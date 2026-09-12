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
 * Per course translation settings.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_contenttranslator\config;
use local_contenttranslator\form\course_config_form;

require_once(__DIR__ . '/../../config.php');

$courseid = required_param('courseid', PARAM_INT);
$course = get_course($courseid);
require_login($course);
$context = context_course::instance($courseid);
require_capability('local/contenttranslator:configurecourse', $context);

$url = new moodle_url('/local/contenttranslator/course.php', ['courseid' => $courseid]);
$dashboard = new moodle_url('/local/contenttranslator/index.php', ['courseid' => $courseid]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_title(get_string('coursesettings', 'local_contenttranslator'));
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('incourse');
$PAGE->navbar->add(get_string('dashboard', 'local_contenttranslator'), $dashboard);
$PAGE->navbar->add(get_string('coursesettings', 'local_contenttranslator'));

// Effective values *without* the course override, so the "inherit" labels are right.
$override = config::get_override('course', $courseid);
$effective = config::get_effective($courseid);
$inheritedlabels = $effective->inherited;
if ($override) {
    // Recompute what the parents provide.
    $parenteffective = clone $effective;
    if ($override->targetlangs !== null) {
        $category = core_course_category::get($course->category, IGNORE_MISSING, true);
        $parenteffective->targetlangs = config::get_site_target_langs();
        $inheritedlabels['targetlangs'] = 'site';
        if ($category) {
            foreach (array_merge(array_reverse($category->get_parents()), [$category->id]) as $catid) {
                $cat = config::get_override('category', (int)$catid);
                if ($cat && $cat->targetlangs !== null) {
                    $parenteffective->targetlangs = config::parse_langs($cat->targetlangs);
                    $inheritedlabels['targetlangs'] = core_course_category::get($catid)->get_formatted_name();
                }
            }
        }
        $effective = $parenteffective;
    }
}
$form = new course_config_form($url->out(false), ['effective' => $effective, 'inheritedlabels' => $inheritedlabels]);
$form->set_data([
    'courseid' => $courseid,
    'enabled' => $override->enabled ?? -1,
    'inherittargetlangs' => ($override === null || $override->targetlangs === null) ? 1 : 0,
    'targetlangs' => $override && $override->targetlangs !== null ? config::parse_langs($override->targetlangs) : [],
    'visibility' => $override->visibility ?? '',
    'externalallowed' => $override->externalallowed ?? -1,
]);
if ($form->is_cancelled()) {
    redirect($dashboard);
}
if ($data = $form->get_data()) {
    config::save_override('course', $courseid, [
        'enabled' => (int)$data->enabled,
        'targetlangs' => empty($data->inherittargetlangs)
            ? config::parse_langs(implode(',', (array)($data->targetlangs ?? [])))
            : null,
        'visibility' => $data->visibility ?: null,
        'externalallowed' => (int)$data->externalallowed,
    ]);
    \local_contenttranslator\cache_helper::purge();
    redirect($dashboard, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('coursesettings', 'local_contenttranslator'));
if (!\local_contenttranslator\budget::is_automation_enabled()) {
    echo $OUTPUT->notification(get_string('nobudgetwarning', 'local_contenttranslator'), 'warning');
}
$form->display();
echo $OUTPUT->footer();
