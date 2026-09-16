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
use local_contenttranslator\external\set_status;
use local_contenttranslator\source\registry;
use local_contenttranslator\table\items_table;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/tablelib.php');

/**
 * Workbench logic behind the dashboard and editor pages: stale diff, accept suggestion / keep previous,
 * rollback, status actions with capabilities, dashboard filters, and the full "save → cron → learner sees
 * the translation" path through Moodle's formatting functions (UI-02, UI-06, UI-08, UI-09, #2374 AC).
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_contenttranslator\diff
 * @covers     \local_contenttranslator\external\set_status
 * @covers     \local_contenttranslator\table\items_table
 * @covers     \filter_contenttranslator\text_filter
 */
final class workbench_test extends \advanced_testcase {
    /**
     * Common setup: pseudo engine, German, generous budget, no debounce.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('targetlangs', 'de', 'local_contenttranslator');
        set_config('engine', 'pseudo', 'local_contenttranslator');
        set_config('budgetchars', 1000000, 'local_contenttranslator');
        // The site default is off; these tests exercise the automation, so switch it on explicitly.
        set_config('defaultcourseenabled', 1, 'local_contenttranslator');
        set_config('debounce', 0, 'local_contenttranslator');
        registry::reset();
        engine_manager::reset();
        cache_helper::purge();
    }

    /**
     * Clean session state.
     */
    protected function tearDown(): void {
        global $SESSION;
        unset($SESSION->forcelang);
        \filter_contenttranslator\text_filter::reset();
        parent::tearDown();
    }

    /**
     * Course with a page; returns [course, page, content item, module context].
     *
     * @param string $content
     * @return array
     */
    private function create_page(string $content = '<p>Hello <b>world</b></p>'): array {
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id, 'name' => 'Workbench page', 'content' => $content,
        ]);
        item_manager::sync_course((int)$course->id);
        $item = item_manager::find('mod_page', 'page', 'content', (int)$page->id);
        return [$course, $page, $item, \context_module::instance($page->cmid)];
    }

    /**
     * Change the page content without events and rescan.
     *
     * @param \stdClass $course
     * @param \stdClass $page
     * @param string $content
     */
    private function change_source(\stdClass $course, \stdClass $page, string $content): void {
        global $DB;
        $DB->set_field('page', 'content', $content, ['id' => $page->id]);
        item_manager::sync_course((int)$course->id);
    }

    /**
     * Stale translation: the editor diff shows what changed; accepting the suggestion makes it reviewed (UI-06).
     */
    public function test_stale_diff_and_accept_suggestion(): void {
        [$course, $page, $item, $context] = $this->create_page();
        $translation = translator::translate_item($item, 'de', budget::TRIGGER_BULK, 2);
        $translation = translation_manager::save_human($translation, '<p>Hallo <b>Welt</b></p>', FORMAT_HTML, 2, true);

        $this->change_source($course, $page, '<p>Hello <b>universe</b></p>');
        $item = item_manager::get_item((int)$item->id);
        $translation = translation_manager::get((int)$translation->id);
        $this->assertSame(translation_manager::STATUS_STALE, $translation->status);

        // The editor renders the diff between the snapshot the translation was made from and the current source.
        $diff = diff::render((string)$translation->sourcesnapshot, (string)$item->sourcetext, (int)$item->sourceformat);
        $this->assertStringContainsString('Hello', $diff);
        $this->assertMatchesRegularExpression('~<del[^>]*>world</del>~', $diff);
        $this->assertMatchesRegularExpression('~<ins[^>]*>universe</ins>~', $diff);

        // The pipeline stores a suggestion next to the reviewed text; the reviewer accepts it.
        $translation = translator::translate_item($item, 'de', budget::TRIGGER_BULK, 2);
        $this->assertSame('<p>Hallo <b>Welt</b></p>', $translation->text);
        $this->assertNotNull($translation->suggestion);
        set_status::apply($translation, 'acceptsuggestion', 2, 0, $context);

        $translation = translation_manager::get((int)$translation->id);
        $this->assertSame(translation_manager::STATUS_REVIEWED, $translation->status);
        $this->assertStringContainsString('universe', $translation->text);
        $this->assertSame($item->sourcehash, $translation->sourcehash);
        $this->assertNull($translation->suggestion);
        $reasons = array_map(fn($h) => $h->reason, translation_manager::get_history((int)$translation->id));
        $this->assertContains('suggestion', $reasons, 'The replaced human text is in the history');
    }

    /**
     * An approved machine translation (reviewed without edits) is not overwritten when the source changes:
     * the new machine text arrives as a suggestion, like for human translations (UI-06).
     */
    public function test_reviewed_machine_translation_gets_a_suggestion(): void {
        [$course, $page, $item, $context] = $this->create_page();
        $translation = translator::translate_item($item, 'de', budget::TRIGGER_BULK, 2);
        set_status::apply($translation, 'review', 2, 0, $context);
        $approved = translation_manager::get((int)$translation->id);
        $this->assertSame(translation_manager::STATUS_REVIEWED, $approved->status);
        $this->assertSame(translation_manager::ORIGIN_MACHINE, $approved->origin, 'Approved as is');

        $this->change_source($course, $page, '<p>Hello <b>universe</b></p>');
        $item = item_manager::get_item((int)$item->id);
        $translation = translator::translate_item($item, 'de', budget::TRIGGER_BULK, 2);
        $this->assertSame($approved->text, $translation->text, 'The approved text stays until a reviewer decides');
        $this->assertNotNull($translation->suggestion, 'The new machine text is a suggestion');
        $this->assertSame(translation_manager::STATUS_STALE, $translation->status);
        $this->assertGreaterThan(0, (int)$translation->reviewerid);
    }

    /**
     * Stale translation: "keep previous & mark reviewed" keeps the text and clears the stale state (UI-06).
     */
    public function test_keep_previous(): void {
        [$course, $page, $item, $context] = $this->create_page();
        $translation = translation_manager::save_human(
            translator::translate_item($item, 'de', budget::TRIGGER_BULK, 2),
            '<p>Hallo Welt</p>',
            FORMAT_HTML,
            2,
            true
        );
        $this->change_source($course, $page, '<p>Hello world!</p>');
        $translation = translation_manager::get((int)$translation->id);
        $this->assertSame(translation_manager::STATUS_STALE, $translation->status);

        set_status::apply($translation, 'keepprevious', 2, 0, $context);
        $translation = translation_manager::get((int)$translation->id);
        $this->assertSame(translation_manager::STATUS_REVIEWED, $translation->status);
        $this->assertSame('<p>Hallo Welt</p>', $translation->text);
        $this->assertSame(item_manager::get_item((int)$item->id)->sourcehash, $translation->sourcehash);
        $pendingids = array_map(fn($t) => (int)$t->id, item_manager::get_pending((int)$course->id, 'de'));
        $this->assertNotContains((int)$translation->id, $pendingids, 'Nothing left to re-translate');
    }

    /**
     * Diff output is escaped, empty for identical texts and refuses huge inputs.
     */
    public function test_diff_rendering(): void {
        $this->assertStringNotContainsString('<del', diff::render('<p>Same text</p>', '<p>Same  text</p>'));
        $diff = diff::render('Fish & chips', 'Fish & <b>fries</b>', FORMAT_HTML);
        $this->assertStringContainsString('&amp;', $diff);
        $this->assertStringNotContainsString('<b>', $diff, 'Markup is stripped, only text is compared');
        $this->assertMatchesRegularExpression('~<ins[^>]*>fries</ins>~', $diff);
        $this->assertNull(diff::render(str_repeat('word ', 2600), 'word'), 'Too long for an inline diff');
    }

    /**
     * Rollback via the web service restores a history version and cannot use another translation's history (UI-09).
     */
    public function test_rollback(): void {
        global $DB;
        [$course, , $item] = $this->create_page();
        $translation = translator::translate_item($item, 'de', budget::TRIGGER_BULK, 2);
        $machinetext = $translation->text;
        $translation = translation_manager::save_human($translation, '<p>Erste Fassung</p>', FORMAT_HTML, 2, false);
        translation_manager::save_human($translation, '<p>Zweite Fassung</p>', FORMAT_HTML, 2, false);

        $history = translation_manager::get_history((int)$translation->id);
        $machineentry = null;
        foreach ($history as $entry) {
            if ($entry->text === $machinetext) {
                $machineentry = $entry;
            }
        }
        $this->assertNotNull($machineentry);
        $result = set_status::execute((int)$translation->id, 'rollback', (int)$machineentry->id);
        $this->assertSame(translation_manager::STATUS_MACHINE, $result['status']);
        $this->assertSame($machinetext, translation_manager::get((int)$translation->id)->text);
        $this->assertCount(count($history) + 1, translation_manager::get_history((int)$translation->id));

        // History of another translation cannot be rolled in.
        $other = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'content' => '<p>Other</p>']);
        $otheritem = item_manager::find('mod_page', 'page', 'content', (int)$other->id);
        $othertr = translation_manager::save_human(
            translator::translate_item($otheritem, 'de', budget::TRIGGER_BULK, 2),
            '<p>Andere</p>',
            FORMAT_HTML,
            2,
            false
        );
        $foreign = $DB->get_records('local_contenttranslator_hist', ['translationid' => $othertr->id]);
        $this->expectException(\dml_missing_record_exception::class);
        set_status::execute((int)$translation->id, 'rollback', (int)reset($foreign)->id);
    }

    /**
     * Status actions need the translate / review capabilities in the item's context (UI-08, #2381).
     */
    public function test_actions_and_capabilities(): void {
        [$course, , $item, $context] = $this->create_page();
        $translation = translator::translate_item($item, 'de', budget::TRIGGER_BULK, 2);

        $editingteacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'teacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $this->setUser($editingteacher);
        $this->assertSame('machine', set_status::execute((int)$translation->id, 'lock')['status']);
        $this->assertSame(1, (int)translation_manager::get((int)$translation->id)->locked);
        set_status::execute((int)$translation->id, 'unlock');
        $this->assertSame(0, (int)translation_manager::get((int)$translation->id)->locked);
        $this->assertSame(translation_manager::STATUS_QUEUED, set_status::execute((int)$translation->id, 'requeue')['status']);
        translator::translate_item($item, 'de', budget::TRIGGER_BULK, 2);
        $this->assertSame(translation_manager::STATUS_REVIEWED, set_status::execute((int)$translation->id, 'review')['status']);
        $this->assertSame(
            translation_manager::STATUS_STALE,
            set_status::execute((int)$translation->id, 'requeue')['status'],
            'Reviewed text is not queued for overwrite; a fresh suggestion is requested instead'
        );

        foreach ([$teacher, $student] as $user) {
            $this->setUser($user);
            foreach (['review', 'lock', 'delete', 'requeue', 'rollback'] as $action) {
                try {
                    set_status::apply(translation_manager::get((int)$translation->id), $action, (int)$user->id, 0, $context);
                    $this->fail("$action must need a capability");
                } catch (\required_capability_exception $e) {
                    $this->assertSame('nopermissions', $e->errorcode);
                }
            }
        }

        $this->setUser($editingteacher);
        $this->expectException(\invalid_parameter_exception::class);
        set_status::execute((int)$translation->id, 'publish');
    }

    /**
     * Dashboard filters: status per language, missing, locked, suggestion, full-text search, content type (UI-02).
     */
    public function test_dashboard_filters(): void {
        [$course, $page, $content] = $this->create_page('<p>Filter body</p>');
        $name = item_manager::find('mod_page', 'page', 'name', (int)$page->id);
        $fullname = item_manager::find('core_course', 'course', 'fullname', (int)$course->id);
        $book = $this->getDataGenerator()->create_module('book', ['course' => $course->id, 'name' => 'Filter book']);
        $chapter = $this->getDataGenerator()->get_plugin_generator('mod_book')->create_chapter([
            'bookid' => $book->id, 'title' => 'Filter chapter', 'content' => '<p>Chapter body</p>',
        ]);
        item_manager::sync_course((int)$course->id);
        $chaptertitle = item_manager::find('mod_book', 'book_chapters', 'title', (int)$chapter->id);

        // Content: machine; page name: reviewed + locked; course name: failed; chapter title: stale with suggestion.
        translator::translate_item($content, 'de', budget::TRIGGER_BULK, 2);
        $nametr = translator::translate_item($name, 'de', budget::TRIGGER_BULK, 2);
        translation_manager::save_human($nametr, 'Werkbankseite', FORMAT_PLAIN, 2, true);
        translation_manager::set_locked(translation_manager::get((int)$nametr->id), true, 2);
        translation_manager::mark_failed(translation_manager::ensure((int)$fullname->id, 'de'), 'boom', 2);
        $chaptertr = translator::translate_item($chaptertitle, 'de', budget::TRIGGER_BULK, 2);
        translation_manager::save_human($chaptertr, 'Filterkapitel', FORMAT_PLAIN, 2, true);
        global $DB;
        $DB->set_field('book_chapters', 'title', 'Filter chapter two', ['id' => $chapter->id]);
        item_manager::sync_course((int)$course->id);
        translator::translate_item(item_manager::get_item((int)$chaptertitle->id), 'de', budget::TRIGGER_BULK, 2);

        $rows = function (array $filters) use ($course): array {
            $table = new items_table('ct_test_' . random_string(), (int)$course->id, ['de'], $filters, new \moodle_url('/'));
            $table->define_baseurl(new \moodle_url('/local/contenttranslator/index.php'));
            $table->setup();
            $table->query_db(1000, false);
            $ids = array_map('intval', array_keys($table->rawdata));
            sort($ids);
            return $ids;
        };
        $ids = function (\stdClass ...$items): array {
            $ids = array_map(fn($i) => (int)$i->id, $items);
            sort($ids);
            return $ids;
        };

        $this->assertSame($ids($content), $rows(['status' => 'machine', 'lang' => 'de']));
        $this->assertSame($ids($name), $rows(['status' => 'reviewed', 'lang' => 'de']));
        $this->assertSame($ids($name), $rows(['status' => 'locked', 'lang' => 'de']));
        $this->assertSame($ids($fullname), $rows(['status' => 'failed', 'lang' => 'de']));
        $this->assertSame($ids($chaptertitle), $rows(['status' => 'stale', 'lang' => 'de']));
        $this->assertSame($ids($chaptertitle), $rows(['status' => 'suggestion', 'lang' => 'de']));
        $this->assertNotContains((int)$content->id, $rows(['status' => 'missing', 'lang' => 'de']));
        $this->assertContains(
            (int)item_manager::find('mod_book', 'book_chapters', 'content', (int)$chapter->id)->id,
            $rows(['status' => 'missing', 'lang' => 'de'])
        );
        $this->assertSame($ids($name), $rows(['search' => 'werkbank']), 'Search finds translated text');
        $this->assertContains((int)$content->id, $rows(['search' => 'FILTER BODY']), 'Search in source, case-insensitive');
        $this->assertSame(
            $ids(...array_values(array_filter(
                [$chaptertitle, item_manager::find('mod_book', 'book_chapters', 'content', (int)$chapter->id)]
            ))),
            $rows(['itemtype' => 'book_chapters'])
        );
    }

    /**
     * Acceptance path of #2374: editing an activity triggers translation after cron, and a German user sees it
     * through the same format_text() / format_string() calls the course page uses; English users see the source.
     */
    public function test_learner_sees_translation_after_cron(): void {
        global $CFG, $DB, $SESSION;
        filter_set_global_state('contenttranslator', TEXTFILTER_ON);
        filter_set_applies_to_strings('contenttranslator', true); // Filters > Apply to: "Content and headings".
        set_config('filterall', 1);
        $CFG->filterall = 1;
        \filter_manager::reset_caches();

        $course = $this->getDataGenerator()->create_course(['fullname' => 'Baking course']);
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id, 'name' => 'Bread basics', 'content' => '<p>Knead the dough for ten minutes.</p>',
            'contentformat' => FORMAT_HTML,
        ]);
        $this->expectOutputRegex('~translated~');
        $this->runAdhocTasks(task\translate_task::class);

        $context = \context_module::instance($page->cmid);
        $cm = get_fast_modinfo($course)->get_cm($page->cmid);
        $render = function (string $lang) use ($SESSION, $DB, $page, $context, $cm, $course): array {
            $SESSION->forcelang = $lang;
            \filter_manager::reset_caches();
            \filter_contenttranslator\text_filter::reset();
            $record = $DB->get_record('page', ['id' => $page->id]);
            return [
                'content' => format_text($record->content, $record->contentformat, ['context' => $context]),
                'name' => $cm->get_formatted_name(),
                'course' => format_string($course->fullname, true, ['context' => \context_course::instance($course->id)]),
            ];
        };

        $de = $render('de');
        $this->assertStringContainsString('[de] Knead the dough', $de['content']);
        $this->assertStringContainsString('lang="de"', $de['content']);
        $this->assertSame('[de] Bread basics', $de['name']);
        $this->assertSame('[de] Baking course', $de['course']);

        $en = $render('en');
        $this->assertStringNotContainsString('[de]', $en['content']);
        $this->assertSame('Bread basics', $en['name']);

        // The teacher edits the page (event path): after the next cron the new text is translated.
        $DB->set_field('page', 'content', '<p>Knead the dough for twelve minutes.</p>', ['id' => $page->id]);
        \core\event\course_module_updated::create_from_cm($cm, $context)->trigger();
        $this->runAdhocTasks(task\translate_task::class);
        $this->assertStringContainsString('[de] Knead the dough for twelve minutes.', $render('de')['content']);
    }
}
