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

namespace local_contenttranslator\engine;

use local_contenttranslator\fake_core_ai_engine;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/contenttranslator/tests/fixtures/fake_core_ai_engine.php');

/**
 * Tests for the core_ai engine: prompt, response mapping, rate limits and AI policy (ENG-04/05/06/07).
 *
 * The provider call is faked; no AI service is contacted.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_contenttranslator\engine\core_ai_engine
 */
final class core_ai_engine_test extends \advanced_testcase {
    /**
     * Common setup.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Translate one segment with the fake engine.
     *
     * @param fake_core_ai_engine $engine
     * @param string $text
     * @param array $options
     * @return result
     */
    private function translate(fake_core_ai_engine $engine, string $text = 'Hello <ph id="1"/>world', array $options = []): result {
        $segment = new segment('s1', $text, true, 'course "Cooking basics", field "content"');
        $results = $engine->translate_batch([$segment], 'en', 'de', $options + ['userid' => 2, 'contextid' => 5]);
        $this->assertArrayHasKey('s1', $results);
        return $results['s1'];
    }

    /**
     * A successful response is cleaned and carries model and token usage; the action gets user and context.
     */
    public function test_success(): void {
        $fence = str_repeat(chr(96), 3);
        $engine = (new fake_core_ai_engine())->respond($fence . "html\nHallo <ph id=\"1\"/>Welt\n" . $fence);
        $result = $this->translate($engine);
        $this->assertTrue($result->success);
        $this->assertSame('Hallo <ph id="1"/>Welt', $result->text);
        $this->assertSame('fake-model', $result->model);
        $this->assertSame(12, $result->prompttokens);
        $this->assertSame(5, $result->completiontokens);
        $this->assertFalse($result->ratelimited);

        $this->assertCount(1, $engine->actions);
        $this->assertSame(2, $engine->actions[0]->get_configuration('userid'));
        $this->assertSame(5, $engine->actions[0]->get_configuration('contextid'));
    }

    /**
     * Errors, rate limits, exceptions and empty answers are mapped to results, never thrown.
     */
    public function test_failures(): void {
        $engine = (new fake_core_ai_engine())
            ->fail(429, 'Slow down')
            ->fail(500, 'Too many requests for this deployment')
            ->fail(500, 'Internal server error')
            ->throw(new \moodle_exception('generic', 'error', '', 'rate limit reached'))
            ->throw(new \coding_exception('provider exploded'))
            ->respond('   ');

        $result = $this->translate($engine);
        $this->assertFalse($result->success);
        $this->assertTrue($result->ratelimited, 'HTTP 429');
        $this->assertStringContainsString('429', $result->error);

        $this->assertTrue($this->translate($engine)->ratelimited, 'Rate limit wording in the message');

        $result = $this->translate($engine);
        $this->assertFalse($result->success);
        $this->assertFalse($result->ratelimited);
        $this->assertStringContainsString('Internal server error', $result->error);

        $result = $this->translate($engine);
        $this->assertFalse($result->success);
        $this->assertTrue($result->ratelimited, 'Exception text mentioning a rate limit');

        $result = $this->translate($engine);
        $this->assertFalse($result->success);
        $this->assertFalse($result->ratelimited);
        $this->assertStringContainsString('provider exploded', $result->error);

        $result = $this->translate($engine);
        $this->assertFalse($result->success);
        $this->assertSame('empty response', $result->error);
    }

    /**
     * Temporary overload is recognised by the status code alone, because Moodle 5.2 hides the provider message
     * outside developer debugging.
     */
    public function test_overload_codes_without_message(): void {
        $engine = (new fake_core_ai_engine())
            ->fail(503, 'Error')
            ->fail(529, 'Error')
            ->fail(400, 'Error');

        foreach ([503, 529] as $code) {
            $result = $this->translate($engine);
            $this->assertFalse($result->success);
            $this->assertTrue($result->ratelimited, "HTTP $code is retried later");
        }

        $result = $this->translate($engine);
        $this->assertFalse($result->success);
        $this->assertFalse($result->ratelimited, 'HTTP 400 is a real failure');
    }

    /**
     * An answer that was cut off is never returned as a translation. Behind the Wunderbyte gateway the partial
     * reasoning text of the model arrives as normal content, so only the finish reason tells it apart.
     */
    public function test_cut_off_answer_is_a_failure(): void {
        $engine = (new fake_core_ai_engine())
            ->respond('The user wants me to translate this, so let me think about', 'length')
            ->respond('Hallo', 'LENGTH')
            ->respond('Hallo', 'max_tokens')
            ->respond('Hallo', 'content_filter');

        $result = $this->translate($engine, 'Hello');
        $this->assertFalse($result->success);
        $this->assertSame('', $result->text, 'The partial text is not passed on');
        $this->assertFalse($result->ratelimited);
        $this->assertSame(get_string('error:truncated', 'local_contenttranslator', 'length'), $result->error);
        $this->assertSame('fake-model', $result->model, 'The call is billed, so its usage is still reported');
        $this->assertSame(12, $result->prompttokens);
        $this->assertSame(5, $result->completiontokens);
        $this->assertTrue($result->retryable, 'A short text that was cut off is a runaway of the model: try once more');

        $this->assertFalse($this->translate($engine, 'Hello')->success, 'Upper case is the same reason');
        $this->assertFalse($this->translate($engine, 'Hello')->success, 'max_tokens is the same reason');

        $result = $this->translate($engine, 'Hello');
        $this->assertFalse($result->success);
        $this->assertFalse($result->retryable, 'A filtered answer would be filtered again');
    }

    /**
     * A long text that was cut off is not worth a second call: it would be cut off at the same place.
     */
    public function test_cut_off_long_text_is_not_retryable(): void {
        $engine = (new fake_core_ai_engine())->respond('Lange Ein', 'length');
        $result = $this->translate($engine, str_repeat('Long text. ', 500));
        $this->assertFalse($result->success);
        $this->assertFalse($result->retryable);
    }

    /**
     * Normal finish reasons, in any case, and a missing finish reason are accepted.
     */
    public function test_regular_finish_reasons_are_accepted(): void {
        $engine = (new fake_core_ai_engine())
            ->respond('Hallo', 'stop')
            ->respond('Hallo', 'STOP')
            ->respond('Hallo', '');
        foreach (['stop', 'STOP', 'missing'] as $reason) {
            $result = $this->translate($engine, 'Hello');
            $this->assertTrue($result->success, "Finish reason $reason");
            $this->assertSame('Hallo', $result->text);
            $this->assertFalse($result->retryable);
        }
    }

    /**
     * Gateway timeouts and bad gateways are retried once, but they do not stop the whole job like a rate limit.
     */
    public function test_gateway_errors_are_retryable(): void {
        $engine = (new fake_core_ai_engine())
            ->fail(504, 'Error')
            ->fail(502, 'Error')
            ->fail(500, 'Internal server error')
            ->throw(new \moodle_exception('generic', 'error', '', 'cURL error 28: Operation timed out after 30001 milliseconds'));

        foreach ([504, 502] as $code) {
            $result = $this->translate($engine);
            $this->assertFalse($result->success);
            $this->assertTrue($result->retryable, "HTTP $code is tried once more");
            $this->assertFalse($result->ratelimited, "HTTP $code does not pause the whole job");
        }

        $result = $this->translate($engine);
        $this->assertFalse($result->success);
        $this->assertFalse($result->retryable, 'HTTP 500 is a real failure');

        $result = $this->translate($engine);
        $this->assertFalse($result->success);
        $this->assertTrue($result->retryable, 'A timeout while talking to the provider');
        $this->assertFalse($result->ratelimited);
    }

    /**
     * The prompt carries languages, register, style guide, context, the text and (on retry) the strict rule.
     */
    public function test_prompt(): void {
        set_config('lang_de_formality', 'more', 'local_contenttranslator');
        set_config('lang_de_styleguide', "Gender-inclusive language.\nShort sentences.", 'local_contenttranslator');
        $engine = (new fake_core_ai_engine())->respond('x')->respond('y');

        $this->translate($engine);
        $prompt = (string)$engine->actions[0]->get_configuration('prompttext');
        $this->assertStringContainsString('from English into German', $prompt);
        $this->assertStringContainsString('formal register', $prompt);
        $this->assertStringContainsString('Style guide: Gender-inclusive language. Short sentences.', $prompt);
        $this->assertStringContainsString('Context of the text: course "Cooking basics", field "content"', $prompt);
        $this->assertStringEndsWith("Text:\nHello <ph id=\"1\"/>world", $prompt);
        $this->assertStringNotContainsString('IMPORTANT', $prompt);
        // Short capitalised titles were mistaken for proper names and left untranslated.
        $this->assertStringContainsString('Translate titles and headings too, even when they are short or capitalised', $prompt);
        $this->assertStringNotContainsString('Do not translate proper names', $prompt);

        $this->translate($engine, 'Hello <ph id="1"/>world', ['strict' => true]);
        $this->assertStringContainsString('IMPORTANT: Your previous answer altered the placeholders', (string)$engine->actions[1]
            ->get_configuration('prompttext'));
    }

    /**
     * An earlier version corrected by a person is part of the prompt only when it is passed.
     */
    public function test_prompt_with_previous_version(): void {
        $engine = (new fake_core_ai_engine())->respond('x')->respond('y');
        $previous = ['source' => 'Welcome to the course', 'translation' => 'Willkommen im Kurs'];

        $this->translate($engine, 'Hello <ph id="1"/>world', ['previous' => $previous]);
        $prompt = (string)$engine->actions[0]->get_configuration('prompttext');
        $this->assertStringContainsString('corrected by a person', $prompt);
        $this->assertStringContainsString('Earlier text: Welcome to the course', $prompt);
        $this->assertStringContainsString('Their translation: Willkommen im Kurs', $prompt);

        $this->translate($engine);
        $this->assertStringNotContainsString('corrected by a person', (string)$engine->actions[1]->get_configuration('prompttext'));
    }

    /**
     * An admin-edited template replaces the shipped prompt.
     */
    public function test_custom_prompt_template(): void {
        set_config('prompttemplate', 'Translate to {targetlang}: {text}', 'local_contenttranslator');
        $engine = (new fake_core_ai_engine())->respond('x');
        $this->translate($engine, 'Good morning');
        $this->assertSame('Translate to German: Good morning', $engine->actions[0]->get_configuration('prompttext'));
    }

    /**
     * Calls run only for real users who accepted the core AI policy.
     */
    public function test_policy(): void {
        $engine = new fake_core_ai_engine();
        $user = $this->getDataGenerator()->create_user();
        $this->assertFalse($engine->is_available_for_user(0), 'No user, no call');
        $this->assertFalse($engine->is_available_for_user((int)$user->id), 'Policy not accepted');
        \core_ai\manager::user_policy_accepted((int)$user->id, \context_system::instance()->id);
        $this->assertTrue($engine->is_available_for_user((int)$user->id));
        $this->assertTrue($engine->is_external());
        $this->assertFalse($engine->supports_html(), 'Markup must be protected before it reaches the LLM');
    }

    /**
     * Without an enabled provider the real engine reports itself unavailable.
     */
    public function test_unavailable_without_provider(): void {
        $this->assertFalse((new core_ai_engine())->is_available());
    }
}
