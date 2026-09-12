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

/**
 * Render cache helpers.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class cache_helper {
    /**
     * Cache key for a lookup.
     *
     * @param string $hash
     * @param string $lang
     * @param string $tenantkey
     * @return string
     */
    public static function key(string $hash, string $lang, string $tenantkey): string {
        return $hash . '_' . str_replace(':', '', $tenantkey) . '_' . $lang;
    }

    /**
     * The render cache.
     *
     * @return \cache
     */
    public static function cache(): \cache {
        return \cache::make('local_contenttranslator', 'translations');
    }

    /**
     * Drop cached lookups for a source hash in every installed language.
     *
     * @param string $hash
     * @param int $courseid
     * @param int $categoryid
     */
    public static function invalidate(string $hash, int $courseid = 0, int $categoryid = 0): void {
        $tenantkeys = ['site', tenant::key($courseid, $categoryid)];
        $keys = [];
        foreach (self::all_langs() as $lang) {
            foreach (array_unique($tenantkeys) as $tenantkey) {
                $keys[] = self::key($hash, $lang, $tenantkey);
            }
        }
        self::cache()->delete_many($keys);
    }

    /**
     * Installed languages plus every language a translation exists for.
     *
     * @return string[]
     */
    public static function all_langs(): array {
        global $DB;
        $langs = array_keys(get_string_manager()->get_list_of_translations(true));
        $langs = array_merge($langs, config::get_site_target_langs());
        $langs = array_merge($langs, $DB->get_fieldset_sql('SELECT DISTINCT targetlang FROM {local_contenttranslator_tr}'));
        return array_values(array_unique($langs));
    }

    /**
     * Drop everything.
     */
    public static function purge(): void {
        self::cache()->purge();
    }
}
