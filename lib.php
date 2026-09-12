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

/**
 * Library callbacks for local_contenttranslator.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Add the "Translations" entry to the course navigation (secondary navigation "More" menu in Boost).
 *
 * @param navigation_node $navigation
 * @param stdClass $course
 * @param context $context
 */
function local_contenttranslator_extend_navigation_course(navigation_node $navigation, stdClass $course, context $context): void {
    if (!has_capability('local/contenttranslator:viewreports', $context)) {
        return;
    }
    $url = new moodle_url('/local/contenttranslator/index.php', ['courseid' => $course->id]);
    $navigation->add(
        get_string('translations', 'local_contenttranslator'),
        $url,
        navigation_node::TYPE_SETTING,
        null,
        'local_contenttranslator',
        new pix_icon('i/language', '')
    );
}

/**
 * Status checks shown in Site administration > Reports > System status.
 *
 * @return \core\check\check[]
 */
function local_contenttranslator_status_checks(): array {
    return [new \local_contenttranslator\check\setup_check()];
}
