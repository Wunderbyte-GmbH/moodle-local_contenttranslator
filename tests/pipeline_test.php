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

/**
 * Integration tests for registry, pipeline, translation memory, status lifecycle and lookups.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_contenttranslator\translator
 * @covers     \local_contenttranslator\item_manager
 * @covers     \local_contenttranslator\translation_manager
 * @covers     \local_contenttranslator\api
 * @covers     \local_contenttranslator\queue
 */
final class pipeline_test extends \advanced_testcase {
    /**
     * Common setup: pseudo engine, German as target language, generous budget.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('targetlangs', 'de,fr', 'local_contenttranslator');
        set_config('engine', 'pseudo', 'local_contenttranslator');
        set_config('budgetchars', 1000000, 'local_contenttranslator');
        // The site default is off; these tests exercise the automation, so switch it on explicitly.
        set_config('defaultcourseenabled', 1, 'local_contenttranslator');
        set_config('enableauto', 1, 'local_contenttranslator');
        set_config('debounce', 0, 'local_contenttranslator');
        registry::reset();
        engine_manager::reset();
        cache_helper::purge();
    }

    /**
     * Create a course with a page and return [course, page, pagecontentitem].
     *
     * @param string $content
     * @return array
     */
    private function create_course_with_page(string $content = '<p>Hello <b>world</b></p>'): array {
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Cooking basics', 'summary' => '<p>Learn to cook.</p>']);
        $page = $this->getDataGenerator()->create_module(
            'page',
            ['course' => $course->id, 'name' => 'Intro page', 'content' => $content]
        );
        item_manager::sync_course((int)$course->id);
        $item = item_manager::find('mod_page', 'page', 'content', (int)$page->id);
        return [$course, $page, $item];
    }

    /**
     * Scanning a course registers course, section and module fields.
     */
    public function test_sync_course_registers_items(): void {
        global $DB;
        [$course, $page, $item] = $this->create_course_with_page();
        $this->assertNotNull($item);
        $this->assertSame('en', $item->sourcelang);
        $this->assertSame(normaliser::hash('<p>Hello <b>world</b></p>'), $item->sourcehash);
        $this->assertEquals(11, $item->chars);
        $this->assertNotNull(item_manager::find('mod_page', 'page', 'name', (int)$page->id));
        $this->assertNotNull(item_manager::find('core_course', 'course', 'fullname', (int)$course->id));
        $this->assertNotNull(item_manager::find('core_course', 'course', 'summary', (int)$course->id));

        // Deleting the module removes its items.
        course_delete_module($page->cmid);
        $this->assertNull(item_manager::find('mod_page', 'page', 'content', (int)$page->id));
    }

    /**
     * Pseudo engine translation, then translation memory reuse for identical text.
     */
    public function test_translate_and_tm(): void {
        global $DB;
        [$course, $page, $item] = $this->create_course_with_page();
        $translation = translator::translate_item($item, 'de', budget::TRIGGER_BULK, 2);
        $this->assertSame(translation_manager::STATUS_MACHINE, $translation->status);
        $this->assertSame(translation_manager::ORIGIN_MACHINE, $translation->origin);
        $this->assertStringContainsString('[de]', $translation->text);
        $this->assertStringContainsString('<b>', $translation->text);
        $this->assertSame('pseudo', $translation->engine);
        $this->assertEquals(1, $DB->count_records('local_contenttranslator_use'));
        $this->assertEquals(11, budget::get_used());

        // Same text in another page: TM hit, no engine call, no budget.
        $page2 = $this->getDataGenerator()->create_module(
            'page',
            ['course' => $course->id, 'name' => 'Second', 'content' => '<p>Hello <b>world</b></p>']
        );
        item_manager::sync_course((int)$course->id);
        $item2 = item_manager::find('mod_page', 'page', 'content', (int)$page2->id);
        $translation2 = translator::translate_item($item2, 'de', budget::TRIGGER_BULK, 2);
        $this->assertSame(translation_manager::ORIGIN_TM, $translation2->origin);
        $this->assertSame($translation->text, $translation2->text);
        $this->assertEquals(1, $DB->count_records('local_contenttranslator_use'));
    }

    /**
     * The memory is keyed on the visible text, so a hit is only reused when the formatting matches too.
     */
    public function test_tm_hit_needs_same_markup(): void {
        global $DB;
        [$course, $page, $item] = $this->create_course_with_page('<p>Hello world</p>');
        translator::translate_item($item, 'de', budget::TRIGGER_BULK, 2);

        // Same words as a list: the memory hit would turn the list into a paragraph, so the engine translates it.
        $list = $this->getDataGenerator()->create_module(
            'page',
            ['course' => $course->id, 'name' => 'List', 'content' => '<ul><li>Hello world</li></ul>']
        );
        // Same words and same formatting: reused from the memory.
        $same = $this->getDataGenerator()->create_module(
            'page',
            ['course' => $course->id, 'name' => 'Same', 'content' => '<p>Hello world</p>']
        );
        item_manager::sync_course((int)$course->id);

        $listitem = item_manager::find('mod_page', 'page', 'content', (int)$list->id);
        $this->assertSame($item->sourcehash, $listitem->sourcehash, 'Both texts share the memory key');
        $translation = translator::translate_item($listitem, 'de', budget::TRIGGER_BULK, 2);
        $this->assertSame(translation_manager::ORIGIN_MACHINE, $translation->origin);
        $this->assertStringContainsString('<ul><li>', $translation->text);
        $this->assertStringNotContainsString('<p>', $translation->text);
        $this->assertEquals(2, $DB->count_records('local_contenttranslator_use'));

        $sameitem = item_manager::find('mod_page', 'page', 'content', (int)$same->id);
        $translation = translator::translate_item($sameitem, 'de', budget::TRIGGER_BULK, 2);
        $this->assertSame(translation_manager::ORIGIN_TM, $translation->origin);
        $this->assertEquals(2, $DB->count_records('local_contenttranslator_use'));
    }

    /**
     * Re-translating asks the engine again instead of returning the item's own translation from the memory,
     * so a changed style guide takes effect; other items with the same text still use the memory.
     */
    public function test_retranslate_skips_own_memory_entry(): void {
        global $DB;
        [$course, $page, $item] = $this->create_course_with_page();
        $translation = translator::translate_item($item, 'de', budget::TRIGGER_BULK, 2);
        $this->assertEquals(1, $DB->count_records('local_contenttranslator_use'));

        translation_manager::requeue($translation, 2);
        $translation = translator::translate_item($item, 'de', budget::TRIGGER_BULK, 2);
        $this->assertSame(translation_manager::ORIGIN_MACHINE, $translation->origin);
        $this->assertEquals(2, $DB->count_records('local_contenttranslator_use'), 'Re-translate calls the engine');

        api::translate_now((int)$item->id, 'de', 2);
        $this->assertEquals(3, $DB->count_records('local_contenttranslator_use'), 'Translate now calls the engine');

        $page2 = $this->getDataGenerator()->create_module(
            'page',
            ['course' => $course->id, 'name' => 'Second', 'content' => '<p>Hello <b>world</b></p>']
        );
        item_manager::sync_course((int)$course->id);
        $item2 = item_manager::find('mod_page', 'page', 'content', (int)$page2->id);
        $translation2 = translator::translate_item($item2, 'de', budget::TRIGGER_BULK, 2);
        $this->assertSame(translation_manager::ORIGIN_TM, $translation2->origin);
        $this->assertEquals(3, $DB->count_records('local_contenttranslator_use'));
    }

    /**
     * The next text does not fit into the rest of the budget: the task stops and admins learn about it once,
     * with a link to the dashboard, which then shows the pause.
     */
    public function test_budget_pause_is_notified(): void {
        global $DB;
        [$course, $page, $item] = $this->create_course_with_page('<p>Twelve chars</p>');
        set_config('budgetchars', 100, 'local_contenttranslator');
        budget::log_usage(['engine' => 'pseudo', 'sourcelang' => 'en', 'targetlang' => 'de', 'chars' => 95,
            'triggertype' => 'bulk']);
        $this->expectOutputRegex('~budget exhausted~');
        $sink = $this->redirectMessages();
        $sink->clear();
        $DB->delete_records_select('local_contenttranslator_tr', 'itemid <> :id', ['id' => $item->id]);
        translation_manager::ensure((int)$item->id, 'de');
        $this->assertFalse(budget::is_paused());

        $task = new task\translate_task();
        $task->set_custom_data(['courseid' => $course->id, 'lang' => 'de', 'trigger' => budget::TRIGGER_BULK]);
        $task->execute();
        $this->assertEquals(95, budget::get_used(), 'Nothing was spent');
        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('not enough', $messages[0]->subject);
        $this->assertStringContainsString('/local/contenttranslator/index.php', $messages[0]->contexturl);
        $this->assertTrue(budget::is_paused());

        $task->execute();
        $this->assertCount(1, $sink->get_messages(), 'Once per month');
        $sink->close();
    }

    /**
     * A failed attempt does not hide the text learners saw before: an earlier translation stays visible as stale
     * (current one as machine translation); a failed row without text stays invisible.
     */
    public function test_failed_attempt_keeps_earlier_text_visible(): void {
        global $DB;
        [$course, $page, $item] = $this->create_course_with_page('<p>Hello world</p>');
        $context = \context_module::instance($page->cmid);
        $translation = translator::translate_item($item, 'de', budget::TRIGGER_BULK, 2);

        // Re-translating a current machine translation fails: it stays visible.
        translation_manager::mark_failed($translation, 'engine down', 2);
        cache_helper::purge();
        $result = api::lookup('<p>Hello world</p>', 'de', $context);
        $this->assertTrue($result['found']);
        $this->assertSame(translation_manager::STATUS_MACHINE, $result['status']);
        $this->assertSame($translation->text, $result['text']);

        // The source changes and the next attempt fails: the earlier translation is shown as stale.
        $DB->set_field('page', 'content', '<p>Hello new world</p>', ['id' => $page->id]);
        item_manager::sync_course((int)$course->id);
        $translation = translation_manager::get_for_item((int)$item->id, 'de');
        translation_manager::mark_failed($translation, 'engine down', 2);
        $this->assertSame(translation_manager::STATUS_FAILED, translation_manager::get_for_item((int)$item->id, 'de')->status);
        cache_helper::purge();
        $result = api::lookup('<p>Hello new world</p>', 'de', $context);
        $this->assertTrue($result['found'], 'Earlier translation still shown');
        $this->assertSame(translation_manager::STATUS_STALE, $result['status']);
        $this->assertSame($translation->text, $result['text']);
        set_config('lang_de_showstale', 0, 'local_contenttranslator');
        cache_helper::purge();
        $this->assertFalse(api::lookup('<p>Hello new world</p>', 'de', $context)['found'], 'Unless stale ones are hidden');

        // Never translated and failed: nothing to show.
        $other = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'content' => '<p>Other text</p>']);
        item_manager::sync_course((int)$course->id);
        $otheritem = item_manager::find('mod_page', 'page', 'content', (int)$other->id);
        translation_manager::mark_failed(translation_manager::ensure((int)$otheritem->id, 'de'), 'engine down', 2);
        cache_helper::purge();
        $this->assertFalse(api::lookup('<p>Other text</p>', 'de', \context_module::instance($other->cmid))['found']);
    }

    /**
     * The course settings page shows what "Use default" would give, not the course's own value.
     */
    public function test_course_settings_show_inherited_values(): void {
        global $CFG;
        require_once($CFG->libdir . '/formslib.php');
        $category = $this->getDataGenerator()->create_category(['name' => 'Kitchen']);
        $course = $this->getDataGenerator()->create_course(['category' => $category->id]);
        config::save_override('category', (int)$category->id, ['targetlangs' => ['de']]);
        config::save_override('course', (int)$course->id, ['enabled' => 0, 'externalallowed' => 0, 'targetlangs' => ['fr']]);

        $parent = config::get_effective((int)$course->id, false);
        $this->assertTrue($parent->enabled, 'Site default is on in this test');
        $this->assertTrue($parent->externalallowed);
        $this->assertSame(['de'], $parent->targetlangs);
        $this->assertSame('Kitchen', $parent->inherited['targetlangs']);
        // The course's own values are unaffected (separate cache entry).
        $own = config::get_effective((int)$course->id);
        $this->assertFalse($own->enabled);
        $this->assertSame(['fr'], $own->targetlangs);

        $form = new \local_contenttranslator\form\course_config_form(null, [
            'effective' => $parent, 'inheritedlabels' => $parent->inherited,
        ]);
        $html = $form->render();
        $this->assertStringContainsString('Use default (Yes)', $html);
        $this->assertStringContainsString('Use the setting of Kitchen', $html);
        $this->assertStringContainsString('Use default (Show immediately', $html);

        // Languages with different site visibility: no single value to show.
        set_config('lang_fr_visibility', config::VISIBILITY_REVIEWED, 'local_contenttranslator');
        $site = config::get_effective((int)$this->getDataGenerator()->create_course()->id, false);
        $html = (new \local_contenttranslator\form\course_config_form(null, [
            'effective' => $site, 'inheritedlabels' => $site->inherited,
        ]))->render();
        $this->assertStringContainsString('Use default (per language, as in the site settings)', $html);
        $this->assertStringContainsString('Use the setting of the site', $html);
    }

    /**
     * Render lookup honours visibility, language fallback and the tenant boundary.
     */
    public function test_lookup_and_visibility(): void {
        [$course, $page, $item] = $this->create_course_with_page();
        $context = \context_module::instance($page->cmid);
        $this->assertNull(api::lookup('Unknown text', 'de', $context));
        $result = api::lookup('<p>Hello <b>world</b></p>', 'de', $context);
        $this->assertFalse($result['found']);
        $this->assertSame('en', $result['sourcelang']);

        $translation = translator::translate_item($item, 'de', budget::TRIGGER_BULK, 2);
        $result = api::lookup('<p>Hello <b>world</b></p>', 'de', $context);
        $this->assertTrue($result['found']);
        $this->assertSame($translation->text, $result['text']);
        $this->assertSame('de', $result['lang']);
        // The rendered variant with different markup hashes alike.
        $this->assertTrue(api::lookup("<p>Hello  <b>world</b><br /></p>\n", 'de', $context)['found']);
        // User in the source language, or in a language without translation: nothing.
        $this->assertFalse(api::lookup('<p>Hello <b>world</b></p>', 'en', $context)['found']);
        $this->assertFalse(api::lookup('<p>Hello <b>world</b></p>', 'fr', $context)['found']);
        $this->assertSame($translation->text, api::get_translation('<p>Hello <b>world</b></p>', 'de', $context));
        $this->assertSame('x', api::get_translation('x', 'de', $context));

        // Reviewed-only mode hides machine translations until reviewed.
        set_config('lang_de_visibility', config::VISIBILITY_REVIEWED, 'local_contenttranslator');
        $this->assertFalse(api::lookup('<p>Hello <b>world</b></p>', 'de', $context)['found']);
        translation_manager::mark_reviewed($translation, 2);
        $this->assertTrue(api::lookup('<p>Hello <b>world</b></p>', 'de', $context)['found']);
        set_config('lang_de_visibility', config::VISIBILITY_IMMEDIATE, 'local_contenttranslator');

        // Tenant boundary = course: another course does not see this translation.
        set_config('tenantboundary', tenant::BOUNDARY_COURSE, 'local_contenttranslator');
        cache_helper::purge();
        $other = $this->getDataGenerator()->create_course();
        $this->assertNull(api::lookup('<p>Hello <b>world</b></p>', 'de', \context_course::instance($other->id)));
        $this->assertTrue(api::lookup('<p>Hello <b>world</b></p>', 'de', $context)['found']);
    }

    /**
     * Source change -> stale; human edits are never overwritten; suggestion; rollback; history.
     */
    public function test_status_lifecycle(): void {
        global $DB;
        [$course, $page, $item] = $this->create_course_with_page();
        $translation = translator::translate_item($item, 'de', budget::TRIGGER_BULK, 2);

        // Human edit + review.
        $translation = translation_manager::save_human($translation, '<p>Hallo Welt</p>', FORMAT_HTML, 2, true);
        $this->assertSame(translation_manager::STATUS_REVIEWED, $translation->status);
        $this->assertSame(translation_manager::ORIGIN_HUMAN, $translation->origin);
        $this->assertCount(1, translation_manager::get_history((int)$translation->id));
        // Human text entered the TM with human quality.
        $tm = tm::find('site', 'en', 'de', $item->sourcehash);
        $this->assertSame(tm::QUALITY_HUMAN, $tm->quality);

        // Source changes (direct DB edit, caught by the scan).
        $DB->set_field('page', 'content', '<p>Hello <b>universe</b></p>', ['id' => $page->id]);
        $changed = item_manager::sync_course((int)$course->id)['changed'];
        $this->assertContains((int)$item->id, $changed);
        $translation = translation_manager::get((int)$translation->id);
        $this->assertSame(translation_manager::STATUS_STALE, $translation->status);
        $this->assertSame('<p>Hallo Welt</p>', $translation->text, 'Reviewed text is kept');
        $item = item_manager::get_item((int)$item->id);
        $this->assertContains($translation->id, array_map(fn($t) => $t->id, item_manager::get_pending((int)$course->id, 'de')));

        // Re-translation stores a suggestion only.
        $translation = translator::translate_item($item, 'de', budget::TRIGGER_BULK, 2);
        $this->assertSame('<p>Hallo Welt</p>', $translation->text);
        $this->assertStringContainsString('universe', $translation->suggestion);
        $this->assertSame($item->sourcehash, $translation->suggestionhash);
        $pendingids = array_map(fn($t) => (int)$t->id, item_manager::get_pending((int)$course->id, 'de'));
        $this->assertNotContains((int)$translation->id, $pendingids, 'Nothing pending once a suggestion exists');

        // Accept the suggestion.
        translation_manager::accept_suggestion($translation, 2);
        $translation = translation_manager::get((int)$translation->id);
        $this->assertSame(translation_manager::STATUS_REVIEWED, $translation->status);
        $this->assertStringContainsString('universe', $translation->text);
        $this->assertNull($translation->suggestion);

        // Rollback to the human version.
        $history = translation_manager::get_history((int)$translation->id);
        $human = null;
        foreach ($history as $entry) {
            if ($entry->text === '<p>Hallo Welt</p>') {
                $human = $entry;
            }
        }
        $this->assertNotNull($human);
        translation_manager::rollback($translation, (int)$human->id, 2);
        $translation = translation_manager::get((int)$translation->id);
        $this->assertSame('<p>Hallo Welt</p>', $translation->text);

        // A locked machine translation is not overwritten either.
        $machine = translator::translate_item($item, 'fr', budget::TRIGGER_BULK, 2);
        translation_manager::set_locked($machine, true, 2);
        $machine = translation_manager::get((int)$machine->id);
        translation_manager::requeue($machine, 2);
        $machine = translator::translate_item($item, 'fr', budget::TRIGGER_BULK, 2);
        $this->assertSame(1, (int)$machine->locked);
        $this->assertNotNull($machine->suggestion);
    }

    /**
     * Budget: automatic jobs stop when the limit is reached; on-demand with capability continues.
     */
    public function test_budget(): void {
        global $DB;
        [$course, $page, $item] = $this->create_course_with_page(str_repeat('<p>Some longer paragraph here.</p>', 20));
        set_config('budgetchars', 100, 'local_contenttranslator');
        $this->assertTrue(budget::is_automation_enabled());
        $this->assertFalse(budget::can_spend((int)$item->chars, budget::TRIGGER_BULK, 2));
        $this->expectException(engine\budget_exceeded_exception::class);
        translator::translate_item($item, 'de', budget::TRIGGER_BULK, 2);
    }

    /**
     * Budget notifications at 80 % and pause at 100 % via the task.
     */
    public function test_budget_notification_and_task(): void {
        global $DB;
        [$course, $page, $item] = $this->create_course_with_page('<p>Twelve chars</p>');
        set_config('budgetchars', 12, 'local_contenttranslator');
        $this->expectOutputRegex('~pending~');
        $sink = $this->redirectMessages();
        // Only the page content is waiting.
        $DB->delete_records_select('local_contenttranslator_tr', 'itemid <> :id', ['id' => $item->id]);
        translation_manager::ensure((int)$item->id, 'de');
        $task = new task\translate_task();
        $task->set_custom_data(['courseid' => $course->id, 'lang' => 'de', 'trigger' => budget::TRIGGER_BULK]);
        $task->execute();
        // The page content (12 chars) consumed the whole budget.
        $this->assertEquals(12, budget::get_used());
        $this->assertSame(translation_manager::STATUS_MACHINE, translation_manager::get_for_item((int)$item->id, 'de')->status);
        // Everything else stays queued and the task stops without spending more.
        queue::ensure_course_translations((int)$course->id, ['de']);
        $task->execute();
        $this->assertEquals(12, budget::get_used());
        $this->assertGreaterThan(
            0,
            $DB->count_records('local_contenttranslator_tr', ['status' => translation_manager::STATUS_QUEUED])
        );
        $messages = $sink->get_messages();
        $this->assertCount(1, $messages, 'Used up is reported once; the stop that follows adds no second message');
        $this->assertStringContainsString('used up', $messages[0]->subject);
        $sink->close();
        // On demand still works for users allowed to exceed the budget.
        $this->assertTrue(budget::can_spend(1000, budget::TRIGGER_ONDEMAND, 2));
        $this->assertFalse(budget::can_spend(1000, budget::TRIGGER_ONDEMAND, 3));
    }

    /**
     * Event path: saving a module registers the change and queues one task per course and language.
     */
    public function test_observer_queues_task(): void {
        global $DB;
        $this->expectOutputRegex('~pending~');
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module(
            'page',
            ['course' => $course->id, 'name' => 'Observed', 'content' => '<p>Watch me</p>']
        );
        $item = item_manager::find('mod_page', 'page', 'content', (int)$page->id);
        $this->assertNotNull($item, 'course_module_created registers the item');
        $tasks = $DB->get_records('task_adhoc', ['classname' => '\\' . task\translate_task::class]);
        $this->assertCount(2, $tasks, 'One task per target language');
        foreach ($tasks as $task) {
            $data = json_decode($task->customdata);
            $this->assertEquals($course->id, $data->courseid);
        }
        $this->assertSame(translation_manager::STATUS_QUEUED, translation_manager::get_for_item((int)$item->id, 'de')->status);

        // Run the tasks: translations arrive.
        $this->runAdhocTasks(task\translate_task::class);
        $this->assertSame(translation_manager::STATUS_MACHINE, translation_manager::get_for_item((int)$item->id, 'de')->status);

        // Update (as the module edit form does): stale, queued again, debounced task.
        $DB->delete_records('task_adhoc');
        $DB->set_field('page', 'content', '<p>Watch me again</p>', ['id' => $page->id]);
        [$course2, $cm] = get_course_and_cm_from_cmid($page->cmid, 'page');
        \core\event\course_module_updated::create_from_cm($cm, \context_module::instance($cm->id))->trigger();
        $item = item_manager::get_item((int)$item->id);
        $this->assertSame(normaliser::hash('<p>Watch me again</p>'), $item->sourcehash);
        $this->assertSame(translation_manager::STATUS_STALE, translation_manager::get_for_item((int)$item->id, 'de')->status);
        $this->assertCount(2, $DB->get_records('task_adhoc', ['classname' => '\\' . task\translate_task::class]));

        // Automatic translation off for the course: no task.
        $DB->delete_records('task_adhoc');
        config::save_override('course', (int)$course->id, ['enabled' => 0]);
        $DB->set_field('page', 'content', '<p>Third</p>', ['id' => $page->id]);
        item_manager::sync_course((int)$course->id);
        queue::queue_items([(int)$item->id], budget::TRIGGER_ONSAVE);
        $this->assertCount(0, $DB->get_records('task_adhoc', ['classname' => '\\' . task\translate_task::class]));
    }

    /**
     * Course / category overrides.
     */
    public function test_effective_config(): void {
        $category = $this->getDataGenerator()->create_category();
        $course = $this->getDataGenerator()->create_course(['category' => $category->id]);
        $this->assertSame(['de', 'fr'], config::get_course_target_langs((int)$course->id));
        config::save_override(
            'category',
            (int)$category->id,
            ['targetlangs' => ['de'], 'visibility' => config::VISIBILITY_REVIEWED]
        );
        $this->assertSame(['de'], config::get_course_target_langs((int)$course->id));
        $this->assertSame(config::VISIBILITY_REVIEWED, config::get_visibility('de', (int)$course->id));
        config::save_override('course', (int)$course->id, ['targetlangs' => ['fr'], 'enabled' => 0]);
        $this->assertSame(['fr'], config::get_course_target_langs((int)$course->id));
        $this->assertFalse(config::is_auto_enabled_for_course((int)$course->id));
        $effective = config::get_effective((int)$course->id);
        $this->assertSame('course', $effective->inherited['targetlangs']);
        $this->assertSame($category->name, $effective->inherited['visibility']);
    }

    /**
     * Sources registered through the hook and user generated sources are handled.
     */
    public function test_registry(): void {
        $registry = registry::get();
        $this->assertNotNull($registry->get_source('core_course', 'course'));
        $this->assertNotNull($registry->get_source('mod_page', 'page'));
        $this->assertNotNull($registry->get_source('mod_book', 'book_chapters'));
        $this->assertTrue($registry->has_table('page'));
        $this->assertFalse($registry->has_table('forum_posts'));
        $fields = $registry->get_source('mod_page', 'page')->get_fields();
        $this->assertArrayHasKey('content', $fields);
        $this->assertArrayNotHasKey('displayoptions', $fields);
        $this->assertArrayHasKey('activity', $registry->get_source('mod_assign', 'assign')->get_fields());
        $this->assertArrayNotHasKey('externalurl', $registry->get_source('mod_url', 'url')->get_fields());
    }
}
