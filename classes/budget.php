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

namespace local_contenttranslator;

/**
 * Monthly site budget in source characters, usage logging and cost estimates.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class budget {
    /** Trigger: content saved */
    public const TRIGGER_ONSAVE = 'onsave';
    /** Trigger: bulk job */
    public const TRIGGER_BULK = 'bulk';
    /** Trigger: backlog task */
    public const TRIGGER_BACKLOG = 'backlog';
    /** Trigger: interactive translate now */
    public const TRIGGER_ONDEMAND = 'ondemand';

    /**
     * Monthly limit in characters, 0 = not set.
     *
     * @return int
     */
    public static function get_limit(): int {
        return (int)config::get('budgetchars', 0);
    }

    /**
     * Automatic and bulk translation only run once a budget exists.
     *
     * @return bool
     */
    public static function is_automation_enabled(): bool {
        return self::get_limit() > 0 && (bool)config::get('enableauto', 1);
    }

    /**
     * Start of the current month.
     *
     * @param int|null $time
     * @return int
     */
    public static function month_start(?int $time = null): int {
        $time = $time ?? time();
        return make_timestamp((int)date('Y', $time), (int)date('n', $time), 1, 0, 0, 0);
    }

    /**
     * Characters sent to engines this month.
     *
     * @return int
     */
    public static function get_used(): int {
        global $DB;
        return (int)$DB->get_field_sql(
            "SELECT COALESCE(SUM(chars), 0) FROM {local_contenttranslator_use} WHERE success = 1 AND timecreated >= :start",
            ['start' => self::month_start()]
        );
    }

    /**
     * May the given number of characters be spent now?
     *
     * @param int $chars
     * @param string $trigger
     * @param int $userid
     * @return bool
     */
    public static function can_spend(int $chars, string $trigger, int $userid): bool {
        $limit = self::get_limit();
        if ($trigger === self::TRIGGER_ONDEMAND) {
            if ($limit <= 0) {
                return true; // On demand works before a budget is set.
            }
            if ($userid && has_capability('local/contenttranslator:exceedbudget', \context_system::instance(), $userid)) {
                return true;
            }
        } else if ($limit <= 0) {
            return false;
        }
        return self::get_used() + $chars <= $limit;
    }

    /**
     * Log an engine call.
     *
     * @param array $data engine, model, sourcelang, targetlang, courseid, contextid, itemid, chars,
     *                    prompttokens, completiontokens, triggertype, userid, success, error
     * @return int id
     */
    public static function log_usage(array $data): int {
        global $DB;
        $record = (object)array_merge([
            'model' => null,
            'courseid' => 0,
            'contextid' => 0,
            'itemid' => 0,
            'chars' => 0,
            'prompttokens' => 0,
            'completiontokens' => 0,
            'userid' => 0,
            'success' => 1,
            'error' => null,
            'timecreated' => time(),
        ], $data);
        $id = $DB->insert_record('local_contenttranslator_use', $record);
        if ($record->success) {
            self::notify_thresholds();
        }
        return $id;
    }

    /**
     * Price per one million characters for an engine (EUR).
     *
     * @param string $engine
     * @return float
     */
    public static function get_price(string $engine): float {
        return (float)config::get('price_' . $engine, 0);
    }

    /**
     * Cost estimate in EUR.
     *
     * @param int $chars
     * @param string $engine
     * @return float
     */
    public static function estimate(int $chars, string $engine): float {
        return $chars / 1000000 * self::get_price($engine);
    }

    /**
     * Formatted "1,234,567 characters (≈ 12.34 €)".
     *
     * @param int $chars
     * @param string $engine
     * @return string
     */
    public static function format(int $chars, string $engine): string {
        $text = number_format($chars) . ' ' . get_string('characters', 'local_contenttranslator');
        if (self::get_price($engine) > 0) {
            $text .= ' (≈ ' . number_format(self::estimate($chars, $engine), 2) . ' €)';
        }
        return $text;
    }

    /**
     * Notify admins once per month at 80 % and 100 %.
     */
    public static function notify_thresholds(): void {
        $limit = self::get_limit();
        if ($limit <= 0) {
            return;
        }
        $used = self::get_used();
        $percent = (int)floor($used / $limit * 100);
        $month = date('Ym');
        $notified = json_decode((string)config::get('budgetnotified', '{}'), true) ?: [];
        $level = $percent >= 100 ? 100 : ($percent >= 80 ? 80 : 0);
        if ($level === 0 || (($notified[$month] ?? 0) >= $level)) {
            return;
        }
        $notified = [$month => $level];
        set_config('budgetnotified', json_encode($notified), 'local_contenttranslator');

        $a = (object)['percent' => $percent, 'used' => number_format($used), 'limit' => number_format($limit)];
        $subject = get_string('budgetnotification:subject', 'local_contenttranslator', $a);
        $body = get_string('budgetnotification:body', 'local_contenttranslator', $a);
        $url = new \moodle_url('/admin/settings.php', ['section' => 'local_contenttranslator']);
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
        \local_contenttranslator\event\budget_threshold_reached::create([
            'context' => \context_system::instance(),
            'other' => ['percent' => $percent, 'used' => $used, 'limit' => $limit],
        ])->trigger();
    }

    /**
     * Usage summary for a month grouped by engine and language.
     *
     * @param int $monthstart
     * @return array
     */
    public static function get_month_summary(int $monthstart): array {
        global $DB;
        $end = strtotime('+1 month', $monthstart);
        return $DB->get_records_sql(
            "SELECT " . $DB->sql_concat('engine', "'-'", 'targetlang') . " AS id, engine, targetlang,
                    SUM(chars) AS chars, SUM(prompttokens) AS prompttokens, SUM(completiontokens) AS completiontokens,
                    COUNT(1) AS calls
               FROM {local_contenttranslator_use}
              WHERE success = 1 AND timecreated >= :start AND timecreated < :end
           GROUP BY engine, targetlang
           ORDER BY engine, targetlang",
            ['start' => $monthstart, 'end' => $end]
        );
    }
}
