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
use local_contenttranslator\source\subtable_map;

/**
 * Content coverage: every M1 content type is registered, translated and found again at render time
 * in its own context (#2377, #2379 WB-02).
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_contenttranslator\source\section_source
 * @covers     \local_contenttranslator\source\category_source
 * @covers     \local_contenttranslator\source\module_source
 * @covers     \local_contenttranslator\source\subtable_source
 * @covers     \local_contenttranslator\source\subtable_map
 */
final class coverage_test extends \advanced_testcase {
    /**
     * Common setup: pseudo engine, German, no automatic jobs (items are translated explicitly).
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('targetlangs', 'de', 'local_contenttranslator');
        set_config('engine', 'pseudo', 'local_contenttranslator');
        set_config('budgetchars', 1000000, 'local_contenttranslator');
        set_config('enableauto', 0, 'local_contenttranslator');
        registry::reset();
        engine_manager::reset();
        subtable_map::reset();
        cache_helper::purge();
    }

    /**
     * Assert that a field is registered with the given source text, translates, and is found by the render lookup.
     *
     * @param string $component
     * @param string $itemtype
     * @param string $field
     * @param int $itemid
     * @param string $rendered Text as it reaches the filter.
     * @param \context $context Render context.
     */
    private function assert_translatable(
        string $component,
        string $itemtype,
        string $field,
        int $itemid,
        string $rendered,
        \context $context
    ): void {
        $label = "$component/$itemtype/$field";
        $item = item_manager::find($component, $itemtype, $field, $itemid);
        $this->assertNotNull($item, "$label is registered");
        $this->assertSame((int)$context->id, (int)$item->contextid, "$label lives in its render context");
        $translation = translator::translate_item($item, 'de', budget::TRIGGER_BULK, 2);
        $this->assertSame(translation_manager::STATUS_MACHINE, $translation->status, $label);
        $result = api::lookup($rendered, 'de', $context);
        $this->assertNotNull($result, "$label is known at render time");
        $this->assertTrue($result['found'], "$label translation is shown");
        $this->assertStringContainsString('[de]', $result['text'], $label);
    }

    /**
     * Course sections (name, summary), also via the event path when edited.
     */
    public function test_sections(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course(['numsections' => 1], ['createsections' => true]);
        $section = $DB->get_record('course_sections', ['course' => $course->id, 'section' => 1], '*', MUST_EXIST);
        course_update_section($course, $section, ['name' => 'Week one', 'summary' => '<p>What we cook this week</p>']);

        $context = \context_course::instance($course->id);
        $this->assert_translatable('core_course', 'course_sections', 'name', (int)$section->id, 'Week one', $context);
        $this->assert_translatable(
            'core_course',
            'course_sections',
            'summary',
            (int)$section->id,
            '<p>What we cook this week</p>',
            $context
        );

        course_update_section($course, $section, ['summary' => '<p>What we bake this week</p>']);
        $item = item_manager::find('core_course', 'course_sections', 'summary', (int)$section->id);
        $this->assertSame(normaliser::hash('<p>What we bake this week</p>'), $item->sourcehash, 'Event path updates the hash');
        $this->assertSame(translation_manager::STATUS_STALE, translation_manager::get_for_item((int)$item->id, 'de')->status);
    }

    /**
     * Course categories (name, description) are site level content in the category context.
     */
    public function test_categories(): void {
        $category = $this->getDataGenerator()->create_category(['name' => 'Cooking', 'description' => '<p>Courses about food</p>']);
        item_manager::sync_site();
        $context = \context_coursecat::instance($category->id);
        $this->assert_translatable('core_course', 'course_categories', 'name', (int)$category->id, 'Cooking', $context);
        $this->assert_translatable(
            'core_course',
            'course_categories',
            'description',
            (int)$category->id,
            '<p>Courses about food</p>',
            $context
        );
    }

    /**
     * Course full name and summary; short name stays off by default, admins can exclude further fields.
     */
    public function test_course_and_exclusions(): void {
        $course = $this->getDataGenerator()->create_course([
            'fullname' => 'Italian cooking', 'shortname' => 'IT-COOK', 'summary' => '<p>Pasta and more</p>',
        ]);
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id, 'name' => 'Recipes', 'content' => '<p>Tomato sauce</p>',
        ]);
        item_manager::sync_course((int)$course->id);
        $context = \context_course::instance($course->id);
        $this->assert_translatable('core_course', 'course', 'fullname', (int)$course->id, 'Italian cooking', $context);
        $this->assert_translatable('core_course', 'course', 'summary', (int)$course->id, '<p>Pasta and more</p>', $context);
        $this->assertNull(
            item_manager::find('core_course', 'course', 'shortname', (int)$course->id),
            'Short name is off by default'
        );

        set_config('excludedfields', "page.name\ncourse.summary", 'local_contenttranslator');
        subtable_map::reset();
        registry::reset();
        item_manager::sync_course((int)$course->id);
        $this->assertNull(item_manager::find('mod_page', 'page', 'name', (int)$page->id));
        $this->assertNull(item_manager::find('core_course', 'course', 'summary', (int)$course->id));
        $this->assertNotNull(item_manager::find('mod_page', 'page', 'content', (int)$page->id));
    }

    /**
     * Generic activities: name, intro and auto-discovered text columns; learner content stays out.
     */
    public function test_generic_activities(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $label = $this->getDataGenerator()->create_module('label', [
            'course' => $course->id, 'intro' => '<p>Welcome to the kitchen</p>',
        ]);
        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id, 'name' => 'Bake a cake', 'intro' => '<p>Bake and upload a photo</p>',
            'activity' => '<p>Use at least three eggs</p>', 'activityformat' => FORMAT_HTML,
        ]);
        $forum = $this->getDataGenerator()->create_module('forum', [
            'course' => $course->id, 'name' => 'Questions', 'intro' => '<p>Ask here</p>',
        ]);
        // The assign generator ignores the activity instructions.
        $DB->set_field('assign', 'activity', '<p>Use at least three eggs</p>', ['id' => $assign->id]);
        $DB->set_field('assign', 'activityformat', FORMAT_HTML, ['id' => $assign->id]);
        item_manager::sync_course((int)$course->id);

        $labelcontext = \context_module::instance($label->cmid);
        $this->assert_translatable('mod_label', 'label', 'intro', (int)$label->id, '<p>Welcome to the kitchen</p>', $labelcontext);
        $assigncontext = \context_module::instance($assign->cmid);
        $this->assert_translatable('mod_assign', 'assign', 'name', (int)$assign->id, 'Bake a cake', $assigncontext);
        $assignid = (int)$assign->id;
        $this->assert_translatable('mod_assign', 'assign', 'intro', $assignid, '<p>Bake and upload a photo</p>', $assigncontext);
        $this->assert_translatable('mod_assign', 'assign', 'activity', $assignid, '<p>Use at least three eggs</p>', $assigncontext);
        $forumcontext = \context_module::instance($forum->cmid);
        $this->assert_translatable('mod_forum', 'forum', 'intro', (int)$forum->id, '<p>Ask here</p>', $forumcontext);
        $this->assertFalse(registry::get()->has_table('forum_posts'), 'Learner posts are out of scope');
    }

    /**
     * Sub-tables: book chapters, choice options, lesson pages, feedback items.
     */
    public function test_subtables(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();

        $book = $this->getDataGenerator()->create_module('book', ['course' => $course->id]);
        $chapter = $this->getDataGenerator()->get_plugin_generator('mod_book')->create_chapter([
            'bookid' => $book->id, 'title' => 'Knives', 'content' => '<p>Keep them sharp</p>',
        ]);
        $choice = $this->getDataGenerator()->create_module('choice', ['course' => $course->id, 'option' => ['Pizza', 'Pasta']]);
        $lesson = $this->getDataGenerator()->create_module('lesson', ['course' => $course->id]);
        $lessonpage = $this->getDataGenerator()->get_plugin_generator('mod_lesson')->create_content($lesson, [
            'title' => 'Mise en place',
            'contents_editor' => ['text' => '<p>Prepare everything first</p>', 'format' => FORMAT_HTML, 'itemid' => 0],
        ]);
        $feedback = $this->getDataGenerator()->create_module('feedback', ['course' => $course->id]);
        $feedbackitem = $this->getDataGenerator()->get_plugin_generator('mod_feedback')->create_item_multichoice($feedback, [
            'name' => 'How did it taste?', 'label' => 'taste',
        ]);
        item_manager::sync_course((int)$course->id);

        $bookcontext = \context_module::instance($book->cmid);
        $this->assert_translatable('mod_book', 'book_chapters', 'title', (int)$chapter->id, 'Knives', $bookcontext);
        $chapterid = (int)$chapter->id;
        $this->assert_translatable('mod_book', 'book_chapters', 'content', $chapterid, '<p>Keep them sharp</p>', $bookcontext);

        $options = array_filter($DB->get_records('choice_options', ['choiceid' => $choice->id]), fn($o) => $o->text === 'Pasta');
        $option = reset($options);
        $choicecontext = \context_module::instance($choice->cmid);
        $this->assert_translatable('mod_choice', 'choice_options', 'text', (int)$option->id, 'Pasta', $choicecontext);

        $lessoncontext = \context_module::instance($lesson->cmid);
        $this->assert_translatable('mod_lesson', 'lesson_pages', 'title', (int)$lessonpage->id, 'Mise en place', $lessoncontext);
        $this->assert_translatable(
            'mod_lesson',
            'lesson_pages',
            'contents',
            (int)$lessonpage->id,
            '<p>Prepare everything first</p>',
            $lessoncontext
        );

        $this->assert_translatable(
            'mod_feedback',
            'feedback_item',
            'name',
            (int)$feedbackitem->id,
            'How did it taste?',
            \context_module::instance($feedback->cmid)
        );
    }

    /**
     * mod_booking options (WB-02), when mod_booking is installed.
     */
    public function test_booking_options(): void {
        global $DB;
        if (!\core_component::get_component_directory('mod_booking')) {
            $this->markTestSkipped('mod_booking is not installed');
        }
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', ['course' => $course->id, 'name' => 'Cooking classes']);
        $option = $this->getDataGenerator()->get_plugin_generator('mod_booking')->create_option((object)[
            'bookingid' => $booking->id,
            'text' => 'Sushi workshop',
            'description' => '<p>Learn to roll maki</p>',
            'location' => 'Main kitchen',
            'coursestarttime' => strtotime('tomorrow 10:00'),
            'courseendtime' => strtotime('tomorrow 12:00'),
        ]);
        // The booking generator does not store the location on the option row.
        $DB->set_field('booking_options', 'location', 'Main kitchen', ['id' => $option->id]);
        item_manager::sync_course((int)$course->id);

        $context = \context_module::instance($booking->cmid);
        $this->assert_translatable('mod_booking', 'booking_options', 'text', (int)$option->id, 'Sushi workshop', $context);
        $optionid = (int)$option->id;
        $description = '<p>Learn to roll maki</p>';
        $this->assert_translatable('mod_booking', 'booking_options', 'description', $optionid, $description, $context);
        $this->assert_translatable('mod_booking', 'booking_options', 'location', (int)$option->id, 'Main kitchen', $context);
        $this->assert_translatable('mod_booking', 'booking', 'name', (int)$booking->id, 'Cooking classes', $context);

        // Plugins without filters (e-mails, tables) use the API by identity.
        $this->assertStringContainsString(
            '[de]',
            api::translate_field('mod_booking', 'booking_options', 'text', (int)$option->id, 'Sushi workshop', 'de')
        );
        $this->assertSame(
            'Sushi workshop',
            api::translate_field('mod_booking', 'booking_options', 'text', (int)$option->id, 'Sushi workshop', 'en')
        );

        // Configuration columns of the booking instance (comma separated field lists) are not content.
        foreach (['responsesfields', 'reportfields', 'optionsfields', 'optionsdownloadfields', 'signinsheetfields'] as $column) {
            $this->assertNull(
                item_manager::find('mod_booking', 'booking', $column, (int)$booking->id),
                "booking.$column is a settings list and must not be sent to an engine"
            );
        }
    }

    /**
     * Auto-discovery skips activity columns that hold settings instead of content (found on a live site).
     */
    public function test_non_content_columns_are_skipped(): void {
        $this->resetAfterTest();
        $settings = [['lesson', 'conditions'], ['lti', 'secureicon'], ['booking', 'banusernames'], ['booking', 'categoryid']];
        foreach ($settings as [$table, $column]) {
            $this->assertTrue(subtable_map::is_skipped_column($table, $column), "$table.$column is not content");
        }
        $content = [['lesson', 'name'], ['lti', 'name'], ['booking', 'intro'], ['booking_options', 'location']];
        foreach ($content as [$table, $column]) {
            $this->assertFalse(subtable_map::is_skipped_column($table, $column), "$table.$column is content");
        }
    }
}
