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
 * Scheduled task: works through untranslated and stale items within the configured time window.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backlog_task extends \core\task\scheduled_task {
    #[\Override]
    public function get_name(): string {
        return get_string('task:backlog', 'local_contenttranslator');
    }

    /**
     * Whether now is inside the configured window (start == end means always).
     *
     * @param int|null $time
     * @return bool
     */
    public static function in_window(?int $time = null): bool {
        $start = (int)config::get('backlogstart', 0);
        $end = (int)config::get('backlogend', 0);
        if ($start === $end) {
            return true;
        }
        $hour = (int)date('G', $time ?? time());
        if ($start < $end) {
            return $hour >= $start && $hour < $end;
        }
        return $hour >= $start || $hour < $end;
    }

    #[\Override]
    public function execute(): void {
        global $DB;
        if (!budget::is_automation_enabled()) {
            mtrace('local_contenttranslator: automatic translation is off (no monthly budget set).');
            return;
        }
        if (!self::in_window()) {
            mtrace('local_contenttranslator: outside the backlog time window.');
            return;
        }
        $pairs = item_manager::get_pending_pairs();
        if (!$pairs) {
            return;
        }
        // Priority: visible courses first, then courses starting soonest.
        $courseids = array_unique(array_map(fn($p) => (int)$p->courseid, $pairs));
        $courses = $DB->get_records_list('course', 'id', $courseids, '', 'id, visible, startdate');
        usort($pairs, function ($a, $b) use ($courses) {
            $ca = $courses[$a->courseid] ?? null;
            $cb = $courses[$b->courseid] ?? null;
            $va = $ca ? (int)$ca->visible : 1;
            $vb = $cb ? (int)$cb->visible : 1;
            if ($va !== $vb) {
                return $vb <=> $va;
            }
            return ($ca->startdate ?? 0) <=> ($cb->startdate ?? 0);
        });
        $queued = 0;
        foreach ($pairs as $pair) {
            $courseid = (int)$pair->courseid;
            if (!config::is_auto_enabled_for_course($courseid)) {
                continue;
            }
            if (!in_array($pair->targetlang, config::get_course_target_langs($courseid), true)) {
                continue;
            }
            queue::queue_course_lang($courseid, $pair->targetlang, budget::TRIGGER_BACKLOG, 0, $queued * 5);
            $queued++;
        }
        mtrace("local_contenttranslator: queued $queued course/language jobs.");
    }
}
