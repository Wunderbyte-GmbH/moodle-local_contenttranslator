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
 * Generic source for a sub-table of an activity module (book chapters, choice options, ...).
 *
 * Driven by a declarative map entry, see {@see subtable_map}.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class subtable_source extends content_source {
    /**
     * Constructor.
     *
     * @param string $table Sub-table name.
     * @param array $def Map entry: module, parentfield, fields, labelfield, restoremapping.
     * @param int $moduleid Id in the modules table.
     */
    public function __construct(
        /** @var string Table */
        protected string $table,
        /** @var array Definition */
        protected array $def,
        /** @var int Module id */
        protected int $moduleid,
    ) {
    }

    #[\Override]
    public function get_component(): string {
        return 'mod_' . $this->def['module'];
    }

    #[\Override]
    public function get_itemtype(): string {
        return $this->table;
    }

    #[\Override]
    public function get_display_name(): string {
        return get_string('modulename', $this->get_component()) . ' (' . $this->table . ')';
    }

    #[\Override]
    public function get_fields(): array {
        return $this->def['fields'];
    }

    #[\Override]
    public function get_restore_mapping(): ?string {
        return $this->def['restoremapping'] ?? null;
    }

    #[\Override]
    public function get_items_for_course(int $courseid): iterable {
        global $DB;
        $sql = "SELECT s.*, p.name AS parentname, p.course AS parentcourse, cm.id AS cmid, cm.lang AS cmlang
                  FROM {" . $this->table . "} s
                  JOIN {" . $this->def['module'] . "} p ON p.id = s." . $this->def['parentfield'] . "
                  JOIN {course_modules} cm ON cm.instance = p.id AND cm.module = :moduleid
                 WHERE p.course = :courseid AND cm.deletioninprogress = 0
              ORDER BY s.id";
        $rs = $DB->get_recordset_sql($sql, ['moduleid' => $this->moduleid, 'courseid' => $courseid]);
        foreach ($rs as $record) {
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
        $sql = "SELECT s.*, p.name AS parentname, p.course AS parentcourse, cm.id AS cmid, cm.lang AS cmlang
                  FROM {" . $this->table . "} s
                  JOIN {" . $this->def['module'] . "} p ON p.id = s." . $this->def['parentfield'] . "
                  JOIN {course_modules} cm ON cm.instance = p.id AND cm.module = :moduleid
                 WHERE s.id = :id AND cm.deletioninprogress = 0";
        $record = $DB->get_record_sql($sql, ['moduleid' => $this->moduleid, 'id' => $itemid]);
        return $record ? $this->build($record) : null;
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
        $labelfield = $this->def['labelfield'] ?? null;
        $label = $record->parentname;
        if ($labelfield && !empty($record->$labelfield)) {
            $label .= ': ' . shorten_text(strip_tags((string)$record->$labelfield), 60);
        }
        $lang = $record->cmlang ?: ($DB->get_field('course', 'lang', ['id' => $record->parentcourse]) ?: null);
        $editurl = null;
        if (!empty($this->def['editurl'])) {
            $editurl = new moodle_url(str_replace(
                ['{cmid}', '{id}', '{parentid}'],
                [$record->cmid, $record->id, $record->{$this->def['parentfield']}],
                $this->def['editurl']
            ));
        }
        return new source_item(
            itemid: (int)$record->id,
            contextid: context_module::instance($record->cmid)->id,
            courseid: (int)$record->parentcourse,
            label: $label,
            fields: $fields,
            lang: $lang,
            editurl: $editurl,
        );
    }
}
