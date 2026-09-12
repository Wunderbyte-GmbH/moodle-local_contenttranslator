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
        $userid = (int)config::get('serviceuserid', 0);
        if (!$userid) {
            $userid = (int)$this->get_userid();
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
}
