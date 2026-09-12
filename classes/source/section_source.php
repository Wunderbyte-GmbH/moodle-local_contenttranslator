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

use context_course;
use moodle_url;

/**
 * Course section names and summaries.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class section_source extends content_source {
    #[\Override]
    public function get_component(): string {
        return 'core_course';
    }

    #[\Override]
    public function get_itemtype(): string {
        return 'course_sections';
    }

    #[\Override]
    public function get_display_name(): string {
        return get_string('section');
    }

    #[\Override]
    public function get_fields(): array {
        return [
            'name' => ['string' => true, 'format' => FORMAT_PLAIN],
            'summary' => ['formatfield' => 'summaryformat'],
        ];
    }

    #[\Override]
    public function get_items_for_course(int $courseid): iterable {
        global $DB;
        $sections = $DB->get_records('course_sections', ['course' => $courseid], 'section ASC');
        foreach ($sections as $section) {
            $item = $this->build($section);
            if ($item) {
                yield $item;
            }
        }
    }

    #[\Override]
    public function get_item(int $itemid): ?source_item {
        global $DB;
        $section = $DB->get_record('course_sections', ['id' => $itemid]);
        return $section ? $this->build($section) : null;
    }

    /**
     * Build the item.
     *
     * @param \stdClass $section
     * @return source_item|null
     */
    private function build(\stdClass $section): ?source_item {
        global $DB, $SITE;
        if ($section->course == $SITE->id) {
            return null;
        }
        $fields = $this->extract_fields($section);
        if (!$fields) {
            return null;
        }
        $courselang = $DB->get_field('course', 'lang', ['id' => $section->course]);
        $label = get_string('section') . ' ' . $section->section;
        if ($section->name !== null && $section->name !== '') {
            $label .= ': ' . $section->name;
        }
        return new source_item(
            itemid: (int)$section->id,
            contextid: context_course::instance($section->course)->id,
            courseid: (int)$section->course,
            label: $label,
            fields: $fields,
            lang: $courselang ?: null,
            editurl: new moodle_url('/course/editsection.php', ['id' => $section->id]),
        );
    }

    #[\Override]
    public function get_edit_url(int $itemid): ?moodle_url {
        return new moodle_url('/course/editsection.php', ['id' => $itemid]);
    }

    #[\Override]
    public function get_restore_mapping(): ?string {
        return 'course_section';
    }
}
