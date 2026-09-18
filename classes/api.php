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

use context;

/**
 * Public API for plugins and the render filter.
 *
 * Every lookup is served from the MUC cache or one DB query. Engines are never called here.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class api {
    /**
     * Languages to try for a user language: the language itself, then its parents.
     *
     * @param string $lang
     * @return string[]
     */
    public static function langs_to_try(string $lang): array {
        $langs = [$lang];
        $current = $lang;
        for ($i = 0; $i < 3; $i++) {
            $parent = get_parent_language($current);
            if ($parent === '' || in_array($parent, $langs, true)) {
                break;
            }
            $langs[] = $parent;
            $current = $parent;
        }
        return $langs;
    }

    /**
     * Find the translation to display for a text.
     *
     * @param string $text Rendered or raw text.
     * @param string|null $lang Target language, default current language.
     * @param context|null $context Render context (tenant and "same context first").
     * @return array|null null when the text is unknown; otherwise
     *   ['found' => bool, 'text' => ?string, 'format' => int, 'status' => ?string, 'origin' => ?string,
     *    'sourcelang' => string, 'itemid' => int, 'translationid' => ?int, 'lang' => string, 'courseid' => int]
     */
    public static function lookup(string $text, ?string $lang = null, ?context $context = null): ?array {
        $lang = $lang ?? current_language();
        if (!normaliser::is_translatable($text)) {
            return null;
        }
        if (config::get('skipmultilang', 1) && normaliser::has_multilang($text)) {
            return null;
        }
        $hash = normaliser::hash($text);
        return self::lookup_hash($hash, $lang, $context);
    }

    /**
     * Same as lookup() for an already computed hash.
     *
     * @param string $hash
     * @param string $lang
     * @param context|null $context
     * @return array|null
     */
    public static function lookup_hash(string $hash, string $lang, ?context $context = null): ?array {
        $tenantkey = tenant::key_for_context($context);
        $contextid = $context ? (int)$context->id : 0;
        $cache = cache_helper::cache();
        $known = null;
        foreach (self::langs_to_try($lang) as $trylang) {
            $key = cache_helper::key($hash, $trylang, $tenantkey);
            $candidates = $cache->get($key);
            if ($candidates === false) {
                $candidates = self::query($hash, $trylang, $tenantkey);
                $cache->set($key, $candidates);
            }
            if (!$candidates) {
                return $known;
            }
            $best = self::pick($candidates, $contextid, $trylang);
            if ($best === null) {
                $first = reset($candidates);
                $known = $known ?? [
                    'found' => false, 'text' => null, 'format' => FORMAT_HTML, 'status' => null, 'origin' => null,
                    'sourcelang' => $first['sourcelang'], 'itemid' => (int)$first['itemid'], 'translationid' => null,
                    'lang' => $trylang, 'courseid' => (int)$first['courseid'],
                ];
                continue;
            }
            if ($best['sourcelang'] === $trylang) {
                // The user's language is the source language: nothing to do.
                return $known ?? ['found' => false, 'text' => null, 'format' => FORMAT_HTML, 'status' => null,
                    'origin' => null, 'sourcelang' => $best['sourcelang'], 'itemid' => (int)$best['itemid'],
                    'translationid' => null, 'lang' => $trylang, 'courseid' => (int)$best['courseid']];
            }
            return $best + ['found' => true, 'lang' => $trylang];
        }
        return $known;
    }

    /**
     * DB query for all candidate rows of a hash in a language within a tenant.
     *
     * @param string $hash
     * @param string $lang
     * @param string $tenantkey
     * @return array
     */
    private static function query(string $hash, string $lang, string $tenantkey): array {
        global $DB;
        $where = 'i.sourcehash = :hash AND i.excluded = 0';
        $params = ['hash' => $hash, 'lang' => $lang];
        if (strpos($tenantkey, 'course:') === 0) {
            $where .= ' AND (i.courseid = :courseid OR i.courseid = 0)';
            $params['courseid'] = (int)substr($tenantkey, 7);
        } else if (strpos($tenantkey, 'category:') === 0) {
            $where .= ' AND (i.categoryid = :categoryid OR i.courseid = 0)';
            $params['categoryid'] = (int)substr($tenantkey, 9);
        }
        $sql = "SELECT i.id AS itemid, i.sourcelang, i.courseid, i.contextid, i.isstring, i.sourcehash AS itemhash,
                       t.id AS translationid, t.text, t.format, t.status, t.origin, t.locked, t.reviewerid, t.timemodified,
                       t.sourcehash AS translationhash
                  FROM {local_contenttranslator_item} i
             LEFT JOIN {local_contenttranslator_tr} t ON t.itemid = i.id AND t.targetlang = :lang
                 WHERE $where
              ORDER BY i.id";
        $rows = [];
        foreach ($DB->get_records_sql($sql, $params, 0, 50) as $row) {
            $status = $row->status;
            if ($status === translation_manager::STATUS_FAILED && (string)$row->text !== '') {
                // A failed attempt keeps the earlier text: show it for what it is, a translation of the current
                // source or of an earlier one.
                $status = $row->translationhash === $row->itemhash
                    ? translation_manager::STATUS_MACHINE
                    : translation_manager::STATUS_STALE;
            }
            $rows[] = [
                'itemid' => (int)$row->itemid,
                'sourcelang' => $row->sourcelang,
                'courseid' => (int)$row->courseid,
                'contextid' => (int)$row->contextid,
                'translationid' => $row->translationid ? (int)$row->translationid : null,
                'text' => $row->text,
                'format' => (int)($row->format ?? FORMAT_HTML),
                'status' => $status,
                'origin' => $row->origin,
                'locked' => (int)$row->locked,
                'reviewerid' => (int)$row->reviewerid,
                'timemodified' => (int)$row->timemodified,
            ];
        }
        return $rows;
    }

    /**
     * Pick the best visible translation among candidates.
     *
     * @param array $candidates
     * @param int $contextid
     * @param string $lang
     * @return array|null
     */
    private static function pick(array $candidates, int $contextid, string $lang): ?array {
        $best = null;
        $bestscore = -1;
        foreach ($candidates as $row) {
            if ($row['sourcelang'] === $lang) {
                // Source is already in this language.
                return $row;
            }
            if ($row['translationid'] === null || $row['text'] === null || $row['text'] === '') {
                continue;
            }
            if (!self::is_visible($row, $lang)) {
                continue;
            }
            $score = 0;
            if ($row['contextid'] === $contextid) {
                $score += 100;
            }
            $score += match ($row['status']) {
                translation_manager::STATUS_REVIEWED => 30,
                translation_manager::STATUS_MACHINE => 20,
                translation_manager::STATUS_STALE => 10,
                default => 0,
            };
            if ($score > $bestscore) {
                $best = $row;
                $bestscore = $score;
            }
        }
        return $best;
    }

    /**
     * Apply the visibility mode of the language / course to a candidate row.
     *
     * @param array $row
     * @param string $lang
     * @return bool
     */
    public static function is_visible(array $row, string $lang): bool {
        $visibility = config::get_visibility($lang, (int)$row['courseid']);
        switch ($row['status']) {
            case translation_manager::STATUS_REVIEWED:
                return true;
            case translation_manager::STATUS_MACHINE:
                return $visibility === config::VISIBILITY_IMMEDIATE;
            case translation_manager::STATUS_STALE:
                if (!config::show_stale($lang)) {
                    return false;
                }
                return $visibility === config::VISIBILITY_IMMEDIATE || $row['reviewerid'] > 0;
            default:
                return false;
        }
    }

    /**
     * Translated text for plugins that output text without Moodle filters (e-mails, PDFs, tables).
     *
     * Returns the source text when no visible translation exists. No markup is added.
     *
     * @param string $text
     * @param string|null $lang
     * @param context|null $context
     * @return string
     */
    public static function get_translation(string $text, ?string $lang = null, ?context $context = null): string {
        $result = self::lookup($text, $lang, $context);
        if (!$result || !$result['found']) {
            return $text;
        }
        return normaliser::rewrite_file_urls((string)$result['text'], $text);
    }

    /**
     * Translated text of a specific field, by identity (no hash lookup).
     *
     * @param string $component
     * @param string $itemtype
     * @param string $field
     * @param int $itemid
     * @param string $text Fallback (current source text).
     * @param string|null $lang
     * @return string
     */
    public static function translate_field(
        string $component,
        string $itemtype,
        string $field,
        int $itemid,
        string $text,
        ?string $lang = null
    ): string {
        $lang = $lang ?? current_language();
        $item = item_manager::find($component, $itemtype, $field, $itemid);
        if (!$item || $item->excluded) {
            return $text;
        }
        foreach (self::langs_to_try($lang) as $trylang) {
            if ($trylang === $item->sourcelang) {
                return $text;
            }
            $translation = translation_manager::get_for_item((int)$item->id, $trylang);
            if (!$translation || empty($translation->text)) {
                continue;
            }
            $row = [
                'courseid' => (int)$item->courseid, 'status' => $translation->status,
                'reviewerid' => (int)$translation->reviewerid,
            ];
            if (self::is_visible($row, $trylang)) {
                return normaliser::rewrite_file_urls((string)$translation->text, $text);
            }
        }
        return $text;
    }

    /**
     * Translate one item into one language right now (interactive; runs as the given user).
     *
     * @param int $itemid
     * @param string $lang
     * @param int $userid
     * @return \stdClass translation record
     */
    public static function translate_now(int $itemid, string $lang, int $userid): \stdClass {
        $item = item_manager::get_item($itemid);
        if (!$item) {
            throw new \moodle_exception('error:itemnotfound', 'local_contenttranslator');
        }
        $translation = translation_manager::ensure($itemid, $lang);
        if (!translation_manager::is_overwritable($translation)) {
            $translation->suggestionhash = null;
        }
        return translator::translate_item($item, $lang, budget::TRIGGER_ONDEMAND, $userid);
    }
}
