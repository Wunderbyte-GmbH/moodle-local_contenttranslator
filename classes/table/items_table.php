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

namespace local_contenttranslator\table;

use local_contenttranslator\config;
use local_contenttranslator\translation_manager;
use moodle_url;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/tablelib.php');

/**
 * Items table of the dashboard: one row per item, one status column per target language.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class items_table extends \table_sql {
    /** @var string[] Target languages */
    protected array $langs;

    /** @var array itemid => lang => translation */
    protected array $translations = [];

    /** @var moodle_url */
    protected moodle_url $returnurl;

    /**
     * Constructor.
     *
     * @param string $uniqueid
     * @param int|null $courseid Null for site wide.
     * @param string[] $langs
     * @param array $filters lang, status, search, itemtype
     * @param moodle_url $returnurl
     */
    public function __construct(string $uniqueid, ?int $courseid, array $langs, array $filters, moodle_url $returnurl) {
        global $DB;
        parent::__construct($uniqueid);
        $this->langs = $langs;
        $this->returnurl = $returnurl;

        $columns = ['select', 'label', 'field', 'sourcetext'];
        $headers = [
            \html_writer::checkbox('selectall', 1, false, '', ['id' => 'ct-selectall', 'title' => get_string('selectall')]),
            get_string('item', 'local_contenttranslator'),
            get_string('field', 'local_contenttranslator'),
            get_string('source', 'local_contenttranslator'),
        ];
        foreach ($langs as $lang) {
            $columns[] = 'lang_' . $lang;
            $headers[] = config::lang_name($lang);
        }
        $this->define_columns($columns);
        $this->define_headers($headers);
        $this->no_sorting('select');
        $this->no_sorting('sourcetext');
        foreach ($langs as $lang) {
            $this->no_sorting('lang_' . $lang);
        }
        $this->sortable(true, 'label', SORT_ASC);
        $this->collapsible(false);
        $this->pageable(true);
        $this->set_attribute('class', 'generaltable table-sm local-contenttranslator-items');

        $where = ['i.excluded = 0'];
        $params = [];
        if ($courseid !== null) {
            $where[] = 'i.courseid = :courseid';
            $params['courseid'] = $courseid;
        }
        if (!empty($filters['itemtype'])) {
            $where[] = 'i.itemtype = :itemtype';
            $params['itemtype'] = $filters['itemtype'];
        }
        if (!empty($filters['search'])) {
            $like = '%' . $DB->sql_like_escape($filters['search']) . '%';
            $where[] = '(' . $DB->sql_like('i.sourcetext', ':s1', false) . ' OR ' . $DB->sql_like('i.label', ':s2', false)
                . ' OR EXISTS (SELECT 1 FROM {local_contenttranslator_tr} ts WHERE ts.itemid = i.id AND '
                . $DB->sql_like('ts.text', ':s3', false) . '))';
            $params['s1'] = $like;
            $params['s2'] = $like;
            $params['s3'] = $like;
        }
        $status = $filters['status'] ?? '';
        $lang = $filters['lang'] ?? '';
        if ($status !== '') {
            $langsql = $lang !== '' ? 'AND tf.targetlang = :flang' : '';
            if ($lang !== '') {
                $params['flang'] = $lang;
            }
            if ($status === 'missing') {
                if ($lang !== '') {
                    $where[] = "i.sourcelang <> :flang2 AND NOT EXISTS (SELECT 1 FROM {local_contenttranslator_tr} tf
                                 WHERE tf.itemid = i.id $langsql)";
                    $params['flang2'] = $lang;
                } else {
                    $where[] = "(SELECT COUNT(1) FROM {local_contenttranslator_tr} tf WHERE tf.itemid = i.id) < :nlangs";
                    $params['nlangs'] = count($langs);
                }
            } else if ($status === 'locked') {
                $where[] = "EXISTS (SELECT 1 FROM {local_contenttranslator_tr} tf
                             WHERE tf.itemid = i.id AND tf.locked = 1 $langsql)";
            } else if ($status === 'suggestion') {
                $where[] = "EXISTS (SELECT 1 FROM {local_contenttranslator_tr} tf WHERE tf.itemid = i.id
                             AND tf.suggestion IS NOT NULL $langsql)";
            } else {
                $where[] = "EXISTS (SELECT 1 FROM {local_contenttranslator_tr} tf WHERE tf.itemid = i.id
                             AND tf.status = :fstatus $langsql)";
                $params['fstatus'] = $status;
            }
        }
        $this->set_sql('i.*', '{local_contenttranslator_item} i', implode(' AND ', $where), $params);
        $this->set_count_sql('SELECT COUNT(1) FROM {local_contenttranslator_item} i WHERE ' . implode(' AND ', $where), $params);
    }

    #[\Override]
    public function query_db($pagesize, $useinitialsbar = true) {
        global $DB;
        parent::query_db($pagesize, false);
        $ids = array_keys($this->rawdata);
        if (!$ids) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
        $rows = $DB->get_records_select(
            'local_contenttranslator_tr',
            "itemid $insql",
            $params,
            '',
            'id, itemid, targetlang, status, origin, locked, suggestion, timemodified, failreason'
        );
        foreach ($rows as $row) {
            $this->translations[$row->itemid][$row->targetlang] = $row;
        }
    }

    /**
     * Checkbox column.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_select(\stdClass $row): string {
        return \html_writer::checkbox('itemids[]', $row->id, false, '', ['class' => 'ct-select']);
    }

    /**
     * Label with deep link to the source edit page.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_label(\stdClass $row): string {
        $label = s($row->label ?: ($row->itemtype . ' #' . $row->itemid));
        $source = \local_contenttranslator\source\registry::get()->get_source($row->component, $row->itemtype);
        $url = $source ? $source->get_edit_url((int)$row->itemid) : null;
        $out = $url ? \html_writer::link($url, $label, ['target' => '_blank']) : $label;
        $out .= \html_writer::tag('div', s($row->component) . ' · ' . s($row->sourcelang), ['class' => 'small text-muted']);
        return $out;
    }

    /**
     * Field name.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_field(\stdClass $row): string {
        return s($row->field);
    }

    /**
     * Source excerpt.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_sourcetext(\stdClass $row): string {
        $text = \local_contenttranslator\normaliser::normalise((string)$row->sourcetext, (int)$row->sourceformat);
        return \html_writer::tag('span', s(shorten_text($text, 120)), ['title' => s(shorten_text($text, 500))])
            . \html_writer::tag(
                'div',
                number_format((int)$row->chars) . ' ' . get_string('characters', 'local_contenttranslator'),
                ['class' => 'small text-muted']
            );
    }

    #[\Override]
    public function other_cols($column, $row) {
        if (strpos($column, 'lang_') !== 0) {
            return null;
        }
        $lang = substr($column, 5);
        if ($row->sourcelang === $lang) {
            return \html_writer::tag(
                'span',
                get_string('sourcelanguage', 'local_contenttranslator'),
                ['class' => 'text-muted small']
            );
        }
        $translation = $this->translations[$row->id][$lang] ?? null;
        $url = new moodle_url('/local/contenttranslator/edit.php', [
            'itemid' => $row->id, 'lang' => $lang, 'returnto' => $this->returnurl->out_as_local_url(false),
        ]);
        if (!$translation) {
            return \html_writer::link(
                $url,
                get_string('status:missing', 'local_contenttranslator'),
                ['class' => 'badge bg-light text-dark border']
            );
        }
        $label = translation_manager::status_label($translation->status);
        if (
            $translation->origin === translation_manager::ORIGIN_HUMAN
            && $translation->status === translation_manager::STATUS_MACHINE
        ) {
            $label = get_string('status:edited', 'local_contenttranslator');
        }
        if ($translation->locked) {
            $label .= ' 🔒';
        }
        $out = \html_writer::link($url, $label, ['class' => translation_manager::badge_class($translation)]);
        if ($translation->suggestion !== null) {
            $out .= ' ' . \html_writer::tag(
                'span',
                get_string('status:suggestion', 'local_contenttranslator'),
                ['class' => 'badge bg-primary text-white']
            );
        }
        if ($translation->status === translation_manager::STATUS_FAILED && $translation->failreason) {
            $out .= \html_writer::tag('div', s(shorten_text($translation->failreason, 80)), ['class' => 'small text-danger']);
        }
        return $out;
    }
}
