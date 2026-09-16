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
 * Triggers, safe defaults and cost control: budget 0, debounce, bulk, backlog window and priority, scan,
 * cleanup, 80/100 % notifications, estimates and tenant-isolated translation memory
 * (AUTO-05/06/07/11/12/13/16, DATA-14/15, TM-02/04).
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_contenttranslator\budget
 * @covers     \local_contenttranslator\queue
 * @covers     \local_contenttranslator\task\backlog_task
 * @covers     \local_contenttranslator\task\scan_task
 * @covers     \local_contenttranslator\task\cleanup_task
 * @covers     \local_contenttranslator\item_manager
 * @covers     \local_contenttranslator\tm
 */
final class automation_test extends \advanced_testcase {
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
     * Queued translate tasks.
     *
     * @return \stdClass[]
     */
    private function translate_tasks(): array {
        global $DB;
        return array_values($DB->get_records('task_adhoc', ['classname' => '\\' . task\translate_task::class], 'nextruntime ASC'));
    }

    /**
     * Fresh install: no budget means no automatic work at all, but "translate now" works (AUTO-16).
     */
    public function test_no_budget_no_automation(): void {
        global $DB;
        set_config('budgetchars', 0, 'local_contenttranslator');
        $this->assertFalse(budget::is_automation_enabled());

        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'content' => '<p>No budget yet</p>']);
        $item = item_manager::find('mod_page', 'page', 'content', (int)$page->id);
        $this->assertNotNull($item, 'Content is still registered');
        $this->assertCount(0, $this->translate_tasks(), 'Saving does not queue');

        ob_start();
        (new task\backlog_task())->execute();
        (new task\scan_task())->execute();
        queue::ensure_course_translations((int)$course->id, ['de']);
        $task = new task\translate_task();
        $task->set_custom_data(['courseid' => $course->id, 'lang' => 'de', 'trigger' => budget::TRIGGER_BACKLOG]);
        $task->execute();
        $output = ob_get_clean();
        $this->assertStringContainsString('no monthly budget set', $output);
        $this->assertCount(0, $this->translate_tasks(), 'Neither backlog nor scan queue work');
        $this->assertSame(translation_manager::STATUS_QUEUED, translation_manager::get_for_item((int)$item->id, 'de')->status);
        $this->assertFalse(budget::can_spend(10, budget::TRIGGER_BULK, 2));
        $this->assertEquals(0, $DB->count_records('local_contenttranslator_use'));

        // On demand is allowed before a budget exists.
        $translation = api::translate_now((int)$item->id, 'de', (int)get_admin()->id);
        $this->assertSame(translation_manager::STATUS_MACHINE, $translation->status);

        // The admin switch turns automation off even with a budget.
        set_config('budgetchars', 1000, 'local_contenttranslator');
        $this->assertTrue(budget::is_automation_enabled());
        set_config('enableauto', 0, 'local_contenttranslator');
        $this->assertFalse(budget::is_automation_enabled());
    }

    /**
     * Out of the box no course is translated: the site default is off until an admin opts a course in.
     */
    public function test_course_default_is_off(): void {
        unset_config('defaultcourseenabled', 'local_contenttranslator');
        $course = $this->getDataGenerator()->create_course();
        $this->assertFalse(config::is_auto_enabled_for_course((int)$course->id));

        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'content' => '<p>Not mine</p>']);
        $item = item_manager::find('mod_page', 'page', 'content', (int)$page->id);
        $this->assertNotNull($item, 'Content is still registered');
        $this->assertCount(0, $this->translate_tasks(), 'Saving does not queue');

        ob_start();
        (new task\scan_task())->execute();
        (new task\backlog_task())->execute();
        ob_end_clean();
        $this->assertCount(0, $this->translate_tasks(), 'Neither scan nor backlog queue work');

        // Opting the course in is enough, no other setting changes.
        config::save_override('course', (int)$course->id, ['enabled' => 1]);
        cache_helper::purge();
        \cache::make('local_contenttranslator', 'courseconfig')->purge();
        $this->assertTrue(config::is_auto_enabled_for_course((int)$course->id));
        ob_start();
        (new task\scan_task())->execute();
        ob_end_clean();
        $this->assertCount(1, $this->translate_tasks(), 'The opted in course is queued');
    }

    /**
     * On save: repeated saves within the debounce window reschedule one task per language (AUTO-05).
     */
    public function test_on_save_is_debounced(): void {
        global $DB;
        set_config('debounce', 120, 'local_contenttranslator');
        $course = $this->getDataGenerator()->create_course();
        $before = time();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'content' => '<p>Version 1</p>']);

        $tasks = $this->translate_tasks();
        $this->assertCount(1, $tasks);
        $this->assertGreaterThanOrEqual($before + 120, (int)$tasks[0]->nextruntime);
        $this->assertSame(budget::TRIGGER_ONSAVE, json_decode($tasks[0]->customdata)->trigger);

        foreach (['<p>Version 2</p>', '<p>Version 3</p>'] as $content) {
            $DB->set_field('page', 'content', $content, ['id' => $page->id]);
            [, $cm] = get_course_and_cm_from_cmid($page->cmid, 'page');
            \core\event\course_module_updated::create_from_cm($cm, \context_module::instance($cm->id))->trigger();
        }
        $this->assertCount(1, $this->translate_tasks(), 'Still one task: no double cost for repeated saves');

        $this->expectOutputRegex('~translated~');
        $this->runAdhocTasks(task\translate_task::class);
        $item = item_manager::find('mod_page', 'page', 'content', (int)$page->id);
        $translation = translation_manager::get_for_item((int)$item->id, 'de');
        $this->assertStringContainsString('Version 3', $translation->text);
        $this->assertEquals(1, $DB->count_records('local_contenttranslator_use', ['itemid' => $item->id]));
    }

    /**
     * Bulk: scans the course, puts failed items back into the queue, queues immediately, fires the event (AUTO-06).
     */
    public function test_bulk_course(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Bulk course']);
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'content' => '<p>Bulk me</p>']);
        $item = item_manager::find('mod_page', 'page', 'content', (int)$page->id);
        translation_manager::mark_failed(translation_manager::ensure((int)$item->id, 'de'), 'earlier error', 2);
        $DB->delete_records('task_adhoc'); // Drop the on-save task of the page creation.

        $sink = $this->redirectEvents();
        $pending = queue::queue_course((int)$course->id, (int)get_admin()->id);
        $events = array_filter($sink->get_events(), fn($e) => $e instanceof event\bulk_started);
        $sink->close();

        $this->assertGreaterThanOrEqual(3, $pending, 'Course name, page name and page content');
        $this->assertSame(translation_manager::STATUS_QUEUED, translation_manager::get_for_item((int)$item->id, 'de')->status);
        $tasks = $this->translate_tasks();
        $this->assertCount(1, $tasks);
        $this->assertLessThanOrEqual(time() + 1, (int)$tasks[0]->nextruntime);
        $this->assertSame(budget::TRIGGER_BULK, json_decode($tasks[0]->customdata)->trigger);
        $this->assertCount(1, $events);
        $this->assertEquals($pending, reset($events)->other['pending']);
    }

    /**
     * Backlog time window, including windows over midnight (AUTO-07).
     */
    public function test_backlog_window(): void {
        $at = fn(int $hour) => mktime($hour, 30, 0, 1, 15, 2026);
        $this->assertTrue(task\backlog_task::in_window($at(12)), 'start == end means always');

        set_config('backlogstart', 22, 'local_contenttranslator');
        set_config('backlogend', 6, 'local_contenttranslator');
        $this->assertTrue(task\backlog_task::in_window($at(23)));
        $this->assertTrue(task\backlog_task::in_window($at(2)));
        $this->assertFalse(task\backlog_task::in_window($at(6)));
        $this->assertFalse(task\backlog_task::in_window($at(12)));

        set_config('backlogstart', 8, 'local_contenttranslator');
        set_config('backlogend', 17, 'local_contenttranslator');
        $this->assertTrue(task\backlog_task::in_window($at(8)));
        $this->assertFalse(task\backlog_task::in_window($at(17)));
    }

    /**
     * Backlog: visible courses before hidden ones, courses with automation off are skipped (AUTO-07, AUTO-11).
     */
    public function test_backlog_priority(): void {
        global $DB;
        set_config('budgetchars', 0, 'local_contenttranslator');
        $hidden = $this->getDataGenerator()->create_course(['visible' => 0, 'startdate' => time() - DAYSECS]);
        $later = $this->getDataGenerator()->create_course(['startdate' => time() + 30 * DAYSECS]);
        $soon = $this->getDataGenerator()->create_course(['startdate' => time() + DAYSECS]);
        $off = $this->getDataGenerator()->create_course();
        foreach ([$hidden, $later, $soon, $off] as $course) {
            $this->getDataGenerator()->create_module('page', [
                'course' => $course->id, 'content' => '<p>Backlog ' . $course->id . '</p>',
            ]);
            item_manager::sync_course((int)$course->id);
            queue::ensure_course_translations((int)$course->id, ['de']);
        }
        config::save_override('course', (int)$off->id, ['enabled' => 0]);
        set_config('budgetchars', 1000000, 'local_contenttranslator');
        $DB->delete_records('task_adhoc');

        $this->expectOutputRegex('~queued 3 course/language jobs~');
        (new task\backlog_task())->execute();
        $order = array_map(fn($t) => (int)json_decode($t->customdata)->courseid, $this->translate_tasks());
        $this->assertSame([(int)$soon->id, (int)$later->id, (int)$hidden->id], $order);

        // Outside the window nothing is queued.
        $DB->delete_records('task_adhoc');
        $hour = (int)date('G');
        set_config('backlogstart', ($hour + 1) % 24, 'local_contenttranslator');
        set_config('backlogend', ($hour + 2) % 24, 'local_contenttranslator');
        (new task\backlog_task())->execute();
        $this->assertCount(0, $this->translate_tasks());
    }

    /**
     * Scan: changes made without events (direct DB edits, imports) become stale and are queued;
     * removed records lose their items (DATA-14, DATA-15).
     */
    public function test_scan_task(): void {
        global $DB;
        $category = $this->getDataGenerator()->create_category(['name' => 'Kitchen', 'description' => '<p>All about food</p>']);
        $course = $this->getDataGenerator()->create_course(['category' => $category->id]);
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'content' => '<p>Before</p>']);
        $book = $this->getDataGenerator()->create_module('book', ['course' => $course->id]);
        $chapter = $this->getDataGenerator()->get_plugin_generator('mod_book')->create_chapter([
            'bookid' => $book->id, 'title' => 'Chapter to delete', 'content' => '<p>Gone soon</p>',
        ]);
        item_manager::sync_course((int)$course->id);
        item_manager::sync_site();
        $pageitem = item_manager::find('mod_page', 'page', 'content', (int)$page->id);
        $catitem = item_manager::find('core_course', 'course_categories', 'description', (int)$category->id);
        $this->assertNotNull($catitem);
        foreach ([$pageitem, $catitem] as $item) {
            translator::translate_item($item, 'de', budget::TRIGGER_BULK, 2);
        }
        $this->assertNotNull(item_manager::find('mod_book', 'book_chapters', 'title', (int)$chapter->id));

        $DB->set_field('page', 'content', '<p>After</p>', ['id' => $page->id]);
        $DB->set_field('course_categories', 'description', '<p>All about cooking</p>', ['id' => $category->id]);
        $DB->delete_records('book_chapters', ['id' => $chapter->id]);
        $DB->delete_records('task_adhoc');
        // The scan removes items not seen since its start; make the earlier sync lie in the past.
        $DB->set_field('local_contenttranslator_item', 'timechecked', time() - 60, []);

        $this->expectOutputRegex('~scanned course ' . $course->id . '~');
        (new task\scan_task())->execute();

        $this->assertSame(translation_manager::STATUS_STALE, translation_manager::get_for_item((int)$pageitem->id, 'de')->status);
        $this->assertSame(translation_manager::STATUS_STALE, translation_manager::get_for_item((int)$catitem->id, 'de')->status);
        $this->assertNull(item_manager::find('mod_book', 'book_chapters', 'title', (int)$chapter->id));
        $courseids = array_map(fn($t) => (int)json_decode($t->customdata)->courseid, $this->translate_tasks());
        $this->assertContains((int)$course->id, $courseids);
        $this->assertContains(0, $courseids, 'Site level content (categories) is queued too');
    }

    /**
     * Cleanup: orphaned translations and expired history are removed, recent history stays (DATA-15).
     */
    public function test_cleanup_task(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'content' => '<p>Keep me</p>']);
        $item = item_manager::find('mod_page', 'page', 'content', (int)$page->id);
        $translation = translator::translate_item($item, 'de', budget::TRIGGER_BULK, 2);
        translation_manager::save_human($translation, '<p>Behalte mich</p>', FORMAT_HTML, 2, true);
        $orphan = $DB->insert_record('local_contenttranslator_tr', (object)[
            'itemid' => 999999, 'targetlang' => 'de', 'text' => 'x', 'format' => FORMAT_HTML, 'status' => 'machine',
            'origin' => 'machine', 'locked' => 0, 'chars' => 0, 'timecreated' => time(), 'timemodified' => time(),
        ]);
        $hist = fn(int $translationid, int $age) => $DB->insert_record('local_contenttranslator_hist', (object)[
            'translationid' => $translationid, 'itemid' => $item->id, 'targetlang' => 'de', 'text' => 'old',
            'format' => FORMAT_HTML, 'status' => 'machine', 'origin' => 'machine', 'reason' => 'mt', 'userid' => 2,
            'timecreated' => time() - $age,
        ]);
        $expired = $hist((int)$translation->id, 400 * DAYSECS);
        $recent = $hist((int)$translation->id, 10 * DAYSECS);
        $deletedold = $hist(888888, 40 * DAYSECS);
        $deletedrecent = $hist(888888, 5 * DAYSECS);

        (new task\cleanup_task())->execute();

        $this->assertFalse($DB->record_exists('local_contenttranslator_tr', ['id' => $orphan]));
        $this->assertTrue($DB->record_exists('local_contenttranslator_tr', ['id' => $translation->id]));
        $this->assertFalse($DB->record_exists('local_contenttranslator_hist', ['id' => $expired]));
        $this->assertTrue($DB->record_exists('local_contenttranslator_hist', ['id' => $recent]));
        $this->assertFalse($DB->record_exists('local_contenttranslator_hist', ['id' => $deletedold]));
        $this->assertTrue($DB->record_exists('local_contenttranslator_hist', ['id' => $deletedrecent]));
    }

    /**
     * Admins are notified once at 80 % and once at 100 % per month (AUTO-13).
     */
    public function test_budget_notifications(): void {
        set_config('budgetchars', 100, 'local_contenttranslator');
        $messages = $this->redirectMessages();
        $events = $this->redirectEvents();
        $log = fn(int $chars) => budget::log_usage([
            'engine' => 'pseudo', 'sourcelang' => 'en', 'targetlang' => 'de', 'chars' => $chars, 'triggertype' => 'bulk',
        ]);

        $log(79);
        $this->assertSame(0, $messages->count());
        $log(1);
        $this->assertSame(1, $messages->count());
        $this->assertStringContainsString('80 %', $messages->get_messages()[0]->subject);
        $log(5);
        $this->assertSame(1, $messages->count(), 'No repeat within the same level');
        $log(15);
        $this->assertSame(2, $messages->count());
        $this->assertStringContainsString('100 %', $messages->get_messages()[1]->subject);
        $this->assertSame('budget', $messages->get_messages()[1]->eventtype);

        $thresholds = array_filter($events->get_events(), fn($e) => $e instanceof event\budget_threshold_reached);
        $this->assertCount(2, $thresholds);
        $messages->close();
        $events->close();
    }

    /**
     * € estimates and the pre-flight estimate for bulk jobs, with TM hits counted as free (AUTO-12).
     */
    public function test_estimates(): void {
        $this->assertSame(0.0, budget::estimate(500000, 'pseudo'));
        $this->assertStringNotContainsString('€', budget::format(500000, 'pseudo'), 'No price, no € figure');
        set_config('price_pseudo', 10, 'local_contenttranslator');
        $this->assertEqualsWithDelta(5.0, budget::estimate(500000, 'pseudo'), 0.0001);
        $this->assertStringContainsString('(≈ 5.00 €)', budget::format(500000, 'pseudo'));

        $course = $this->getDataGenerator()->create_course(['fullname' => 'Estimate']);
        $page1 = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id, 'name' => 'One', 'content' => '<p>Same body</p>',
        ]);
        $page2 = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id, 'name' => 'Two', 'content' => '<p>Same body</p>',
        ]);
        item_manager::sync_course((int)$course->id);
        $before = item_manager::estimate_course((int)$course->id, 'de');

        $content1 = item_manager::find('mod_page', 'page', 'content', (int)$page1->id);
        translator::translate_item($content1, 'de', budget::TRIGGER_BULK, 2);
        $after = item_manager::estimate_course((int)$course->id, 'de');

        $this->assertSame($before['items'] - 1, $after['items'], 'The translated item needs no work');
        $this->assertSame(1, $after['tmhits'], 'The identical page body is a TM hit');
        $this->assertSame($before['chars'] - 2 * (int)$content1->chars, $after['chars'], 'TM hits cost nothing');
        $this->assertNotNull(item_manager::find('mod_page', 'page', 'content', (int)$page2->id));
    }

    /**
     * Translation memory is shared only within a tenant (TM-04): the same paragraph in two courses costs
     * one engine call without boundary, two with tenant = course.
     */
    public function test_tm_tenant_isolation(): void {
        global $DB;
        $translate = function (): array {
            $items = [];
            foreach ([1, 2] as $n) {
                $course = $this->getDataGenerator()->create_course();
                $page = $this->getDataGenerator()->create_module('page', [
                    'course' => $course->id, 'content' => '<p>Shared paragraph</p>',
                ]);
                $item = item_manager::find('mod_page', 'page', 'content', (int)$page->id);
                $items[] = translator::translate_item($item, 'de', budget::TRIGGER_BULK, 2);
            }
            return $items;
        };

        [, $second] = $translate();
        $this->assertSame(translation_manager::ORIGIN_TM, $second->origin, 'No boundary: site-wide TM');
        $this->assertEquals(1, $DB->count_records('local_contenttranslator_use'));

        $DB->delete_records('local_contenttranslator_use');
        set_config('tenantboundary', tenant::BOUNDARY_COURSE, 'local_contenttranslator');
        [$first, $second] = $translate();
        $this->assertSame(translation_manager::ORIGIN_MACHINE, $first->origin, 'Site TM entries are not visible to a tenant');
        $this->assertSame(translation_manager::ORIGIN_MACHINE, $second->origin, 'Other tenant: no TM hit');
        $this->assertEquals(2, $DB->count_records('local_contenttranslator_use'));
    }
}
