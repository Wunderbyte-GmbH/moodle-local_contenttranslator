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

use core_ai\aiactions\generate_text;
use core_ai\aiactions\responses\response_generate_text;
use local_contenttranslator\engine\engine_manager;
use local_contenttranslator\engine\rate_limited_exception;
use local_contenttranslator\hook\register_engines;
use local_contenttranslator\source\registry;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/contenttranslator/tests/fixtures/scripted_engine.php');
require_once($CFG->dirroot . '/local/contenttranslator/tests/fixtures/fake_core_ai_engine.php');

/**
 * Pipeline behaviour with real engine routing: per-language engines, fallback, markup retry,
 * rate limits, course opt-out and the core_ai engine end to end (ENG-02, ENG-04, ENG-07, ENG-13, GOV-05).
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_contenttranslator\translator
 * @covers     \local_contenttranslator\engine\engine_manager
 * @covers     \local_contenttranslator\task\translate_task
 */
final class engine_pipeline_test extends \advanced_testcase {
    /** @var engine\engine[] Engines registered through the hook for this test */
    private array $engines = [];

    /**
     * Common setup: German and French, generous budget, no debounce.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('targetlangs', 'de,fr', 'local_contenttranslator');
        set_config('engine', 'pseudo', 'local_contenttranslator');
        set_config('budgetchars', 1000000, 'local_contenttranslator');
        set_config('debounce', 0, 'local_contenttranslator');
        $this->engines = [];
        $this->redirectHook(register_engines::class, function (register_engines $hook): void {
            foreach ($this->engines as $engine) {
                $hook->add_engine($engine);
            }
        });
        registry::reset();
        engine_manager::reset();
        cache_helper::purge();
    }

    /**
     * Register an engine (replaces a built-in engine of the same name).
     *
     * @param engine\engine $engine
     * @return engine\engine
     */
    private function add_engine(engine\engine $engine): engine\engine {
        $this->engines[] = $engine;
        engine_manager::reset();
        return $engine;
    }

    /**
     * Course with a page; returns [course, page, content item].
     *
     * @param string $content
     * @return array
     */
    private function create_page(string $content = '<p>Hello <b>world</b></p>'): array {
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id, 'name' => 'Intro', 'content' => $content,
        ]);
        item_manager::sync_course((int)$course->id);
        return [$course, $page, item_manager::find('mod_page', 'page', 'content', (int)$page->id)];
    }

    /**
     * Engine names returned for a target language.
     *
     * @param string $lang
     * @param bool $externalallowed
     * @return string[]
     */
    private function engine_names(string $lang, bool $externalallowed = true): array {
        return array_map(fn($e) => $e->get_name(), engine_manager::get_engines_for_lang('en', $lang, $externalallowed));
    }

    /**
     * Site engine, per-language engine and fallback engines are routed as configured.
     */
    public function test_routing(): void {
        $this->add_engine(new scripted_engine('scripted'));
        $this->assertSame(['pseudo'], $this->engine_names('de'));

        set_config('lang_fr_engine', 'scripted', 'local_contenttranslator');
        $this->assertSame(['scripted'], $this->engine_names('fr'));
        $this->assertSame(['pseudo'], $this->engine_names('de'), 'Other languages keep the site engine');

        set_config('fallbackengine', 'pseudo', 'local_contenttranslator');
        $this->assertSame(['scripted', 'pseudo'], $this->engine_names('fr'));
        set_config('lang_de_fallbackengine', 'scripted', 'local_contenttranslator');
        $this->assertSame(['pseudo', 'scripted'], $this->engine_names('de'), 'Per-language fallback beats the site fallback');

        $this->assertSame(['pseudo'], $this->engine_names('fr', false), 'External engines are skipped on opt-out');
        set_config('engine', 'doesnotexist', 'local_contenttranslator');
        set_config('lang_de_fallbackengine', '', 'local_contenttranslator');
        $this->assertSame(['pseudo'], $this->engine_names('de'), 'Unknown engines are ignored');
    }

    /**
     * Broken placeholders: one retry with the strict prompt, then success.
     */
    public function test_markup_retry_with_strict_prompt(): void {
        $engine = $this->add_engine((new scripted_engine())->script(scripted_engine::BROKEN, scripted_engine::ECHO));
        set_config('engine', 'scripted', 'local_contenttranslator');
        [, , $item] = $this->create_page();

        $translation = translator::translate_item($item, 'de', budget::TRIGGER_BULK, 2);
        $this->assertSame(translation_manager::STATUS_MACHINE, $translation->status);
        $this->assertSame('scripted', $translation->engine);
        $this->assertStringContainsString('<b>world</b>', $translation->text, 'Markup restored');
        $this->assertCount(2, $engine->calls);
        $this->assertFalse($engine->calls[0]['options']['strict']);
        $this->assertTrue($engine->calls[1]['options']['strict']);
        $this->assertStringNotContainsString('<b>', $engine->calls[0]['text'], 'The engine only sees placeholders');
        $this->assertStringContainsString('<ph id="', $engine->calls[0]['text']);
        $this->assertSame((int)$item->contextid, $engine->calls[0]['options']['contextid'], 'Real context for attribution');
    }

    /**
     * Broken twice: the fallback engine takes over.
     */
    public function test_markup_broken_twice_uses_fallback(): void {
        $this->add_engine((new scripted_engine())->script(scripted_engine::BROKEN, scripted_engine::BROKEN));
        set_config('engine', 'scripted', 'local_contenttranslator');
        set_config('fallbackengine', 'pseudo', 'local_contenttranslator');
        [, , $item] = $this->create_page();

        $translation = translator::translate_item($item, 'de', budget::TRIGGER_BULK, 2);
        $this->assertSame(translation_manager::STATUS_MACHINE, $translation->status);
        $this->assertSame('pseudo', $translation->engine);
        $this->assertStringContainsString('[de]', $translation->text);
        $this->assertStringContainsString('<b>world</b>', $translation->text);
    }

    /**
     * Broken everywhere: failed with a reason, and no broken HTML is ever stored.
     */
    public function test_markup_broken_everywhere_is_failed(): void {
        $this->add_engine((new scripted_engine())->script(scripted_engine::BROKEN, scripted_engine::BROKEN));
        set_config('engine', 'scripted', 'local_contenttranslator');
        [$course, , $item] = $this->create_page();

        $translation = translator::translate_item($item, 'de', budget::TRIGGER_BULK, 2);
        $translation = translation_manager::get((int)$translation->id);
        $this->assertSame(translation_manager::STATUS_FAILED, $translation->status);
        $this->assertNull($translation->text);
        $this->assertStringStartsWith(get_string('error:markup', 'local_contenttranslator', ''), $translation->failreason);
        $this->assertNull(tm::find(tenant::key((int)$course->id), 'en', 'de', $item->sourcehash), 'Nothing enters the TM');

        // Retry from the dashboard: failed rows go back to the queue.
        queue::ensure_course_translations((int)$course->id, ['de'], true);
        $this->assertSame(translation_manager::STATUS_QUEUED, translation_manager::get((int)$translation->id)->status);
    }

    /**
     * An engine error is not retried with the same engine; the fallback is used, otherwise failed.
     */
    public function test_engine_error(): void {
        $engine = $this->add_engine((new scripted_engine())->script(scripted_engine::ERROR, scripted_engine::ERROR));
        set_config('engine', 'scripted', 'local_contenttranslator');
        [, , $item] = $this->create_page();

        $translation = translator::translate_item($item, 'de', budget::TRIGGER_BULK, 2);
        $this->assertSame(translation_manager::STATUS_FAILED, translation_manager::get((int)$translation->id)->status);
        $this->assertSame('500 upstream failure', translation_manager::get((int)$translation->id)->failreason);
        $this->assertCount(1, $engine->calls);
        $this->assertSame(0, budget::get_used(), 'Failed calls do not consume the budget');

        set_config('fallbackengine', 'pseudo', 'local_contenttranslator');
        $translation = translator::translate_item($item, 'fr', budget::TRIGGER_BULK, 2);
        $this->assertSame('pseudo', $translation->engine);
    }

    /**
     * Rate limits abort the item without marking it failed; the task re-throws so cron retries later.
     */
    public function test_rate_limit(): void {
        $this->add_engine((new scripted_engine())->script(scripted_engine::RATELIMIT, scripted_engine::RATELIMIT));
        set_config('engine', 'scripted', 'local_contenttranslator');
        set_config('fallbackengine', 'pseudo', 'local_contenttranslator');
        [$course, , $item] = $this->create_page();

        try {
            translator::translate_item($item, 'de', budget::TRIGGER_BULK, 2);
            $this->fail('rate_limited_exception expected');
        } catch (rate_limited_exception $e) {
            $this->assertSame(translation_manager::STATUS_QUEUED, translation_manager::get_for_item((int)$item->id, 'de')->status);
        }

        $this->expectOutputRegex('~rate limited~');
        $task = new task\translate_task();
        $task->set_custom_data(['courseid' => $course->id, 'lang' => 'de', 'trigger' => budget::TRIGGER_BULK]);
        $this->expectException(rate_limited_exception::class);
        $task->execute();
    }

    /**
     * Unavailable engines are skipped; without an alternative the item fails with a clear reason.
     */
    public function test_unavailable_engine(): void {
        $this->add_engine(new scripted_engine('scripted', true, false));
        set_config('engine', 'scripted', 'local_contenttranslator');
        [, , $item] = $this->create_page();

        $translation = translator::translate_item($item, 'de', budget::TRIGGER_BULK, 2);
        $this->assertSame(
            get_string('error:engineunavailable', 'local_contenttranslator', 'Scripted scripted'),
            translation_manager::get((int)$translation->id)->failreason
        );

        set_config('fallbackengine', 'pseudo', 'local_contenttranslator');
        $this->assertSame('pseudo', translator::translate_item($item, 'fr', budget::TRIGGER_BULK, 2)->engine);
    }

    /**
     * Course opt-out of external translation (GOV-05): external engines are never called for that course.
     */
    public function test_course_optout_of_external_engines(): void {
        $engine = $this->add_engine(new scripted_engine());
        set_config('engine', 'scripted', 'local_contenttranslator');
        [$course, , $item] = $this->create_page();
        config::save_override('course', (int)$course->id, ['externalallowed' => 0]);

        $translation = translator::translate_item($item, 'de', budget::TRIGGER_BULK, 2);
        $this->assertSame(translation_manager::STATUS_FAILED, translation_manager::get((int)$translation->id)->status);
        $this->assertSame(
            get_string('error:noengine', 'local_contenttranslator'),
            translation_manager::get((int)$translation->id)->failreason
        );
        $this->assertCount(0, $engine->calls);

        set_config('fallbackengine', 'pseudo', 'local_contenttranslator');
        queue::ensure_course_translations((int)$course->id, ['de'], true);
        $translation = translator::translate_item($item, 'de', budget::TRIGGER_BULK, 2);
        $this->assertSame('pseudo', $translation->engine, 'Local engines are still allowed');
        $this->assertCount(0, $engine->calls);
    }

    /**
     * The core_ai engine end to end: protected prompt, restored and cleaned HTML, usage with tokens,
     * translation memory, and the AI policy of the calling user.
     */
    public function test_core_ai_engine_end_to_end(): void {
        global $DB;
        $engine = new fake_core_ai_engine();
        $this->add_engine($engine);
        set_config('engine', 'core_ai', 'local_contenttranslator');
        [$course, , $item] = $this->create_page('<p>Hello <b>world</b> <script>alert(1)</script></p>');
        $user = $this->getDataGenerator()->create_user();

        // Policy not accepted: no call, failed with reason.
        $translation = translator::translate_item($item, 'de', budget::TRIGGER_ONDEMAND, (int)$user->id);
        $this->assertCount(0, $engine->actions);
        $this->assertSame(translation_manager::STATUS_FAILED, translation_manager::get((int)$translation->id)->status);

        \core_ai\manager::user_policy_accepted((int)$user->id, \context_system::instance()->id);
        $engine->compute(function (generate_text $action): response_generate_text {
            $response = new response_generate_text(true);
            $text = str_replace(['Hello', 'world'], ['Hallo', 'Welt'], fake_core_ai_engine::prompt_text($action));
            $response->set_response_data([
                'generatedcontent' => '"' . $text . '"', 'prompttokens' => '40', 'completiontokens' => '9', 'model' => 'gpt-test',
            ]);
            return $response;
        });
        queue::ensure_course_translations((int)$course->id, ['de'], true);
        $translation = translator::translate_item($item, 'de', budget::TRIGGER_ONDEMAND, (int)$user->id);

        $this->assertCount(1, $engine->actions);
        $prompt = (string)$engine->actions[0]->get_configuration('prompttext');
        $this->assertStringNotContainsString('<b>', $prompt, 'Markup never reaches the LLM');
        $this->assertStringNotContainsString('alert(1)', $prompt, 'Scripts never reach the LLM');
        $this->assertSame((int)$user->id, $engine->actions[0]->get_configuration('userid'));
        $this->assertSame((int)$item->contextid, $engine->actions[0]->get_configuration('contextid'));

        $this->assertSame(translation_manager::STATUS_MACHINE, $translation->status);
        $this->assertSame('core_ai', $translation->engine);
        $this->assertSame('gpt-test', $translation->model);
        $this->assertStringContainsString('Hallo <b>Welt</b>', $translation->text);
        $this->assertStringNotContainsString('<script', $translation->text, 'Output is cleaned');

        $usage = $DB->get_record('local_contenttranslator_use', ['itemid' => $item->id, 'success' => 1], '*', MUST_EXIST);
        $this->assertEquals(40, $usage->prompttokens);
        $this->assertEquals(9, $usage->completiontokens);
        $this->assertEquals($course->id, $usage->courseid);
        $this->assertSame(budget::TRIGGER_ONDEMAND, $usage->triggertype);
        $this->assertNotNull(tm::find(tenant::key((int)$course->id), 'en', 'de', $item->sourcehash));
    }
}
