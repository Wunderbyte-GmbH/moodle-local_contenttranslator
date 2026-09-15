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
use local_contenttranslator\hook\register_engines;
use local_contenttranslator\source\registry;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/contenttranslator/tests/fixtures/scripted_engine.php');

/**
 * Who AI calls run as (ENG-05): the translation service user for automatic jobs, the clicking user for
 * "translate now". Automatic jobs must refuse to run without a valid service user.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_contenttranslator\task\translate_task
 * @covers     \local_contenttranslator\api
 */
final class service_user_test extends \advanced_testcase {
    /** @var scripted_engine External engine that needs the core AI policy */
    private scripted_engine $engine;

    /**
     * Common setup: an external engine that checks the AI policy, like core_ai.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('targetlangs', 'de', 'local_contenttranslator');
        set_config('engine', 'scripted', 'local_contenttranslator');
        set_config('budgetchars', 1000000, 'local_contenttranslator');
        set_config('debounce', 0, 'local_contenttranslator');
        $this->engine = new scripted_engine('scripted', true, true, true);
        $this->redirectHook(register_engines::class, fn(register_engines $hook) => $hook->add_engine($this->engine));
        registry::reset();
        engine_manager::reset();
        cache_helper::purge();
    }

    /**
     * Course with a page whose content is queued for German; returns [course, content item].
     *
     * @return array
     */
    private function create_queued_page(): array {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'content' => '<p>Queued text</p>']);
        $DB->delete_records('task_adhoc');
        item_manager::sync_course((int)$course->id);
        queue::ensure_course_translations((int)$course->id, ['de']);
        return [$course, item_manager::find('mod_page', 'page', 'content', (int)$page->id)];
    }

    /**
     * Run the translate task for a course as it would run from cron (task user = the admin who saved).
     *
     * @param int $courseid
     * @param string $trigger
     */
    private function run_task(int $courseid, string $trigger = budget::TRIGGER_ONSAVE): void {
        $task = new task\translate_task();
        $task->set_custom_data(['courseid' => $courseid, 'lang' => 'de', 'trigger' => $trigger]);
        $task->set_userid(get_admin()->id);
        $task->execute();
    }

    /**
     * Automatic jobs run as the configured service user, not as the user who triggered them.
     */
    public function test_automatic_job_runs_as_service_user(): void {
        $serviceuser = $this->getDataGenerator()->create_user(['firstname' => 'Content', 'lastname' => 'translator']);
        \core_ai\manager::user_policy_accepted((int)$serviceuser->id, \context_system::instance()->id);
        set_config('serviceuserid', $serviceuser->id, 'local_contenttranslator');
        [$course, $item] = $this->create_queued_page();

        $this->expectOutputRegex('~translated~');
        $this->run_task((int)$course->id);
        $this->assertNotEmpty($this->engine->calls);
        foreach ($this->engine->calls as $call) {
            $this->assertEquals($serviceuser->id, $call['options']['userid']);
        }
        $this->assertSame(translation_manager::STATUS_MACHINE, translation_manager::get_for_item((int)$item->id, 'de')->status);
    }

    /**
     * ENG-05: without a service user, automatic jobs do not call external engines, keep the work queued
     * and notify the admins. (Currently the task silently falls back to the task user.)
     */
    public function test_automatic_job_refuses_without_service_user(): void {
        \core_ai\manager::user_policy_accepted((int)get_admin()->id, \context_system::instance()->id);
        [$course, $item] = $this->create_queued_page();
        $sink = $this->redirectMessages();

        ob_start();
        $this->run_task((int)$course->id);
        ob_end_clean();

        $this->assertCount(0, $this->engine->calls, 'No AI call without a service user');
        $this->assertSame(translation_manager::STATUS_QUEUED, translation_manager::get_for_item((int)$item->id, 'de')->status);
        $this->assertGreaterThan(0, $sink->count(), 'Admins are notified');
        $sink->close();
    }

    /**
     * ENG-05: a service user who has not accepted the AI policy blocks the job as a whole; the items stay
     * queued instead of being marked failed one by one.
     */
    public function test_automatic_job_refuses_when_policy_not_accepted(): void {
        $serviceuser = $this->getDataGenerator()->create_user();
        set_config('serviceuserid', $serviceuser->id, 'local_contenttranslator');
        [$course, $item] = $this->create_queued_page();

        ob_start();
        $this->run_task((int)$course->id, budget::TRIGGER_BULK);
        ob_end_clean();

        $this->assertCount(0, $this->engine->calls);
        $this->assertSame(translation_manager::STATUS_QUEUED, translation_manager::get_for_item((int)$item->id, 'de')->status);
    }

    /**
     * "Translate now" runs as the clicking user and needs that user's policy acceptance, no service user.
     */
    public function test_translate_now_runs_as_clicking_user(): void {
        [, $item] = $this->create_queued_page();
        $teacher = $this->getDataGenerator()->create_user();

        $translation = api::translate_now((int)$item->id, 'de', (int)$teacher->id);
        $this->assertCount(0, $this->engine->calls, 'Policy not accepted yet');
        $this->assertSame(translation_manager::STATUS_FAILED, translation_manager::get((int)$translation->id)->status);

        \core_ai\manager::user_policy_accepted((int)$teacher->id, \context_system::instance()->id);
        $translation = api::translate_now((int)$item->id, 'de', (int)$teacher->id);
        $this->assertCount(1, $this->engine->calls);
        $this->assertEquals($teacher->id, $this->engine->calls[0]['options']['userid']);
        $this->assertSame(translation_manager::STATUS_MACHINE, $translation->status);
    }
}
