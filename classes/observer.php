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

use local_contenttranslator\source\registry;

/**
 * Catch-all event observer: keeps the item registry in sync with content changes.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class observer {
    /** @var bool Disable during restore / bulk operations */
    private static bool $enabled = true;

    /**
     * Switch the observer on or off (restore, tests).
     *
     * @param bool $enabled
     */
    public static function set_enabled(bool $enabled): void {
        self::$enabled = $enabled;
    }

    /**
     * Observer callback for every event.
     *
     * @param \core\event\base $event
     */
    public static function catch_all(\core\event\base $event): void {
        global $DB;
        if (!self::$enabled || $event->crud === 'r' || $event->component === 'local_contenttranslator') {
            return;
        }
        if (during_initial_install() || !$DB->get_manager()->table_exists('local_contenttranslator_item')) {
            return;
        }
        $table = (string)$event->objecttable;
        $id = (int)$event->objectid;
        if ($table === '' || $id <= 0) {
            return;
        }
        try {
            $changed = [];
            if ($table === 'course_modules') {
                $modname = $event->other['modulename'] ?? null;
                $instanceid = (int)($event->other['instanceid'] ?? 0);
                if (!$modname || !$instanceid) {
                    $cm = $DB->get_record_sql("SELECT cm.instance, m.name FROM {course_modules} cm
                                                 JOIN {modules} m ON m.id = cm.module WHERE cm.id = :id", ['id' => $id]);
                    if (!$cm) {
                        return;
                    }
                    $modname = $cm->name;
                    $instanceid = (int)$cm->instance;
                }
                $registry = registry::get();
                if (!$registry->has_table($modname)) {
                    return;
                }
                if ($event->crud === 'd') {
                    item_manager::delete_items('mod_' . $modname, $modname, $instanceid);
                    // Sub-table items live in the module context; remove them too.
                    $DB->delete_records_select(
                        'local_contenttranslator_tr',
                        'itemid IN (SELECT id FROM {local_contenttranslator_item} WHERE contextid = :ctx)',
                        ['ctx' => (int)$event->contextid]
                    );
                    $DB->delete_records('local_contenttranslator_item', ['contextid' => (int)$event->contextid]);
                    cache_helper::purge();
                    return;
                }
                $changed = item_manager::sync_by_table($modname, $instanceid);
            } else if ($table === 'course' && $event->crud === 'd') {
                item_manager::delete_course($id);
                return;
            } else if (registry::get()->has_table($table)) {
                $changed = item_manager::sync_by_table($table, $id, $event->crud === 'd');
            } else {
                return;
            }
            if ($changed) {
                queue::queue_items($changed, budget::TRIGGER_ONSAVE, (int)$event->userid);
            }
        } catch (\Throwable $e) {
            debugging('local_contenttranslator observer: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
