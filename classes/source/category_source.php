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

use context_coursecat;
use moodle_url;

/**
 * Course category names and descriptions (site level content).
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class category_source extends content_source {
    #[\Override]
    public function get_component(): string {
        return 'core_course';
    }

    #[\Override]
    public function get_itemtype(): string {
        return 'course_categories';
    }

    #[\Override]
    public function get_display_name(): string {
        return get_string('category');
    }

    #[\Override]
    public function get_fields(): array {
        return [
            'name' => ['string' => true, 'format' => FORMAT_PLAIN],
            'description' => ['formatfield' => 'descriptionformat'],
        ];
    }

    #[\Override]
    public function get_items_for_course(int $courseid): iterable {
        return [];
    }

    #[\Override]
    public function get_site_items(): iterable {
        global $DB;
        foreach ($DB->get_records('course_categories', [], 'id ASC') as $category) {
            yield $this->build($category);
        }
    }

    #[\Override]
    public function get_item(int $itemid): ?source_item {
        global $DB;
        $category = $DB->get_record('course_categories', ['id' => $itemid]);
        return $category ? $this->build($category) : null;
    }

    /**
     * Build the item.
     *
     * @param \stdClass $category
     * @return source_item
     */
    private function build(\stdClass $category): source_item {
        return new source_item(
            itemid: (int)$category->id,
            contextid: context_coursecat::instance($category->id)->id,
            courseid: 0,
            label: get_string('category') . ': ' . $category->name,
            fields: $this->extract_fields($category),
            lang: null,
            editurl: new moodle_url('/course/editcategory.php', ['id' => $category->id]),
        );
    }

    #[\Override]
    public function get_edit_url(int $itemid): ?moodle_url {
        return new moodle_url('/course/editcategory.php', ['id' => $itemid]);
    }
}
