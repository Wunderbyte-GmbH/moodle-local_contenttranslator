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
 * Translation dashboard (course or site).
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_contenttranslator\budget;
use local_contenttranslator\config;
use local_contenttranslator\item_manager;
use local_contenttranslator\queue;
use local_contenttranslator\trial\trial_provisioner;
use local_contenttranslator\table\items_table;
use local_contenttranslator\translation_manager;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$courseid = optional_param('courseid', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$filters = [
    'lang' => optional_param('lang', '', PARAM_ALPHANUMEXT),
    'status' => optional_param('status', '', PARAM_ALPHA),
    'search' => optional_param('search', '', PARAM_TEXT),
    'itemtype' => optional_param('itemtype', '', PARAM_ALPHANUMEXT),
];

$pageparams = ['courseid' => $courseid] + array_filter($filters, fn($v) => $v !== '');
$url = new moodle_url('/local/contenttranslator/index.php', $pageparams);
if ($courseid && $courseid != SITEID) {
    $course = $DB->get_record('course', ['id' => $courseid]);
    if (!$course) {
        require_login();
        // Send site wide reporters to the site dashboard, everyone else to their home page.
        $fallback = has_capability('local/contenttranslator:viewreports', context_system::instance())
            ? new moodle_url('/local/contenttranslator/index.php')
            : new moodle_url('/');
        redirect(
            $fallback,
            get_string('error:nosuchcourse', 'local_contenttranslator'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
    require_login($course);
    $context = context_course::instance($courseid);
    $PAGE->set_heading($course->fullname);
    $langs = config::get_course_target_langs($courseid);
} else {
    $courseid = 0;
    $context = context_system::instance();
    $langs = config::get_site_target_langs();
    admin_externalpage_setup('local_contenttranslator_dashboard', '', null, $url);
    $PAGE->set_heading(get_string('pluginname', 'local_contenttranslator'));
}
require_capability('local/contenttranslator:viewreports', $context);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_title(get_string('dashboard', 'local_contenttranslator'));
$PAGE->set_pagelayout($courseid ? 'incourse' : 'admin');

$cantranslate = has_capability('local/contenttranslator:translate', $context);
$canreview = has_capability('local/contenttranslator:review', $context);
$canbulk = has_capability('local/contenttranslator:bulktranslate', $context);

// Actions.
if ($action !== '' && confirm_sesskey()) {
    if ($action === 'scan' && $cantranslate) {
        $result = $courseid
            ? item_manager::sync_course($courseid)
            : ['total' => count(item_manager::sync_site()), 'changed' => [], 'deleted' => 0];
        redirect($url, get_string('scandone', 'local_contenttranslator', (object)[
            'total' => $result['total'], 'changed' => count($result['changed']), 'deleted' => $result['deleted'],
        ]), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    if ($action === 'translate' && $canbulk) {
        if (!budget::is_automation_enabled()) {
            redirect(
                $url,
                get_string('nobudgetwarning', 'local_contenttranslator'),
                null,
                \core\output\notification::NOTIFY_WARNING
            );
        }
        if (optional_param('confirm', 0, PARAM_BOOL)) {
            $pending = queue::queue_course($courseid, (int)$USER->id, $langs);
            redirect(
                $url,
                get_string('bulkqueued', 'local_contenttranslator', $pending),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
        }
        // Pre-flight estimate.
        item_manager::sync_course($courseid);
        echo $OUTPUT->header();
        echo $OUTPUT->heading(get_string('bulktranslate', 'local_contenttranslator'));
        $rows = [];
        $totalchars = 0;
        foreach ($langs as $lang) {
            $estimate = item_manager::estimate_course($courseid, $lang);
            $engine = config::get_engine_for_lang($lang);
            $rows[] = [
                config::lang_name($lang), $estimate['items'], $estimate['tmhits'], budget::format($estimate['chars'], $engine),
            ];
            $totalchars += $estimate['chars'];
        }
        $table = new html_table();
        $table->head = [
            get_string('language'), get_string('items', 'local_contenttranslator'),
            get_string('tmhits', 'local_contenttranslator'), get_string('estimatedcost', 'local_contenttranslator'),
        ];
        $table->data = $rows;
        echo html_writer::table($table);
        $remaining = max(0, budget::get_limit() - budget::get_used());
        echo html_writer::tag('p', get_string('budgetremaining', 'local_contenttranslator', (object)[
            'chars' => number_format($remaining),
            'tokens' => number_format(budget::chars_to_tokens($remaining)),
        ]));
        if ($totalchars > $remaining) {
            echo $OUTPUT->notification(get_string('estimateexceedsbudget', 'local_contenttranslator'), 'warning');
        }
        $confirmurl = new moodle_url($url, ['action' => 'translate', 'confirm' => 1, 'sesskey' => sesskey()]);
        echo $OUTPUT->confirm(get_string('bulkconfirm', 'local_contenttranslator'), $confirmurl, $url);
        echo $OUTPUT->footer();
        exit;
    }
    if ($action === 'bulk' && $cantranslate) {
        $bulkaction = required_param('bulkaction', PARAM_ALPHA);
        $itemids = optional_param_array('itemids', [], PARAM_INT);
        $bulklang = optional_param('bulklang', '', PARAM_ALPHANUMEXT);
        $count = 0;
        foreach ($itemids as $itemid) {
            $item = item_manager::get_item($itemid);
            if (!$item || ($courseid && $item->courseid != $courseid)) {
                continue;
            }
            $targets = $bulklang !== '' ? [$bulklang] : $langs;
            foreach ($targets as $lang) {
                if ($lang === $item->sourcelang) {
                    continue;
                }
                $translation = translation_manager::get_for_item($itemid, $lang);
                switch ($bulkaction) {
                    case 'translate':
                        $translation = $translation ?? translation_manager::ensure($itemid, $lang);
                        translation_manager::requeue($translation, (int)$USER->id);
                        $count++;
                        break;
                    case 'review':
                        if ($translation && $canreview) {
                            translation_manager::mark_reviewed($translation, (int)$USER->id);
                            $count++;
                        }
                        break;
                    case 'lock':
                    case 'unlock':
                        if ($translation && $canreview) {
                            translation_manager::set_locked($translation, $bulkaction === 'lock', (int)$USER->id);
                            $count++;
                        }
                        break;
                    case 'delete':
                        if ($translation && $canreview) {
                            translation_manager::delete($translation, (int)$USER->id);
                            $count++;
                        }
                        break;
                    case 'exclude':
                        if ($canreview) {
                            $DB->set_field('local_contenttranslator_item', 'excluded', 1, ['id' => $itemid]);
                            \local_contenttranslator\cache_helper::invalidate(
                                $item->sourcehash,
                                (int)$item->courseid,
                                (int)$item->categoryid
                            );
                            $count++;
                        }
                        break;
                }
            }
        }
        if ($bulkaction === 'translate' && $count) {
            foreach ($bulklang !== '' ? [$bulklang] : $langs as $lang) {
                queue::queue_course_lang($courseid, $lang, budget::TRIGGER_BULK, (int)$USER->id, 0);
            }
        }
        redirect(
            $url,
            get_string('bulkactiondone', 'local_contenttranslator', $count),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('dashboard', 'local_contenttranslator'));

// Stats.
$stats = item_manager::get_stats($courseid ?: null, $langs);
$statdata = ['langs' => [], 'budget' => null];
foreach ($stats as $lang => $entry) {
    $entry['lang'] = $lang;
    $entry['name'] = config::lang_name($lang);
    $entry['machinepercent'] = $entry['total'] ? (int)round($entry['machine'] / $entry['total'] * 100) : 0;
    $entry['stalepercent'] = $entry['total'] ? (int)round($entry['stale'] / $entry['total'] * 100) : 0;
    $entry['filterurl'] = (new moodle_url($url, ['lang' => $lang]))->out(false);
    $statdata['langs'][] = $entry;
}
if (has_capability('local/contenttranslator:manage', context_system::instance())) {
    $limit = budget::get_limit();
    $used = budget::get_used();
    $warnpercent = budget::get_warning_percent();
    $changeurl = new moodle_url('/admin/settings.php', ['section' => 'local_contenttranslator'], 'admin-budgetchars');
    $statdata['budget'] = [
        'hasbudget' => $limit > 0,
        'limit' => number_format($limit),
        'used' => number_format($used),
        'limittokens' => number_format(budget::chars_to_tokens($limit)),
        'usedtokens' => number_format(budget::chars_to_tokens($used)),
        'percent' => $limit > 0 ? min(100, (int)round($used / $limit * 100)) : 0,
        'warning' => $limit > 0 && $warnpercent > 0 && $used / $limit * 100 >= $warnpercent,
        'paused' => budget::is_paused(),
        'changeurl' => $changeurl->out(false),
    ];
    if (has_capability('local/contenttranslator:viewreports', context_system::instance())) {
        $usage = (new trial_provisioner())->get_usage();
        if ($usage !== null) {
            $statdata['aicredit'] = [
                'unlimited' => $usage['unlimited'],
                'percent' => $usage['unlimited'] ? null : (int)round($usage['percent']),
                'expires' => $usage['expiresat']
                    ? userdate($usage['expiresat'], get_string('strftimedatefullshort', 'langconfig'))
                    : null,
                'daysleft' => $usage['expiresat']
                    ? max(0, (int)ceil(($usage['expiresat'] - time()) / DAYSECS))
                    : null,
                'shopurl' => $usage['shopurl'],
            ];
        }
    }
}
echo $OUTPUT->render_from_template('local_contenttranslator/stats', $statdata);

// Action buttons.
$buttons = [];
if ($cantranslate) {
    $buttons[] = $OUTPUT->single_button(
        new moodle_url($url, ['action' => 'scan', 'sesskey' => sesskey()]),
        get_string('scannow', 'local_contenttranslator'),
        'post',
        ['class' => 'd-inline-block mr-1 me-1']
    );
}
if ($canbulk && $courseid) {
    $buttons[] = $OUTPUT->single_button(
        new moodle_url($url, ['action' => 'translate', 'sesskey' => sesskey()]),
        get_string('bulktranslate', 'local_contenttranslator'),
        'post',
        ['class' => 'd-inline-block mr-1 me-1']
    );
}
if ($courseid && has_capability('local/contenttranslator:configurecourse', $context)) {
    $buttons[] = $OUTPUT->single_button(
        new moodle_url('/local/contenttranslator/course.php', ['courseid' => $courseid]),
        get_string('coursesettings', 'local_contenttranslator'),
        'get',
        ['class' => 'd-inline-block mr-1 me-1']
    );
}
if (!$courseid && has_capability('local/contenttranslator:manage', $context)) {
    $buttons[] = $OUTPUT->single_button(
        new moodle_url('/admin/settings.php', ['section' => 'local_contenttranslator']),
        get_string('settings'),
        'get',
        ['class' => 'd-inline-block mr-1 me-1']
    );
    $buttons[] = $OUTPUT->single_button(
        new moodle_url('/local/contenttranslator/wizard.php'),
        get_string('wizard', 'local_contenttranslator'),
        'get',
        ['class' => 'd-inline-block mr-1 me-1']
    );
}
echo html_writer::div(implode(' ', $buttons), 'mb-3');

// Filter form.
$statusoptions = [
    '' => get_string('allstatuses', 'local_contenttranslator'),
    'missing' => get_string('status:missing', 'local_contenttranslator'),
];
foreach (translation_manager::STATUSES as $status) {
    $statusoptions[$status] = translation_manager::status_label($status);
}
$statusoptions['suggestion'] = get_string('status:suggestion', 'local_contenttranslator');
$statusoptions['locked'] = get_string('status:locked', 'local_contenttranslator');
$langoptions = ['' => get_string('alllanguages', 'local_contenttranslator')];
foreach ($langs as $lang) {
    $langoptions[$lang] = config::lang_name($lang);
}
$typeoptions = ['' => get_string('alltypes', 'local_contenttranslator')];
$typesql = 'SELECT DISTINCT itemtype, component FROM {local_contenttranslator_item}'
    . ($courseid ? ' WHERE courseid = :courseid' : '');
foreach ($DB->get_records_sql($typesql, ['courseid' => $courseid]) as $type) {
    $source = \local_contenttranslator\source\registry::get()->get_source($type->component, $type->itemtype);
    $typeoptions[$type->itemtype] = $source ? $source->get_display_name() : $type->itemtype;
}
echo html_writer::start_tag('form', [
    'method' => 'get', 'action' => $url->out_omit_querystring(), 'class' => 'form-inline mb-3 d-flex flex-wrap gap-2',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $courseid]);
echo html_writer::select($langoptions, 'lang', $filters['lang'], false, ['class' => 'custom-select form-select mr-1 me-1']);
echo html_writer::select($statusoptions, 'status', $filters['status'], false, ['class' => 'custom-select form-select mr-1 me-1']);
echo html_writer::select($typeoptions, 'itemtype', $filters['itemtype'], false, ['class' => 'custom-select form-select mr-1 me-1']);
echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'search', 'value' => $filters['search'],
    'placeholder' => get_string('search'), 'class' => 'form-control mr-1 me-1']);
echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('filter'), 'class' => 'btn btn-secondary']);
echo html_writer::end_tag('form');

// Items table inside a bulk action form.
$table = new items_table('local_contenttranslator_items_' . $courseid, $courseid ?: null, $langs, $filters, $url);
$table->define_baseurl($url);
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false), 'id' => 'ct-bulkform']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'bulk']);
$table->out(50, false);
if ($cantranslate) {
    $bulkoptions = ['translate' => get_string('bulk:translate', 'local_contenttranslator')];
    if ($canreview) {
        $bulkoptions += [
            'review' => get_string('bulk:review', 'local_contenttranslator'),
            'lock' => get_string('bulk:lock', 'local_contenttranslator'),
            'unlock' => get_string('bulk:unlock', 'local_contenttranslator'),
            'delete' => get_string('bulk:delete', 'local_contenttranslator'),
            'exclude' => get_string('bulk:exclude', 'local_contenttranslator'),
        ];
    }
    echo html_writer::start_div('d-flex flex-wrap align-items-center gap-2 mt-2');
    echo html_writer::span(get_string('withselected', 'local_contenttranslator'), 'mr-2 me-2');
    echo html_writer::select($bulkoptions, 'bulkaction', 'translate', false, ['class' => 'custom-select form-select mr-1 me-1']);
    echo html_writer::select($langoptions, 'bulklang', '', false, ['class' => 'custom-select form-select mr-1 me-1']);
    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('go'), 'class' => 'btn btn-primary']);
    echo html_writer::end_div();
}
echo html_writer::end_tag('form');
$PAGE->requires->js_amd_inline("
require(['jquery'], function($) {
    $('#ct-selectall').on('change', function() { $('.ct-select').prop('checked', this.checked); });
});");
echo $OUTPUT->footer();
