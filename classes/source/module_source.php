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

namespace local_contenttranslator\source;

use context_module;
use moodle_url;

/**
 * Generic source for the main table of an activity module.
 *
 * Covers name + intro of every installed module and auto-discovers further text columns
 * (e.g. page.content, assign.activity, workshop.instructauthors) using a skip list.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class module_source extends content_source {
    /** @var array|null Cached field definitions */
    private ?array $fields = null;

    /**
     * Constructor.
     *
     * @param string $modname Module name, e.g. "page".
     * @param int $moduleid Id in the modules table.
     */
    public function __construct(
        /** @var string Module name */
        protected string $modname,
        /** @var int Module id */
        protected int $moduleid,
    ) {
    }

    #[\Override]
    public function get_component(): string {
        return 'mod_' . $this->modname;
    }

    #[\Override]
    public function get_itemtype(): string {
        return $this->modname;
    }

    #[\Override]
    public function get_fields(): array {
        global $DB;
        if ($this->fields !== null) {
            return $this->fields;
        }
        $columns = $DB->get_columns($this->modname);
        $fields = [];
        if (isset($columns['name'])) {
            $fields['name'] = ['string' => true, 'format' => FORMAT_PLAIN];
        }
        if (isset($columns['intro'])) {
            $fields['intro'] = ['formatfield' => isset($columns['introformat']) ? 'introformat' : null];
        }
        if (get_config('local_contenttranslator', 'discovercolumns') ?? 1) {
            foreach ($columns as $name => $column) {
                if (isset($fields[$name]) || $column->meta_type !== 'X') {
                    continue;
                }
                if (subtable_map::is_skipped_column($this->modname, $name)) {
                    continue;
                }
                $fields[$name] = ['formatfield' => isset($columns[$name . 'format']) ? $name . 'format' : null];
            }
        }
        $this->fields = $fields;
        return $fields;
    }

    #[\Override]
    public function get_items_for_course(int $courseid): iterable {
        global $DB;
        $sql = "SELECT m.*, cm.id AS cmid, cm.lang AS cmlang, cm.deletioninprogress
                  FROM {" . $this->modname . "} m
                  JOIN {course_modules} cm ON cm.instance = m.id AND cm.module = :moduleid
                 WHERE m.course = :courseid
              ORDER BY m.id";
        $rs = $DB->get_recordset_sql($sql, ['moduleid' => $this->moduleid, 'courseid' => $courseid]);
        foreach ($rs as $record) {
            if ($record->deletioninprogress) {
                continue;
            }
            $item = $this->build($record);
            if ($item) {
                yield $item;
            }
        }
        $rs->close();
    }

    #[\Override]
    public function get_item(int $itemid): ?source_item {
        global $DB;
        $sql = "SELECT m.*, cm.id AS cmid, cm.lang AS cmlang, cm.deletioninprogress
                  FROM {" . $this->modname . "} m
                  JOIN {course_modules} cm ON cm.instance = m.id AND cm.module = :moduleid
                 WHERE m.id = :id";
        $record = $DB->get_record_sql($sql, ['moduleid' => $this->moduleid, 'id' => $itemid]);
        if (!$record || $record->deletioninprogress) {
            return null;
        }
        return $this->build($record);
    }

    /**
     * Build the item.
     *
     * @param \stdClass $record
     * @return source_item|null
     */
    private function build(\stdClass $record): ?source_item {
        global $DB;
        $fields = $this->extract_fields($record);
        if (!$fields) {
            return null;
        }
        $lang = $record->cmlang ?: ($DB->get_field('course', 'lang', ['id' => $record->course]) ?: null);
        return new source_item(
            itemid: (int)$record->id,
            contextid: context_module::instance($record->cmid)->id,
            courseid: (int)$record->course,
            label: get_string('modulename', $this->get_component()) . ': ' . ($record->name ?? $record->id),
            fields: $fields,
            lang: $lang,
            editurl: new moodle_url('/course/modedit.php', ['update' => $record->cmid]),
        );
    }

    #[\Override]
    public function get_edit_url(int $itemid): ?moodle_url {
        $cmid = get_coursemodule_from_instance($this->modname, $itemid, 0, false, IGNORE_MISSING)->id ?? null;
        return $cmid ? new moodle_url('/course/modedit.php', ['update' => $cmid]) : null;
    }
}
