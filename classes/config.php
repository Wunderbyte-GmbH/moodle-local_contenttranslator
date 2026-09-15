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

use core_course_category;

/**
 * Effective configuration: site defaults, category and course overrides, per language settings.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class config {
    /** Machine translations are shown at once, with a label */
    public const VISIBILITY_IMMEDIATE = 'immediate';
    /** Only reviewed translations are shown */
    public const VISIBILITY_REVIEWED = 'reviewed';

    /**
     * Plugin setting with default.
     *
     * @param string $name
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $name, $default = null) {
        $value = get_config('local_contenttranslator', $name);
        return ($value === false || $value === null || $value === '') ? $default : $value;
    }

    /**
     * Site level target languages.
     *
     * @return string[]
     */
    public static function get_site_target_langs(): array {
        return self::parse_langs((string)self::get('targetlangs', ''));
    }

    /**
     * Parse a comma separated language list, keeping only installed languages.
     *
     * @param string $raw
     * @return string[]
     */
    public static function parse_langs(string $raw): array {
        $installed = get_string_manager()->get_list_of_translations(true);
        $known = get_string_manager()->get_list_of_languages('en');
        $langs = [];
        foreach (preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) as $lang) {
            $lang = trim($lang);
            if ((isset($installed[$lang]) || isset($known[$lang])) && !in_array($lang, $langs, true)) {
                $langs[] = $lang;
            }
        }
        return $langs;
    }

    /**
     * Stored override row for a course or category.
     *
     * @param string $type course|category
     * @param int $id
     * @return \stdClass|null
     */
    public static function get_override(string $type, int $id): ?\stdClass {
        global $DB;
        $record = $DB->get_record('local_contenttranslator_cfg', ['instancetype' => $type, 'instanceid' => $id]);
        return $record ?: null;
    }

    /**
     * Save an override row.
     *
     * @param string $type
     * @param int $id
     * @param array $values enabled, targetlangs (array|null), visibility (string|null), externalallowed
     */
    public static function save_override(string $type, int $id, array $values): void {
        global $DB;
        $record = self::get_override($type, $id) ?? (object)[
            'instancetype' => $type,
            'instanceid' => $id,
            'enabled' => -1,
            'targetlangs' => null,
            'visibility' => null,
            'externalallowed' => -1,
            'timescanned' => 0,
        ];
        if (array_key_exists('enabled', $values)) {
            $record->enabled = (int)$values['enabled'];
        }
        if (array_key_exists('targetlangs', $values)) {
            $record->targetlangs = $values['targetlangs'] === null ? null : implode(',', $values['targetlangs']);
        }
        if (array_key_exists('visibility', $values)) {
            $record->visibility = $values['visibility'] ?: null;
        }
        if (array_key_exists('externalallowed', $values)) {
            $record->externalallowed = (int)$values['externalallowed'];
        }
        if (array_key_exists('timescanned', $values)) {
            $record->timescanned = (int)$values['timescanned'];
        }
        $record->timemodified = time();
        if (empty($record->id)) {
            $DB->insert_record('local_contenttranslator_cfg', $record);
        } else {
            $DB->update_record('local_contenttranslator_cfg', $record);
        }
        \cache::make('local_contenttranslator', 'courseconfig')->purge();
    }

    /**
     * Effective settings for a course, walking course -> categories -> site.
     *
     * @param int $courseid
     * @return \stdClass enabled, targetlangs, visibility (per language), externalallowed, inherited (labels)
     */
    public static function get_effective(int $courseid): \stdClass {
        $cache = \cache::make('local_contenttranslator', 'courseconfig');
        $cached = $cache->get('c' . $courseid);
        if ($cached !== false) {
            return (object)$cached;
        }
        $result = (object)[
            'enabled' => (bool)self::get('defaultcourseenabled', 0),
            'targetlangs' => self::get_site_target_langs(),
            'visibility' => null,
            'externalallowed' => true,
            'inherited' => [
                'enabled' => 'site',
                'targetlangs' => 'site',
                'visibility' => 'site',
                'externalallowed' => 'site',
            ],
        ];
        $chain = [];
        if ($courseid > 0 && $courseid != SITEID) {
            $course = get_course($courseid);
            $category = core_course_category::get($course->category, IGNORE_MISSING, true);
            if ($category) {
                $chain[] = ['category', (int)$category->id, $category->get_formatted_name()];
                foreach (array_reverse($category->get_parents()) as $parentid) {
                    $parent = core_course_category::get($parentid, IGNORE_MISSING, true);
                    if ($parent) {
                        $chain[] = ['category', (int)$parent->id, $parent->get_formatted_name()];
                    }
                }
            }
            $chain = array_reverse($chain); // Top category first.
            $chain[] = ['course', $courseid, get_string('course')];
        }
        foreach ($chain as [$type, $id, $label]) {
            $override = self::get_override($type, $id);
            if (!$override) {
                continue;
            }
            if ($override->enabled >= 0) {
                $result->enabled = (bool)$override->enabled;
                $result->inherited['enabled'] = $type === 'course' ? 'course' : $label;
            }
            if ($override->targetlangs !== null) {
                $result->targetlangs = self::parse_langs($override->targetlangs);
                $result->inherited['targetlangs'] = $type === 'course' ? 'course' : $label;
            }
            if (!empty($override->visibility)) {
                $result->visibility = $override->visibility;
                $result->inherited['visibility'] = $type === 'course' ? 'course' : $label;
            }
            if ($override->externalallowed >= 0) {
                $result->externalallowed = (bool)$override->externalallowed;
                $result->inherited['externalallowed'] = $type === 'course' ? 'course' : $label;
            }
        }
        $cache->set('c' . $courseid, (array)$result);
        return $result;
    }

    /**
     * Target languages of a course (site languages for site level content).
     *
     * @param int $courseid
     * @return string[]
     */
    public static function get_course_target_langs(int $courseid): array {
        if ($courseid <= 0 || $courseid == SITEID) {
            return self::get_site_target_langs();
        }
        return self::get_effective($courseid)->targetlangs;
    }

    /**
     * Whether automatic translation runs for a course (needs a budget, see budget::is_automation_enabled()).
     *
     * @param int $courseid
     * @return bool
     */
    public static function is_auto_enabled_for_course(int $courseid): bool {
        if (!budget::is_automation_enabled()) {
            return false;
        }
        if ($courseid <= 0 || $courseid == SITEID) {
            return (bool)self::get('defaultcourseenabled', 0);
        }
        return self::get_effective($courseid)->enabled;
    }

    /**
     * Per language setting.
     *
     * @param string $lang
     * @param string $name visibility|showstale|badge|engine|formality|styleguide
     * @param mixed $default
     * @return mixed
     */
    public static function get_lang_setting(string $lang, string $name, $default = null) {
        return self::get('lang_' . str_replace('-', '_', $lang) . '_' . $name, $default);
    }

    /**
     * Effective visibility mode for a language in a course.
     *
     * @param string $lang
     * @param int $courseid
     * @return string
     */
    public static function get_visibility(string $lang, int $courseid = 0): string {
        if ($courseid > 0 && $courseid != SITEID) {
            $effective = self::get_effective($courseid);
            if (!empty($effective->visibility)) {
                return $effective->visibility;
            }
        }
        return self::get_lang_setting($lang, 'visibility', self::VISIBILITY_IMMEDIATE);
    }

    /**
     * Whether stale translations are still shown for a language.
     *
     * @param string $lang
     * @return bool
     */
    public static function show_stale(string $lang): bool {
        return (bool)self::get_lang_setting($lang, 'showstale', 1);
    }

    /**
     * Badge mode for a language: off, block or banner.
     *
     * @param string $lang
     * @return string
     */
    public static function get_badge_mode(string $lang): string {
        return (string)self::get_lang_setting($lang, 'badge', 'banner');
    }

    /**
     * Primary engine for a language.
     *
     * @param string $lang
     * @return string
     */
    public static function get_engine_for_lang(string $lang): string {
        $engine = (string)self::get_lang_setting($lang, 'engine', '');
        return $engine !== '' ? $engine : (string)self::get('engine', 'core_ai');
    }

    /**
     * Fallback engine for a language ('' for none).
     *
     * @param string $lang
     * @return string
     */
    public static function get_fallback_engine_for_lang(string $lang): string {
        $engine = (string)self::get_lang_setting($lang, 'fallbackengine', '');
        return $engine !== '' ? $engine : (string)self::get('fallbackengine', '');
    }

    /**
     * Human readable language name.
     *
     * @param string $lang
     * @return string
     */
    public static function lang_name(string $lang): string {
        $installed = get_string_manager()->get_list_of_translations(true);
        if (isset($installed[$lang])) {
            return $installed[$lang];
        }
        $known = get_string_manager()->get_list_of_languages();
        return $known[$lang] ?? $lang;
    }

    /**
     * English language name (for prompts).
     *
     * @param string $lang
     * @return string
     */
    public static function lang_name_english(string $lang): string {
        $languages = get_string_manager()->get_list_of_languages('en');
        $base = explode('_', $lang)[0];
        return $languages[$lang] ?? $languages[$base] ?? $lang;
    }
}
