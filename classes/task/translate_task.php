<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_contenttranslator\task;

use local_contenttranslator\budget;
use local_contenttranslator\config;
use local_contenttranslator\engine\budget_exceeded_exception;
use local_contenttranslator\engine\engine_manager;
use local_contenttranslator\engine\rate_limited_exception;
use local_contenttranslator\item_manager;
use local_contenttranslator\queue;
use local_contenttranslator\translator;

/**
 * Ad-hoc task: translate the pending items of one course into one language.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class translate_task extends \core\task\adhoc_task {
    #[\Override]
    public function get_name(): string {
        return get_string('task:translate', 'local_contenttranslator');
    }

    #[\Override]
    public function execute(): void {
        $data = $this->get_custom_data();
        $courseid = (int)($data->courseid ?? 0);
        $lang = (string)($data->lang ?? '');
        $trigger = (string)($data->trigger ?? budget::TRIGGER_BACKLOG);
        if ($lang === '') {
            return;
        }
        if (!budget::is_automation_enabled()) {
            mtrace('local_contenttranslator: automatic translation is off (no monthly budget set), nothing done.');
            return;
        }
        if (
            in_array($trigger, [budget::TRIGGER_ONSAVE, budget::TRIGGER_BACKLOG], true)
            && !config::is_auto_enabled_for_course($courseid)
        ) {
            mtrace("local_contenttranslator: automatic translation is disabled for course $courseid.");
            return;
        }
        // Automatic jobs always run as the translation service user, never as the user who triggered them (ENG-05).
        $userid = (int)config::get('serviceuserid', 0);
        $problem = self::get_service_user_problem($courseid, $lang, $userid);
        if ($problem !== null) {
            mtrace('local_contenttranslator: automatic translation paused: ' . $problem);
            self::notify_paused($problem);
            return;
        }
        $limit = (int)config::get('backloglimit', 200);
        $pending = item_manager::get_pending($courseid, $lang, $limit);
        mtrace("local_contenttranslator: course $courseid, language $lang, " . count($pending) . ' pending.');
        $done = 0;
        foreach ($pending as $translation) {
            $item = item_manager::get_item((int)$translation->itemid);
            if (!$item) {
                continue;
            }
            try {
                translator::translate_item($item, $lang, $trigger, $userid);
                $done++;
            } catch (budget_exceeded_exception $e) {
                mtrace('local_contenttranslator: monthly budget exhausted, pausing.');
                budget::notify_thresholds(true);
                return;
            } catch (rate_limited_exception $e) {
                mtrace('local_contenttranslator: rate limited, will retry later.');
                throw $e;
            }
        }
        mtrace("local_contenttranslator: $done translated.");
        if (count($pending) >= $limit && $limit > 0) {
            queue::queue_course_lang($courseid, $lang, $trigger, (int)$this->get_userid(), 60);
        }
    }

    /**
     * Why automatic translation cannot run for a course and language, or null when it can.
     *
     * Blocked when none of the engines configured for the language can be used by the service user (no service
     * user, AI policy not accepted, provider unavailable). Local engines such as the pseudo engine need no service user.
     *
     * @param int $courseid
     * @param string $lang
     * @param int $userid Service user id, 0 = none.
     * @return string|null
     */
    public static function get_service_user_problem(int $courseid, string $lang, int $userid): ?string {
        global $CFG, $DB;
        if ($userid && !$DB->record_exists('user', ['id' => $userid, 'deleted' => 0])) {
            $userid = 0;
        }
        $externalallowed = $courseid > 0 && $courseid != SITEID ? config::get_effective($courseid)->externalallowed : true;
        $engines = engine_manager::get_engines_for_lang((string)$CFG->lang, $lang, $externalallowed);
        if (!$engines) {
            // Nothing is sent anywhere; the pipeline reports "no engine" per item.
            return null;
        }
        $available = false;
        $unavailable = null;
        foreach ($engines as $engine) {
            if (!$engine->is_external()) {
                return null;
            }
            if (!$engine->is_available()) {
                // Provider switched off or "Generate text" disabled: not a matter of the service user.
                $unavailable = $unavailable ?? $engine;
                continue;
            }
            $available = true;
            if ($userid && $engine->is_available_for_user($userid)) {
                return null;
            }
        }
        if (!$available) {
            return get_string('check:engineunavailable', 'local_contenttranslator', $unavailable->get_display_name());
        }
        return get_string($userid ? 'check:serviceuserpolicy' : 'check:noserviceuser', 'local_contenttranslator');
    }

    /**
     * Tell the site admins, at most once per day, that automatic translation is paused.
     *
     * @param string $reason
     */
    private static function notify_paused(string $reason): void {
        $today = date('Ymd');
        if (config::get('automationpausednotified', '') === $today) {
            return;
        }
        set_config('automationpausednotified', $today, 'local_contenttranslator');
        $url = new \moodle_url('/admin/settings.php', ['section' => 'local_contenttranslator']);
        $subject = get_string('automationpaused:subject', 'local_contenttranslator');
        $body = get_string(
            'automationpaused:body',
            'local_contenttranslator',
            (object)['reason' => $reason, 'url' => $url->out(false)]
        );
        foreach (get_admins() as $admin) {
            $message = new \core\message\message();
            $message->component = 'local_contenttranslator';
            $message->courseid = SITEID;
            $message->name = 'budget';
            $message->userfrom = \core_user::get_noreply_user();
            $message->userto = $admin;
            $message->subject = $subject;
            $message->fullmessage = $body;
            $message->fullmessageformat = FORMAT_PLAIN;
            $message->fullmessagehtml = text_to_html($body);
            $message->smallmessage = $subject;
            $message->notification = 1;
            $message->contexturl = $url->out(false);
            $message->contexturlname = get_string('pluginname', 'local_contenttranslator');
            message_send($message);
        }
    }
}
