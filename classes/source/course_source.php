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
 * Course full name and summary.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_source extends content_source {
    #[\Override]
    public function get_component(): string {
        return 'core_course';
    }

    #[\Override]
    public function get_itemtype(): string {
        return 'course';
    }

    #[\Override]
    public function get_display_name(): string {
        return get_string('course');
    }

    #[\Override]
    public function get_fields(): array {
        return [
            'fullname' => ['string' => true, 'format' => FORMAT_PLAIN],
            'summary' => ['formatfield' => 'summaryformat'],
        ];
    }

    #[\Override]
    public function get_items_for_course(int $courseid): iterable {
        $item = $this->get_item($courseid);
        return $item ? [$item] : [];
    }

    #[\Override]
    public function get_item(int $itemid): ?source_item {
        global $DB, $SITE;
        if ($itemid == $SITE->id) {
            // The front page is site level content (M2).
            return null;
        }
        $course = $DB->get_record('course', ['id' => $itemid]);
        if (!$course) {
            return null;
        }
        return new source_item(
            itemid: (int)$course->id,
            contextid: context_course::instance($course->id)->id,
            courseid: (int)$course->id,
            label: get_string('course') . ': ' . $course->fullname,
            fields: $this->extract_fields($course),
            lang: $course->lang ?: null,
            editurl: new moodle_url('/course/edit.php', ['id' => $course->id]),
        );
    }

    #[\Override]
    public function get_edit_url(int $itemid): ?moodle_url {
        return new moodle_url('/course/edit.php', ['id' => $itemid]);
    }
}
