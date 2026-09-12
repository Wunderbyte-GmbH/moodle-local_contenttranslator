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
 * Setup wizard.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_contenttranslator\config;
use local_contenttranslator\engine\engine_manager;
use local_contenttranslator\form\wizard_form;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_contenttranslator_wizard');
require_capability('local/contenttranslator:manage', context_system::instance());

$url = new moodle_url('/local/contenttranslator/wizard.php');
$form = new wizard_form($url->out(false));
if ($form->is_cancelled()) {
    redirect(new moodle_url('/admin/settings.php', ['section' => 'local_contenttranslator']));
}
if ($data = $form->get_data()) {
    set_config('engine', $data->engine, 'local_contenttranslator');
    set_config('price_' . $data->engine, (float)$data->price, 'local_contenttranslator');
    $targetlangs = config::parse_langs(implode(',', (array)($data->targetlangs ?? [])));
    set_config('targetlangs', implode(',', $targetlangs), 'local_contenttranslator');
    set_config('budgetchars', (int)$data->budgetchars, 'local_contenttranslator');
    set_config('enableauto', (int)$data->enableauto, 'local_contenttranslator');

    $serviceuserid = (int)$data->serviceuserid;
    if (!empty($data->createserviceuser) && !$serviceuserid) {
        $existing = $DB->get_record('user', ['username' => 'contenttranslator', 'mnethostid' => $CFG->mnet_localhost_id]);
        if ($existing) {
            $serviceuserid = (int)$existing->id;
        } else {
            $user = new stdClass();
            $user->username = 'contenttranslator';
            $user->auth = 'nologin';
            $user->confirmed = 1;
            $user->mnethostid = $CFG->mnet_localhost_id;
            $user->firstname = get_string('serviceuserfirstname', 'local_contenttranslator');
            $user->lastname = get_string('serviceuserlastname', 'local_contenttranslator');
            $user->email = 'contenttranslator@' . parse_url($CFG->wwwroot, PHP_URL_HOST);
            $user->lang = $CFG->lang;
            $user->timecreated = time();
            $user->timemodified = time();
            $serviceuserid = (int)user_create_user($user, false, false);
        }
    }
    set_config('serviceuserid', $serviceuserid, 'local_contenttranslator');
    if (
        !empty($data->acceptpolicy) && $serviceuserid && class_exists(\core_ai\manager::class)
        && !\core_ai\manager::get_user_policy_status($serviceuserid)
    ) {
        \core_ai\manager::user_policy_accepted($serviceuserid, context_system::instance()->id);
    }
    \local_contenttranslator\cache_helper::purge();
    \cache::make('local_contenttranslator', 'courseconfig')->purge();
    redirect(
        new moodle_url('/local/contenttranslator/index.php'),
        get_string('wizardsaved', 'local_contenttranslator'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('wizard', 'local_contenttranslator'));
echo html_writer::tag('p', get_string('wizard_desc', 'local_contenttranslator'));
$engine = engine_manager::get_engine((string)config::get('engine', 'core_ai'));
if ($engine && !$engine->is_available()) {
    echo $OUTPUT->notification(
        get_string('check:engineunavailable', 'local_contenttranslator', $engine->get_display_name()),
        'warning'
    );
}
$form->display();
echo $OUTPUT->footer();
