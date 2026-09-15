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

use local_contenttranslator\engine\engine_manager;
use local_contenttranslator\source\registry;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * Backup and restore of translations.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \backup_local_contenttranslator_plugin
 * @covers     \restore_local_contenttranslator_plugin
 */
final class backup_restore_test extends \advanced_testcase {
    /**
     * Translations of course, section and module content survive a course backup and restore.
     */
    public function test_backup_restore_course(): void {
        global $CFG, $USER, $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('targetlangs', 'de', 'local_contenttranslator');
        set_config('engine', 'pseudo', 'local_contenttranslator');
        set_config('budgetchars', 1000000, 'local_contenttranslator');
        registry::reset();
        engine_manager::reset();
        $CFG->backup_file_logger_level = \backup::LOG_NONE;

        $course = $this->getDataGenerator()->create_course(['fullname' => 'Backup me', 'summary' => '<p>Summary text</p>']);
        $page = $this->getDataGenerator()->create_module(
            'page',
            ['course' => $course->id, 'name' => 'Page one', 'content' => '<p>Page body</p>']
        );
        $book = $this->getDataGenerator()->create_module('book', ['course' => $course->id, 'name' => 'Book one']);
        $chapter = $this->getDataGenerator()->get_plugin_generator('mod_book')->create_chapter([
            'bookid' => $book->id, 'title' => 'Chapter one', 'content' => '<p>Chapter body</p>',
        ]);
        item_manager::sync_course((int)$course->id);
        foreach ($DB->get_records('local_contenttranslator_item', ['courseid' => $course->id]) as $item) {
            translator::translate_item($item, 'de', budget::TRIGGER_BULK, (int)$USER->id);
        }
        $pageitem = item_manager::find('mod_page', 'page', 'content', (int)$page->id);
        $pagetranslation = translation_manager::save_human(
            translation_manager::get_for_item((int)$pageitem->id, 'de'),
            '<p>Seiteninhalt</p>',
            FORMAT_HTML,
            (int)$USER->id,
            true
        );
        $chapteritem = item_manager::find('mod_book', 'book_chapters', 'content', (int)$chapter->id);
        $this->assertNotNull($chapteritem);

        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id
        );
        $bc->execute_plan();
        $results = $bc->get_results();
        $file = $results['backup_destination'];
        $backupid = 'local_contenttranslator_test_restore';
        $file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), make_backup_temp_directory($backupid));
        $bc->destroy();

        $newcourseid = \restore_dbops::create_new_course('Restored', 'restored', $course->category);
        $rc = new \restore_controller(
            $backupid,
            $newcourseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_NEW_COURSE
        );
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();

        $newpage = $DB->get_record('page', ['course' => $newcourseid], '*', MUST_EXIST);
        $this->assertNotEquals($page->id, $newpage->id);
        $newitem = item_manager::find('mod_page', 'page', 'content', (int)$newpage->id);
        $this->assertNotNull($newitem, 'Module level item restored with the new instance id');
        $this->assertEquals($newcourseid, $newitem->courseid);
        $this->assertEquals(
            \context_module::instance($newpage->cmid ?? get_coursemodule_from_instance('page', $newpage->id)->id)->id,
            $newitem->contextid
        );
        $newtranslation = translation_manager::get_for_item((int)$newitem->id, 'de');
        $this->assertSame('<p>Seiteninhalt</p>', $newtranslation->text);
        $this->assertSame(translation_manager::STATUS_REVIEWED, $newtranslation->status);

        $newcourseitem = item_manager::find('core_course', 'course', 'summary', $newcourseid);
        $this->assertNotNull($newcourseitem, 'Course level item restored');
        $this->assertNotNull(translation_manager::get_for_item((int)$newcourseitem->id, 'de'));

        $newchapter = $DB->get_record_sql(
            "SELECT c.* FROM {book_chapters} c JOIN {book} b ON b.id = c.bookid WHERE b.course = :c",
            ['c' => $newcourseid],
            MUST_EXIST
        );
        $newchapteritem = item_manager::find('mod_book', 'book_chapters', 'content', (int)$newchapter->id);
        $this->assertNotNull($newchapteritem, 'Sub-table item remapped through the restore mapping');
        $this->assertStringContainsString('[de]', translation_manager::get_for_item((int)$newchapteritem->id, 'de')->text);

        // The original course is untouched.
        $this->assertSame('<p>Seiteninhalt</p>', translation_manager::get((int)$pagetranslation->id)->text);
    }

    /**
     * Course with a page whose content has a reviewed human translation; returns [course, page, translation].
     *
     * @return array
     */
    private function create_translated_course(): array {
        global $CFG, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('targetlangs', 'de', 'local_contenttranslator');
        set_config('engine', 'pseudo', 'local_contenttranslator');
        set_config('budgetchars', 1000000, 'local_contenttranslator');
        set_config('enableauto', 0, 'local_contenttranslator');
        registry::reset();
        engine_manager::reset();
        $CFG->backup_file_logger_level = \backup::LOG_NONE;

        $course = $this->getDataGenerator()->create_course(['fullname' => 'Copy me']);
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id, 'name' => 'Page', 'content' => '<p>Copy body</p>',
        ]);
        item_manager::sync_course((int)$course->id);
        $item = item_manager::find('mod_page', 'page', 'content', (int)$page->id);
        $translation = translator::translate_item($item, 'de', budget::TRIGGER_BULK, (int)$USER->id);
        $translation = translation_manager::save_human($translation, '<p>Kopierter Inhalt</p>', FORMAT_HTML, (int)$USER->id, true);
        return [$course, $page, $translation];
    }

    /**
     * Activity duplication copies translations; the copy is independent afterwards (BK-03).
     */
    public function test_duplicate_activity(): void {
        global $USER;
        [$course, $page, $translation] = $this->create_translated_course();

        $newcm = duplicate_module($course, get_fast_modinfo($course)->get_cm($page->cmid));
        $newitem = item_manager::find('mod_page', 'page', 'content', (int)$newcm->instance);
        $this->assertNotNull($newitem, 'Duplicated activity has its item');
        $this->assertEquals(\context_module::instance($newcm->id)->id, $newitem->contextid);
        $newtranslation = translation_manager::get_for_item((int)$newitem->id, 'de');
        $this->assertNotNull($newtranslation, 'Duplicated activity keeps its translation');
        $this->assertSame('<p>Kopierter Inhalt</p>', $newtranslation->text);
        $this->assertSame(translation_manager::STATUS_REVIEWED, $newtranslation->status);
        $this->assertNotEquals($translation->id, $newtranslation->id);

        translation_manager::save_human($newtranslation, '<p>Nur in der Kopie</p>', FORMAT_HTML, (int)$USER->id, true);
        $this->assertSame('<p>Kopierter Inhalt</p>', translation_manager::get((int)$translation->id)->text, 'Independent copies');
    }

    /**
     * Course copy keeps translations (BK-03).
     */
    public function test_course_copy(): void {
        global $DB;
        [$course, , ] = $this->create_translated_course();

        $formdata = (object)[
            'courseid' => $course->id, 'fullname' => 'Copied course', 'shortname' => 'copied', 'category' => $course->category,
            'visible' => 1, 'startdate' => time(), 'enddate' => 0, 'idnumber' => '', 'userdata' => 0,
        ];
        \copy_helper::create_copy(\copy_helper::process_formdata($formdata));
        ob_start();
        $this->runAdhocTasks(\core\task\asynchronous_copy_task::class);
        ob_end_clean();

        $newcourse = $DB->get_record('course', ['shortname' => 'copied'], '*', MUST_EXIST);
        $newpage = $DB->get_record('page', ['course' => $newcourse->id], '*', MUST_EXIST);
        $newitem = item_manager::find('mod_page', 'page', 'content', (int)$newpage->id);
        $this->assertNotNull($newitem, 'Copied course has the page item');
        $this->assertEquals($newcourse->id, $newitem->courseid);
        $newtranslation = translation_manager::get_for_item((int)$newitem->id, 'de');
        $this->assertNotNull($newtranslation, 'Copied course keeps the translation');
        $this->assertSame('<p>Kopierter Inhalt</p>', $newtranslation->text);
        $this->assertSame(translation_manager::STATUS_REVIEWED, $newtranslation->status);
    }
}
