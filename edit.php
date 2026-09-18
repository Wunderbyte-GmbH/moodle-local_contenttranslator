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
 * Side-by-side translation editor.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_contenttranslator\api;
use local_contenttranslator\config;
use local_contenttranslator\diff;
use local_contenttranslator\external\set_status;
use local_contenttranslator\form\translation_form;
use local_contenttranslator\item_manager;
use local_contenttranslator\translation_manager;

require_once(__DIR__ . '/../../config.php');

$id = optional_param('id', 0, PARAM_INT);
$itemid = optional_param('itemid', 0, PARAM_INT);
$lang = optional_param('lang', '', PARAM_ALPHANUMEXT);
$action = optional_param('action', '', PARAM_ALPHA);
$returnto = optional_param('returnto', '', PARAM_LOCALURL);

if ($id) {
    $translation = translation_manager::get($id);
    $item = item_manager::get_item((int)$translation->itemid);
} else {
    $item = item_manager::get_item($itemid);
    if (!$item || !config::parse_langs($lang)) {
        throw new moodle_exception('error:itemnotfound', 'local_contenttranslator');
    }
    $translation = translation_manager::ensure((int)$item->id, $lang);
}
if (!$item) {
    throw new moodle_exception('error:itemnotfound', 'local_contenttranslator');
}
$lang = $translation->targetlang;
$context = context::instance_by_id($item->contextid);
$course = $item->courseid ? get_course((int)$item->courseid) : null;
if ($context->contextlevel == CONTEXT_MODULE) {
    $cm = get_coursemodule_from_id('', $context->instanceid, 0, false, MUST_EXIST);
    require_login($course, false, $cm);
} else {
    require_login($course);
}
require_capability('local/contenttranslator:translate', $context);
$canreview = has_capability('local/contenttranslator:review', $context);

$url = new moodle_url('/local/contenttranslator/edit.php', ['id' => $translation->id, 'returnto' => $returnto]);
$dashboard = $returnto
    ? new moodle_url($returnto)
    : new moodle_url('/local/contenttranslator/index.php', ['courseid' => $item->courseid]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_title(get_string('editor', 'local_contenttranslator'));
$PAGE->set_heading($course ? $course->fullname : get_string('pluginname', 'local_contenttranslator'));
$PAGE->set_pagelayout($course ? 'incourse' : 'admin');
$PAGE->navbar->add(get_string('dashboard', 'local_contenttranslator'), $dashboard);
$PAGE->navbar->add(get_string('editor', 'local_contenttranslator'));

// Link actions.
if ($action !== '' && confirm_sesskey()) {
    if ($action === 'translatenow') {
        $translation = api::translate_now((int)$item->id, $lang, (int)$USER->id);
        // A failure is shown by the page itself (see below), so only success gets a message.
        $failed = $translation->status === translation_manager::STATUS_FAILED;
        redirect(
            $url,
            $failed ? '' : get_string('translated', 'local_contenttranslator'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    if (in_array($action, set_status::ACTIONS, true)) {
        set_status::apply($translation, $action, (int)$USER->id, optional_param('historyid', 0, PARAM_INT), $context);
        if ($action === 'delete') {
            redirect($dashboard, get_string('deleted', 'local_contenttranslator'), null, \core\output\notification::NOTIFY_SUCCESS);
        }
        redirect($url, get_string('actiondone', 'local_contenttranslator'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

/**
 * Next translation needing attention in the same course and language.
 *
 * @param stdClass $item
 * @param stdClass $translation
 * @return stdClass|null
 */
function local_contenttranslator_find_next(stdClass $item, stdClass $translation): ?stdClass {
    global $DB;
    $sql = "SELECT t.id
              FROM {local_contenttranslator_tr} t
              JOIN {local_contenttranslator_item} i ON i.id = t.itemid
             WHERE i.courseid = :courseid AND t.targetlang = :lang AND t.id <> :id AND i.excluded = 0
               AND (t.status IN (:machine, :stale, :failed) OR t.suggestion IS NOT NULL)
          ORDER BY CASE WHEN t.id > :id2 THEN 0 ELSE 1 END, t.id";
    $next = $DB->get_records_sql($sql, [
        'courseid' => $item->courseid, 'lang' => $translation->targetlang, 'id' => $translation->id, 'id2' => $translation->id,
        'machine' => translation_manager::STATUS_MACHINE, 'stale' => translation_manager::STATUS_STALE,
        'failed' => translation_manager::STATUS_FAILED,
    ], 0, 1);
    return $next ? reset($next) : null;
}

$form = new translation_form($url->out(false), [
    'item' => $item, 'translation' => $translation, 'canreview' => $canreview, 'context' => $context,
]);
$form->set_data(['returnto' => $returnto]);
if ($form->is_cancelled()) {
    redirect($dashboard);
}
if ($data = $form->get_data()) {
    if ((int)$data->timemodified !== (int)$translation->timemodified && $translation->timemodified > $data->timemodified) {
        redirect(
            $url,
            get_string('error:concurrentedit', 'local_contenttranslator'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
    if (isset($data->texteditor)) {
        $text = clean_text($data->texteditor['text'], FORMAT_HTML);
        $format = FORMAT_HTML;
    } else {
        $text = $item->isstring ? strip_tags($data->text) : $data->text;
        $format = $item->isstring ? FORMAT_PLAIN : (int)$item->sourceformat;
    }
    $review = !empty($data->savereview) && $canreview;
    $translation = translation_manager::save_human($translation, $text, $format, (int)$USER->id, $review);
    if (!empty($data->savenext)) {
        $next = local_contenttranslator_find_next($item, $translation);
        if ($next) {
            redirect(
                new moodle_url('/local/contenttranslator/edit.php', ['id' => $next->id, 'returnto' => $returnto]),
                get_string('saved', 'local_contenttranslator'),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
        }
        redirect($dashboard, get_string('nomoreitems', 'local_contenttranslator'), null, \core\output\notification::NOTIFY_INFO);
    }
    redirect($url, get_string('saved', 'local_contenttranslator'), null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(s($item->label) . ' · ' . s($item->field) . ' → ' . config::lang_name($lang));

// Status line and actions.
$statuslabel = translation_manager::status_label($translation->status);
if ($translation->origin === translation_manager::ORIGIN_HUMAN && $translation->status === translation_manager::STATUS_MACHINE) {
    $statuslabel = get_string('status:edited', 'local_contenttranslator');
}
$badges = html_writer::span($statuslabel, translation_manager::badge_class($translation));
if ($translation->locked) {
    $badges .= ' ' . html_writer::span(get_string('status:locked', 'local_contenttranslator'), 'badge bg-dark text-white');
}
$badges .= ' ' . html_writer::span(
    get_string('origin:' . $translation->origin, 'local_contenttranslator'),
    'badge bg-light text-dark border'
);
if ($translation->engine) {
    $badges .= ' ' . html_writer::span(
        s($translation->engine . ($translation->model ? ' / ' . $translation->model : '')),
        'badge bg-light text-dark border'
    );
}
$actions = [];
$link = function (string $action, string $label, string $class = 'btn btn-outline-secondary btn-sm', array $extra = []) use ($url) {
    $actionurl = new moodle_url($url, ['action' => $action, 'sesskey' => sesskey()] + $extra);
    return html_writer::link($actionurl, $label, ['class' => $class]);
};
$actions[] = $link('translatenow', get_string('translatenow', 'local_contenttranslator'), 'btn btn-primary btn-sm');
if ($canreview) {
    if (!empty($translation->text) && $translation->status !== translation_manager::STATUS_REVIEWED) {
        $actions[] = $link('review', get_string('markreviewed', 'local_contenttranslator'), 'btn btn-success btn-sm');
    }
    $actions[] = $translation->locked
        ? $link('unlock', get_string('unlock', 'local_contenttranslator'))
        : $link('lock', get_string('lock', 'local_contenttranslator'));
    if (!empty($translation->text)) {
        $actions[] = $link('delete', get_string('delete'), 'btn btn-outline-danger btn-sm');
    }
}
echo html_writer::div($badges, 'mb-2');
echo html_writer::div(implode(' ', $actions), 'mb-3');
if ($translation->status === translation_manager::STATUS_FAILED && $translation->failreason) {
    echo $OUTPUT->notification(get_string('translatefailed', 'local_contenttranslator', $translation->failreason), 'error');
}

// Stale / suggestion panel.
if ($translation->status === translation_manager::STATUS_STALE || $translation->suggestion !== null) {
    echo html_writer::start_div('card mb-3 border-warning');
    echo html_writer::div(get_string('sourcechanged', 'local_contenttranslator'), 'card-header bg-warning');
    echo html_writer::start_div('card-body');
    if ($translation->sourcesnapshot !== null && $translation->sourcesnapshot !== $item->sourcetext) {
        $rendered = diff::render((string)$translation->sourcesnapshot, (string)$item->sourcetext, (int)$item->sourceformat);
        echo html_writer::tag('h5', get_string('sourcediff', 'local_contenttranslator'));
        echo html_writer::div($rendered ?? get_string('difftoolong', 'local_contenttranslator'), 'ct-diff border rounded p-2 mb-3');
    }
    if ($translation->suggestion !== null) {
        echo html_writer::tag('h5', get_string('suggestion', 'local_contenttranslator'));
        echo html_writer::div($item->isstring ? s($translation->suggestion) : format_text(
            $translation->suggestion,
            FORMAT_HTML,
            ['context' => $context, 'filter' => false]
        ), 'border rounded p-2 mb-2 bg-light');
        if ($canreview) {
            echo $link(
                'acceptsuggestion',
                get_string('acceptsuggestion', 'local_contenttranslator'),
                'btn btn-success btn-sm'
            ) . ' ';
        }
    }
    if ($canreview && !empty($translation->text)) {
        echo $link('keepprevious', get_string('keepprevious', 'local_contenttranslator'), 'btn btn-outline-secondary btn-sm');
    }
    echo html_writer::end_div();
    echo html_writer::end_div();
}

// Side by side.
echo html_writer::start_div('row');
echo html_writer::start_div('col-lg-6');
echo html_writer::tag('h4', get_string('source', 'local_contenttranslator') . ' (' . config::lang_name($item->sourcelang) . ')');
$source = \local_contenttranslator\source\registry::get()->get_source($item->component, $item->itemtype);
$editurl = $source ? $source->get_edit_url((int)$item->itemid) : null;
if ($editurl) {
    $editlink = html_writer::link($editurl, get_string('editsource', 'local_contenttranslator'), ['target' => '_blank']);
    echo html_writer::div($editlink, 'mb-2 small');
}
$sourcehtml = $item->isstring ? s($item->sourcetext) : format_text(
    $item->sourcetext,
    (int)$item->sourceformat,
    ['context' => $context, 'filter' => false, 'noclean' => false]
);
echo html_writer::div($sourcehtml, 'ct-source border rounded p-3 mb-2 bg-white', ['lang' => $item->sourcelang]);
if (!$item->isstring) {
    echo html_writer::tag('details', html_writer::tag('summary', get_string('rawsource', 'local_contenttranslator'))
        . html_writer::tag('pre', s($item->sourcetext), ['class' => 'small border rounded p-2 bg-light']), ['class' => 'mb-2']);
}
echo html_writer::div(
    number_format((int)$item->chars) . ' ' . get_string('characters', 'local_contenttranslator'),
    'small text-muted'
);
echo html_writer::end_div();
echo html_writer::start_div('col-lg-6');
echo html_writer::tag('h4', get_string('translation', 'local_contenttranslator') . ' (' . config::lang_name($lang) . ')');
$form->display();
echo html_writer::end_div();
echo html_writer::end_div();

// History.
$history = translation_manager::get_history((int)$translation->id);
if ($history) {
    echo html_writer::tag('h4', get_string('history', 'local_contenttranslator'), ['class' => 'mt-4']);
    $table = new html_table();
    $table->head = [get_string('date'), get_string('user'), get_string('reason', 'local_contenttranslator'),
        get_string('status', 'local_contenttranslator'), get_string('text', 'local_contenttranslator'), ''];
    foreach ($history as $entry) {
        $user = $entry->userid ? core_user::get_user($entry->userid) : null;
        $table->data[] = [
            userdate($entry->timecreated),
            $user ? fullname($user) : get_string('system', 'local_contenttranslator'),
            get_string('reason:' . $entry->reason, 'local_contenttranslator'),
            translation_manager::status_label($entry->status),
            s(shorten_text(\local_contenttranslator\normaliser::normalise((string)$entry->text), 160)),
            $link(
                'rollback',
                get_string('rollback', 'local_contenttranslator'),
                'btn btn-outline-secondary btn-sm',
                ['historyid' => $entry->id]
            ),
        ];
    }
    echo html_writer::table($table);
}
$PAGE->requires->js_amd_inline("
document.addEventListener('keydown', function(e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
        var btn = document.querySelector('input[name=savenext]');
        if (btn) { e.preventDefault(); btn.click(); }
    }
});");
echo $OUTPUT->footer();
