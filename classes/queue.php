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

use local_contenttranslator\task\translate_task;

/**
 * Queues translation work as ad-hoc tasks grouped per course and language.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class queue {
    /**
     * Queue (or debounce) the task for a course and language.
     *
     * @param int $courseid
     * @param string $lang
     * @param string $trigger
     * @param int $userid User who caused the work (bulk: the admin who clicked).
     * @param int|null $delay Seconds until the task runs; null = configured debounce for on-save, now otherwise.
     */
    public static function queue_course_lang(
        int $courseid,
        string $lang,
        string $trigger,
        int $userid = 0,
        ?int $delay = null
    ): void {
        if ($delay === null) {
            $delay = $trigger === budget::TRIGGER_ONSAVE ? (int)config::get('debounce', 120) : 0;
        }
        $task = new translate_task();
        $task->set_custom_data(['courseid' => $courseid, 'lang' => $lang, 'trigger' => $trigger]);
        if ($userid) {
            $task->set_userid($userid);
        }
        $task->set_next_run_time(time() + max(1, $delay));
        \core\task\manager::reschedule_or_queue_adhoc_task($task);
    }

    /**
     * Changed items (event or scan path): create queued translation rows and queue tasks when automatic
     * translation is enabled for the course.
     *
     * @param int[] $itemids
     * @param string $trigger
     * @param int $userid
     */
    public static function queue_items(array $itemids, string $trigger, int $userid = 0): void {
        if (!$itemids) {
            return;
        }
        $pairs = [];
        foreach ($itemids as $itemid) {
            $item = item_manager::get_item((int)$itemid);
            if (!$item) {
                continue;
            }
            $courseid = (int)$item->courseid;
            if (!config::is_auto_enabled_for_course($courseid)) {
                continue;
            }
            $langs = config::get_course_target_langs($courseid);
            item_manager::ensure_translations($item, $langs);
            foreach ($langs as $lang) {
                if ($lang !== $item->sourcelang) {
                    $pairs[$courseid . '|' . $lang] = [$courseid, $lang];
                }
            }
        }
        foreach ($pairs as [$courseid, $lang]) {
            self::queue_course_lang($courseid, $lang, $trigger, $userid);
        }
    }

    /**
     * Make sure every item of a course has a translation row for each language.
     *
     * @param int $courseid
     * @param string[] $langs
     * @param bool $retryfailed Put failed translations back into the queue.
     * @return int Number of translations waiting afterwards.
     */
    public static function ensure_course_translations(int $courseid, array $langs, bool $retryfailed = false): int {
        global $DB;
        $items = $DB->get_records('local_contenttranslator_item', ['courseid' => $courseid, 'excluded' => 0]);
        foreach ($items as $item) {
            item_manager::ensure_translations($item, $langs);
        }
        if ($retryfailed) {
            [$insql, $params] = $DB->get_in_or_equal($langs, SQL_PARAMS_NAMED);
            $params['courseid'] = $courseid;
            $params['failed'] = translation_manager::STATUS_FAILED;
            $params['queued'] = translation_manager::STATUS_QUEUED;
            $DB->execute("UPDATE {local_contenttranslator_tr} SET status = :queued, failreason = NULL
                           WHERE status = :failed AND targetlang $insql
                             AND itemid IN (SELECT id FROM {local_contenttranslator_item} WHERE courseid = :courseid)", $params);
        }
        $pending = 0;
        foreach ($langs as $lang) {
            $pending += count(item_manager::get_pending($courseid, $lang));
        }
        return $pending;
    }

    /**
     * Bulk: scan a course, create translation rows and queue one task per language.
     *
     * @param int $courseid
     * @param int $userid
     * @param string[]|null $langs Null = configured target languages of the course.
     * @return int Number of translations queued.
     */
    public static function queue_course(int $courseid, int $userid, ?array $langs = null): int {
        $langs = $langs ?? config::get_course_target_langs($courseid);
        item_manager::sync_course($courseid);
        $pending = self::ensure_course_translations($courseid, $langs, true);
        foreach ($langs as $lang) {
            self::queue_course_lang($courseid, $lang, budget::TRIGGER_BULK, $userid, 0);
        }
        event\bulk_started::create([
            'context' => $courseid > 0 && $courseid != SITEID ? \context_course::instance($courseid) : \context_system::instance(),
            'other' => ['courseid' => $courseid, 'langs' => implode(',', $langs), 'pending' => $pending],
        ])->trigger();
        return $pending;
    }
}
