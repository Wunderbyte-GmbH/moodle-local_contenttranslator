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

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

use local_contenttranslator\budget;
use local_contenttranslator\item_manager;
use local_contenttranslator\queue;
use local_contenttranslator\translation_manager;
use local_contenttranslator\translator;

/**
 * Behat steps for the content translator: test language packs and deterministic translations (pseudo engine).
 *
 * @package    local_contenttranslator
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_local_contenttranslator extends behat_base {
    /**
     * Install a minimal language pack so that users can use that language (all strings fall back to English).
     *
     * @Given /^the content translator test language "(?P<lang_string>[a-z_]+)" is installed$/
     * @param string $lang
     */
    public function the_content_translator_test_language_is_installed(string $lang): void {
        global $CFG;
        $dir = $CFG->dataroot . '/lang/' . $lang;
        check_dir_exists($dir);
        file_put_contents(
            $dir . '/langconfig.php',
            "<?php\n\$string['thislanguage'] = " . var_export('Test ' . $lang, true) . ";\n\$string['parentlanguage'] = '';\n"
        );
        get_string_manager()->reset_caches();
    }

    /**
     * Scan a course and translate everything that is pending into a language, like a finished cron run.
     *
     * @Given /^the content translator has translated course "(?P<shortname_string>(?:[^"]|\\")*)" into "(?P<lang_string>[a-z_]+)"$/
     * @param string $shortname
     * @param string $lang
     */
    public function the_content_translator_has_translated_course_into(string $shortname, string $lang): void {
        global $DB;
        $courseid = (int)$DB->get_field('course', 'id', ['shortname' => $shortname], MUST_EXIST);
        item_manager::sync_course($courseid);
        queue::ensure_course_translations($courseid, [$lang], true);
        foreach (item_manager::get_pending($courseid, $lang) as $translation) {
            $item = item_manager::get_item((int)$translation->itemid);
            if ($item) {
                translator::translate_item($item, $lang, budget::TRIGGER_BULK, (int)get_admin()->id);
            }
        }
    }

    /**
     * Mark every translation of a course as reviewed.
     *
     * @Given /^the content translator translations of course "(?P<shortname_string>(?:[^"]|\\")*)" are reviewed$/
     * @param string $shortname
     */
    public function the_content_translator_translations_of_course_are_reviewed(string $shortname): void {
        global $DB;
        $courseid = (int)$DB->get_field('course', 'id', ['shortname' => $shortname], MUST_EXIST);
        $translations = $DB->get_records_sql(
            "SELECT t.* FROM {local_contenttranslator_tr} t
               JOIN {local_contenttranslator_item} i ON i.id = t.itemid
              WHERE i.courseid = :courseid",
            ['courseid' => $courseid]
        );
        foreach ($translations as $translation) {
            translation_manager::mark_reviewed($translation, (int)get_admin()->id);
        }
    }
}
