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

namespace local_contenttranslator;

use local_contenttranslator\source\content_source;
use local_contenttranslator\source\registry;
use local_contenttranslator\source\source_item;
use local_contenttranslator\source\subtable_map;

/**
 * Item registry: synchronises translatable items with their sources and detects changes.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class item_manager {
    /**
     * Load an item.
     *
     * @param int $id
     * @return \stdClass|null
     */
    public static function get_item(int $id): ?\stdClass {
        global $DB;
        $record = $DB->get_record('local_contenttranslator_item', ['id' => $id]);
        return $record ?: null;
    }

    /**
     * Load an item by identity.
     *
     * @param string $component
     * @param string $itemtype
     * @param string $field
     * @param int $itemid
     * @return \stdClass|null
     */
    public static function find(string $component, string $itemtype, string $field, int $itemid): ?\stdClass {
        global $DB;
        $record = $DB->get_record('local_contenttranslator_item', [
            'component' => $component, 'itemtype' => $itemtype, 'field' => $field, 'itemid' => $itemid,
        ]);
        return $record ?: null;
    }

    /**
     * Source language of an item: explicit -> course language -> site language.
     *
     * @param source_item $data
     * @return string
     */
    public static function resolve_sourcelang(source_item $data): string {
        global $CFG;
        if (!empty($data->lang)) {
            return $data->lang;
        }
        return $CFG->lang ?? 'en';
    }

    /**
     * Synchronise all items of one source record.
     *
     * @param content_source $source
     * @param source_item $data
     * @param int $time Scan time stamp (written to timechecked).
     * @return array ['changed' => item ids whose source changed or that are new, 'items' => all item records]
     */
    public static function sync_item(content_source $source, source_item $data, int $time = 0): array {
        global $DB;
        $time = $time ?: time();
        $component = $source->get_component();
        $itemtype = $source->get_itemtype();
        $courseid = $data->courseid;
        $categoryid = 0;
        if ($itemtype === 'course_categories') {
            $categoryid = $data->itemid;
        } else if ($courseid > 0) {
            $categoryid = (int)($DB->get_field('course', 'category', ['id' => $courseid]) ?: 0);
        }
        $existing = $DB->get_records('local_contenttranslator_item', [
            'component' => $component, 'itemtype' => $itemtype, 'itemid' => $data->itemid,
        ], '', 'field, id, sourcehash, sourcelang, langlocked, contextid, courseid, categoryid, label, excluded');
        $changed = [];
        $items = [];
        $sourcelang = self::resolve_sourcelang($data);
        foreach ($data->fields as $field => $def) {
            if (subtable_map::is_excluded_field($itemtype, $field)) {
                continue;
            }
            $text = $def['text'];
            $format = (int)$def['format'];
            if (!normaliser::is_translatable($text)) {
                continue;
            }
            $hash = normaliser::hash($text, $format);
            $chars = normaliser::count_chars($text, $format);
            $isstring = !empty($def['string']) ? 1 : 0;
            if (isset($existing[$field])) {
                $item = $existing[$field];
                $update = (object)[
                    'id' => $item->id,
                    'contextid' => $data->contextid,
                    'courseid' => $courseid,
                    'categoryid' => $categoryid,
                    'label' => \core_text::substr($data->label, 0, 255),
                    'sourceformat' => $format,
                    'isstring' => $isstring,
                    'chars' => $chars,
                    'timechecked' => $time,
                ];
                if (!$item->langlocked) {
                    $update->sourcelang = $sourcelang;
                }
                if ($item->sourcehash !== $hash) {
                    $update->sourcehash = $hash;
                    $update->sourcetext = $text;
                    $update->timemodified = $time;
                    $DB->update_record('local_contenttranslator_item', $update);
                    cache_helper::invalidate($item->sourcehash, (int)$item->courseid, (int)$item->categoryid);
                    cache_helper::invalidate($hash, $courseid, $categoryid);
                    translation_manager::mark_stale_for_item((int)$item->id, 0);
                    $changed[] = (int)$item->id;
                } else {
                    // Keep the snapshot current even when only markup changed.
                    $update->sourcetext = $text;
                    $DB->update_record('local_contenttranslator_item', $update);
                }
                $items[] = (int)$item->id;
                unset($existing[$field]);
            } else {
                $record = (object)[
                    'component' => $component,
                    'itemtype' => $itemtype,
                    'field' => $field,
                    'itemid' => $data->itemid,
                    'contextid' => $data->contextid,
                    'courseid' => $courseid,
                    'categoryid' => $categoryid,
                    'label' => \core_text::substr($data->label, 0, 255),
                    'sourcelang' => $sourcelang,
                    'langlocked' => 0,
                    'sourcehash' => $hash,
                    'sourcetext' => $text,
                    'sourceformat' => $format,
                    'isstring' => $isstring,
                    'chars' => $chars,
                    'excluded' => 0,
                    'timechecked' => $time,
                    'timecreated' => $time,
                    'timemodified' => $time,
                ];
                $record->id = $DB->insert_record('local_contenttranslator_item', $record);
                cache_helper::invalidate($hash, $courseid, $categoryid);
                $changed[] = (int)$record->id;
                $items[] = (int)$record->id;
            }
        }
        // Fields that are now empty or excluded.
        foreach ($existing as $item) {
            self::delete_item((int)$item->id);
        }
        return ['changed' => $changed, 'items' => $items];
    }

    /**
     * Synchronise one record of a table (event path).
     *
     * @param string $table
     * @param int $id
     * @param bool $deleted
     * @return array changed item ids
     */
    public static function sync_by_table(string $table, int $id, bool $deleted = false): array {
        $source = registry::get()->get_source_for_table($table);
        if (!$source) {
            return [];
        }
        $data = $deleted ? null : $source->get_item($id);
        if (!$data) {
            self::delete_items($source->get_component(), $source->get_itemtype(), $id);
            return [];
        }
        return self::sync_item($source, $data)['changed'];
    }

    /**
     * Synchronise every item of a course and remove items that no longer exist.
     *
     * @param int $courseid
     * @return array ['changed' => int[], 'total' => int, 'deleted' => int]
     */
    public static function sync_course(int $courseid): array {
        global $DB;
        $time = time();
        $changed = [];
        $total = 0;
        foreach (registry::get()->get_sources() as $source) {
            foreach ($source->get_items_for_course($courseid) as $data) {
                $result = self::sync_item($source, $data, $time);
                $changed = array_merge($changed, $result['changed']);
                $total += count($result['items']);
            }
        }
        // Anything in this course that was not seen during this scan is gone.
        $stale = $DB->get_records_select(
            'local_contenttranslator_item',
            'courseid = :courseid AND timechecked < :time',
            ['courseid' => $courseid, 'time' => $time],
            '',
            'id'
        );
        foreach ($stale as $item) {
            self::delete_item((int)$item->id);
        }
        config::save_override('course', $courseid, ['timescanned' => $time]);
        return ['changed' => $changed, 'total' => $total, 'deleted' => count($stale)];
    }

    /**
     * Synchronise site level items (categories, ...).
     *
     * @return array changed item ids
     */
    public static function sync_site(): array {
        $changed = [];
        $time = time();
        foreach (registry::get()->get_sources() as $source) {
            foreach ($source->get_site_items() as $data) {
                $changed = array_merge($changed, self::sync_item($source, $data, $time)['changed']);
            }
        }
        return $changed;
    }

    /**
     * Delete all items of a record.
     *
     * @param string $component
     * @param string $itemtype
     * @param int $itemid
     */
    public static function delete_items(string $component, string $itemtype, int $itemid): void {
        global $DB;
        $items = $DB->get_records('local_contenttranslator_item', [
            'component' => $component, 'itemtype' => $itemtype, 'itemid' => $itemid,
        ], '', 'id');
        foreach ($items as $item) {
            self::delete_item((int)$item->id);
        }
    }

    /**
     * Delete all items of a course.
     *
     * @param int $courseid
     */
    public static function delete_course(int $courseid): void {
        global $DB;
        $items = $DB->get_records('local_contenttranslator_item', ['courseid' => $courseid], '', 'id');
        foreach ($items as $item) {
            self::delete_item((int)$item->id);
        }
        $DB->delete_records('local_contenttranslator_cfg', ['instancetype' => 'course', 'instanceid' => $courseid]);
    }

    /**
     * Delete one item with its translations. History rows stay until cleanup.
     *
     * @param int $id
     */
    public static function delete_item(int $id): void {
        global $DB;
        $item = self::get_item($id);
        if (!$item) {
            return;
        }
        $DB->delete_records('local_contenttranslator_tr', ['itemid' => $id]);
        $DB->delete_records('local_contenttranslator_item', ['id' => $id]);
        cache_helper::invalidate($item->sourcehash, (int)$item->courseid, (int)$item->categoryid);
    }

    /**
     * Make sure a queued translation row exists for each target language.
     *
     * @param \stdClass $item
     * @param string[] $langs
     */
    public static function ensure_translations(\stdClass $item, array $langs): void {
        if ($item->excluded) {
            return;
        }
        foreach ($langs as $lang) {
            if ($lang === $item->sourcelang) {
                continue;
            }
            translation_manager::ensure((int)$item->id, $lang);
        }
    }

    /**
     * Translations waiting for the pipeline in a course and language.
     *
     * Queued rows, plus stale rows that have no suggestion for the current source yet.
     *
     * @param int $courseid
     * @param string $lang
     * @param int $limit
     * @return \stdClass[] translation records with item_* columns
     */
    public static function get_pending(int $courseid, string $lang, int $limit = 0): array {
        global $DB;
        $sql = "SELECT t.*, i.sourcehash AS item_sourcehash
                  FROM {local_contenttranslator_tr} t
                  JOIN {local_contenttranslator_item} i ON i.id = t.itemid
                 WHERE i.courseid = :courseid AND t.targetlang = :lang AND i.excluded = 0 AND i.sourcelang <> t.targetlang
                   AND (t.status = :queued
                        OR (t.status = :stale AND (t.suggestionhash IS NULL OR t.suggestionhash <> i.sourcehash)))
              ORDER BY t.timemodified ASC, t.id ASC";
        return $DB->get_records_sql($sql, [
            'courseid' => $courseid, 'lang' => $lang,
            'queued' => translation_manager::STATUS_QUEUED, 'stale' => translation_manager::STATUS_STALE,
        ], 0, $limit);
    }

    /**
     * Distinct (courseid, lang) pairs with pending work.
     *
     * @return \stdClass[]
     */
    public static function get_pending_pairs(): array {
        global $DB;
        $sql = "SELECT " . $DB->sql_concat('i.courseid', "'-'", 't.targetlang') . " AS id, i.courseid, t.targetlang,
                       COUNT(1) AS pending
                  FROM {local_contenttranslator_tr} t
                  JOIN {local_contenttranslator_item} i ON i.id = t.itemid
                 WHERE i.excluded = 0 AND i.sourcelang <> t.targetlang
                   AND (t.status = :queued
                        OR (t.status = :stale AND (t.suggestionhash IS NULL OR t.suggestionhash <> i.sourcehash)))
              GROUP BY i.courseid, t.targetlang";
        return $DB->get_records_sql($sql, [
            'queued' => translation_manager::STATUS_QUEUED, 'stale' => translation_manager::STATUS_STALE,
        ]);
    }

    /**
     * Status counts per language for a course (or the whole site when courseid is null).
     *
     * @param int|null $courseid
     * @param string[] $langs
     * @return array lang => ['total' => n, 'missing' => n, 'queued' => n, 'machine' => n, ...]
     */
    public static function get_stats(?int $courseid, array $langs): array {
        global $DB;
        $where = 'i.excluded = 0';
        $params = [];
        if ($courseid !== null) {
            $where .= ' AND i.courseid = :courseid';
            $params['courseid'] = $courseid;
        }
        $stats = [];
        foreach ($langs as $lang) {
            $total = (int)$DB->count_records_sql(
                "SELECT COUNT(1) FROM {local_contenttranslator_item} i WHERE $where AND i.sourcelang <> :lang",
                $params + ['lang' => $lang]
            );
            $rows = $DB->get_records_sql(
                "SELECT t.status, COUNT(1) AS cnt
                   FROM {local_contenttranslator_tr} t
                   JOIN {local_contenttranslator_item} i ON i.id = t.itemid
                  WHERE $where AND t.targetlang = :lang AND i.sourcelang <> :lang2
               GROUP BY t.status",
                $params + ['lang' => $lang, 'lang2' => $lang]
            );
            $entry = ['total' => $total, 'missing' => $total];
            foreach (translation_manager::STATUSES as $status) {
                $entry[$status] = isset($rows[$status]) ? (int)$rows[$status]->cnt : 0;
                $entry['missing'] -= $entry[$status];
            }
            $entry['locked'] = (int)$DB->count_records_sql(
                "SELECT COUNT(1) FROM {local_contenttranslator_tr} t
                   JOIN {local_contenttranslator_item} i ON i.id = t.itemid
                  WHERE $where AND t.targetlang = :lang AND t.locked = 1",
                $params + ['lang' => $lang]
            );
            $entry['done'] = $entry['machine'] + $entry['reviewed'];
            $entry['percent'] = $total > 0 ? (int)round($entry['done'] / $total * 100) : 0;
            $entry['reviewedpercent'] = $total > 0 ? (int)round($entry['reviewed'] / $total * 100) : 0;
            $stats[$lang] = $entry;
        }
        return $stats;
    }

    /**
     * Characters that a bulk translation of a course into a language would send to an engine.
     *
     * @param int $courseid
     * @param string $lang
     * @return array ['items' => n, 'chars' => n, 'tmhits' => n]
     */
    public static function estimate_course(int $courseid, string $lang): array {
        global $DB;
        $sql = "SELECT i.id, i.chars, i.sourcehash, i.sourcelang, i.categoryid, t.id AS translationid, t.status,
                       t.suggestionhash, t.locked, t.origin
                  FROM {local_contenttranslator_item} i
             LEFT JOIN {local_contenttranslator_tr} t ON t.itemid = i.id AND t.targetlang = :lang
                 WHERE i.courseid = :courseid AND i.excluded = 0 AND i.sourcelang <> :lang2";
        $rows = $DB->get_records_sql($sql, ['lang' => $lang, 'courseid' => $courseid, 'lang2' => $lang]);
        $items = 0;
        $chars = 0;
        $tmhits = 0;
        foreach ($rows as $row) {
            $needs = $row->translationid === null
                || $row->status === translation_manager::STATUS_QUEUED
                || $row->status === translation_manager::STATUS_FAILED
                || ($row->status === translation_manager::STATUS_STALE && $row->suggestionhash !== $row->sourcehash);
            if (!$needs) {
                continue;
            }
            $items++;
            $tenantkey = tenant::key($courseid, (int)$row->categoryid);
            if (tm::find($tenantkey, $row->sourcelang, $lang, $row->sourcehash)) {
                $tmhits++;
            } else {
                $chars += (int)$row->chars;
            }
        }
        return ['items' => $items, 'chars' => $chars, 'tmhits' => $tmhits];
    }
}
