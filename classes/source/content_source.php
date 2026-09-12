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

use moodle_url;

/**
 * Base class for content sources.
 *
 * A content source tells the translator which fields of which table are translatable, how to
 * enumerate them per course, how to read one record and where the record is edited.
 * Register a source through the {@see \local_contenttranslator\hook\register_sources} hook.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class content_source {
    /**
     * Frankenstyle component that owns the content (e.g. mod_page, core_course).
     *
     * @return string
     */
    abstract public function get_component(): string;

    /**
     * Table name of the content records (without prefix). Also used as item type.
     *
     * @return string
     */
    abstract public function get_itemtype(): string;

    /**
     * Translatable fields.
     *
     * Each entry: fieldname => [
     *   'formatfield' => name of the column holding the text format, or null,
     *   'format' => fixed text format when there is no format column (default FORMAT_HTML),
     *   'string' => true for short plain strings rendered through format_string() (names),
     * ]
     *
     * @return array
     */
    abstract public function get_fields(): array;

    /**
     * Enumerate all records of this source that belong to a course.
     *
     * @param int $courseid
     * @return iterable<source_item>
     */
    abstract public function get_items_for_course(int $courseid): iterable;

    /**
     * Read one record by id. Return null when the record no longer exists.
     *
     * @param int $itemid
     * @return source_item|null
     */
    abstract public function get_item(int $itemid): ?source_item;

    /**
     * Records that are not bound to a course (site level). Default: none.
     *
     * @return iterable<source_item>
     */
    public function get_site_items(): iterable {
        return [];
    }

    /**
     * Registry key.
     *
     * @return string
     */
    final public function get_key(): string {
        return $this->get_component() . '/' . $this->get_itemtype();
    }

    /**
     * Human readable name of the content type.
     *
     * @return string
     */
    public function get_display_name(): string {
        $component = $this->get_component();
        if (get_string_manager()->string_exists('pluginname', $component)) {
            return get_string('pluginname', $component);
        }
        return $component;
    }

    /**
     * Whether the content is authored by learners. Such sources are refused in v1 (GDPR).
     *
     * @return bool
     */
    public function is_user_generated(): bool {
        return false;
    }

    /**
     * Tables whose events update items of this source. Defaults to the item type table.
     *
     * @return string[]
     */
    public function get_tables(): array {
        return [$this->get_itemtype()];
    }

    /**
     * Name used by backup/restore mappings for records of this table (e.g. "book_chapter").
     *
     * @return string|null
     */
    public function get_restore_mapping(): ?string {
        return null;
    }

    /**
     * Edit page of a record.
     *
     * @param int $itemid
     * @return moodle_url|null
     */
    public function get_edit_url(int $itemid): ?moodle_url {
        return null;
    }

    /**
     * Helper: build the fields array of a source_item from a DB record.
     *
     * @param \stdClass $record
     * @return array
     */
    protected function extract_fields(\stdClass $record): array {
        $fields = [];
        foreach ($this->get_fields() as $name => $def) {
            if (!property_exists($record, $name)) {
                continue;
            }
            $text = (string)$record->$name;
            if (trim($text) === '') {
                continue;
            }
            $format = $def['format'] ?? FORMAT_HTML;
            if (!empty($def['formatfield']) && property_exists($record, $def['formatfield'])) {
                $format = (int)$record->{$def['formatfield']};
            }
            $fields[$name] = [
                'text' => $text,
                'format' => $format,
                'string' => !empty($def['string']),
            ];
        }
        return $fields;
    }
}
