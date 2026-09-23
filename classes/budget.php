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
    /** @var int Rough characters-per-token estimate for display only; the budget itself is enforced in characters. */
    private const CHARS_PER_TOKEN = 4;

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
     * Rough token estimate for a character count. Display only: budgets are stored and enforced in characters,
     * the only unit known before an engine call and enforceable across all engines (including DeepL).
     *
     * @param int $chars
     * @return int
     */
    public static function chars_to_tokens(int $chars): int {
        return (int)round($chars / self::CHARS_PER_TOKEN);
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
        $tokens = number_format(self::chars_to_tokens($chars));
        $text .= ' (≈ ' . $tokens . ' ' . get_string('tokens', 'local_contenttranslator') . ')';

        if (self::get_price($engine) > 0) {
            $text .= ' (≈ ' . number_format(self::estimate($chars, $engine), 2) . ' €)';
        }
        return $text;
    }

    /**
     * Notify admins once per month and budget: at the warning level (setting), at 100 %, and when automatic
     * translation pauses because the next text does not fit into the rest of the budget. 100 % and "paused" say
     * the same thing, so only the first of the two is sent.
     *
     * @param bool $paused True when automatic translation just stopped because the next text would exceed the budget.
     */
    public static function notify_thresholds(bool $paused = false): void {
        $limit = self::get_limit();
        if ($limit <= 0) {
            return;
        }
        $used = self::get_used();
        $percent = (int)floor($used / $limit * 100);
        $warnpercent = self::get_warning_percent();
        $sent = self::get_sent_notifications($limit);
        if ($paused) {
            $level = 'paused';
        } else if ($percent >= 100) {
            $level = '100';
        } else if ($warnpercent > 0 && $percent >= $warnpercent) {
            $level = 'warning';
        } else {
            return;
        }
        if (in_array($level, $sent, true) || ($level !== 'warning' && array_intersect(['100', 'paused'], $sent))) {
            return;
        }
        $sent = array_values(array_unique(array_merge($sent, $level === 'warning' ? ['warning'] : ['warning', $level])));
        set_config(
            'budgetnotified',
            json_encode(['month' => date('Ym'), 'limit' => $limit, 'sent' => $sent]),
            'local_contenttranslator'
        );

        $a = (object)[
            'percent' => $percent,
            'used' => number_format($used),
            'limit' => number_format($limit),
            'usedtokens' => number_format(self::chars_to_tokens($used)),
            'limittokens' => number_format(self::chars_to_tokens($limit)),
        ];
        $key = ['warning' => 'budgetnotification', '100' => 'budgetexhausted', 'paused' => 'budgetpaused'][$level];
        $subject = get_string($key . ':subject', 'local_contenttranslator', $a);
        $body = get_string($key . ':body', 'local_contenttranslator', $a);
        $url = new \moodle_url('/local/contenttranslator/index.php');
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
            $message->contexturlname = get_string('dashboard', 'local_contenttranslator');
            message_send($message);
        }
        \local_contenttranslator\event\budget_threshold_reached::create([
            'context' => \context_system::instance(),
            'other' => ['percent' => $percent, 'used' => $used, 'limit' => $limit],
        ])->trigger();
    }

    /**
     * Percentage of the budget at which admins are warned (setting), 0 when the warning is off.
     *
     * @return int
     */
    public static function get_warning_percent(): int {
        return max(0, min(99, (int)config::get('budgetwarnpercent', 80)));
    }

    /**
     * Whether automatic translation is paused by the budget this month: used up, or the next text did not fit.
     *
     * @return bool
     */
    public static function is_paused(): bool {
        $limit = self::get_limit();
        if ($limit <= 0) {
            return false;
        }
        return self::get_used() >= $limit || in_array('paused', self::get_sent_notifications($limit), true);
    }

    /**
     * Notifications already sent for the current month and budget. Raising the budget starts over.
     *
     * @param int $limit Current budget.
     * @return string[] Sent levels: 'warning', '100', 'paused'.
     */
    private static function get_sent_notifications(int $limit): array {
        $state = json_decode((string)config::get('budgetnotified', '{}'), true) ?: [];
        $month = date('Ym');
        if (!isset($state['month'])) {
            // Format before 2026091800: {"YYYYMM": highest level}.
            $level = (int)($state[$month] ?? 0);
            return $level >= 100 ? ['warning', '100'] : ($level >= 80 ? ['warning'] : []);
        }
        if ($state['month'] !== $month || (int)$state['limit'] !== $limit) {
            return [];
        }
        return $state['sent'] ?? [];
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
