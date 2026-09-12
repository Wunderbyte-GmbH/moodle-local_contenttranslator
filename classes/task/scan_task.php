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
use local_contenttranslator\item_manager;
use local_contenttranslator\queue;

/**
 * Scheduled task: incremental scan comparing stored source hashes with current content.
 *
 * Catches changes made without events (imports, restores, direct DB edits, web services).
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scan_task extends \core\task\scheduled_task {
    #[\Override]
    public function get_name(): string {
        return get_string('task:scan', 'local_contenttranslator');
    }

    #[\Override]
    public function execute(): void {
        global $DB;
        $batch = (int)config::get('scanbatch', 20);
        $sql = "SELECT c.id
                  FROM {course} c
             LEFT JOIN {local_contenttranslator_cfg} cfg ON cfg.instancetype = 'course' AND cfg.instanceid = c.id
                 WHERE c.id <> :siteid
              ORDER BY COALESCE(cfg.timescanned, 0) ASC, c.id ASC";
        $courses = $DB->get_records_sql($sql, ['siteid' => SITEID], 0, $batch);
        foreach ($courses as $course) {
            $result = item_manager::sync_course((int)$course->id);
            mtrace("local_contenttranslator: scanned course {$course->id}: {$result['total']} items, "
                . count($result['changed']) . " changed, {$result['deleted']} removed.");
            if (config::is_auto_enabled_for_course((int)$course->id)) {
                $langs = config::get_course_target_langs((int)$course->id);
                $pending = queue::ensure_course_translations((int)$course->id, $langs);
                if ($pending > 0) {
                    foreach ($langs as $lang) {
                        queue::queue_course_lang((int)$course->id, $lang, budget::TRIGGER_BACKLOG, 0, 0);
                    }
                }
            }
        }
        $changed = item_manager::sync_site();
        if ($changed) {
            queue::queue_items($changed, budget::TRIGGER_BACKLOG);
        }
    }
}
