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
use core_ai\aiactions\responses\response_base;
use core_ai\aiactions\responses\response_generate_text;
use local_contenttranslator\engine\core_ai_engine;

/**
 * The real core_ai engine with the provider call replaced by prepared responses.
 *
 * Everything above \core_ai\manager::process_action() is production code: prompt building, error and
 * rate-limit mapping, output cleaning and the AI policy check.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fake_core_ai_engine extends core_ai_engine {
    /** @var generate_text[] Actions that would have been sent to the provider */
    public array $actions = [];

    /** @var array Prepared responses: response_base instances, \Throwable instances or callables(generate_text) */
    private array $responses = [];

    /**
     * Queue a successful response.
     *
     * @param string $content Generated text.
     * @param string $finishreason Why the model stopped: "stop", or "length" when the answer was cut off.
     * @return self
     */
    public function respond(string $content, string $finishreason = 'stop'): self {
        $response = new response_generate_text(true);
        $response->set_response_data([
            'id' => 'fake-1',
            'fingerprint' => 'fp-1',
            'generatedcontent' => $content,
            'finishreason' => $finishreason,
            'prompttokens' => '12',
            'completiontokens' => '5',
            'model' => 'fake-model',
        ]);
        $this->responses[] = $response;
        return $this;
    }

    /**
     * Queue a failed response.
     *
     * @param int $code
     * @param string $message
     * @return self
     */
    public function fail(int $code, string $message): self {
        // Moodle 5.2 inserted a short "error" argument before "errormessage";
        // named arguments keep the fixture working on both signatures.
        $params = ['success' => false, 'errorcode' => $code, 'errormessage' => $message];
        foreach ((new \ReflectionMethod(response_generate_text::class, '__construct'))->getParameters() as $param) {
            if ($param->getName() === 'error') {
                $params['error'] = $message;
            }
        }
        $this->responses[] = new response_generate_text(...$params);
        return $this;
    }

    /**
     * Queue an exception thrown by the provider.
     *
     * @param \Throwable $e
     * @return self
     */
    public function throw(\Throwable $e): self {
        $this->responses[] = $e;
        return $this;
    }

    /**
     * Queue a response computed from the action (e.g. echo the text of the prompt).
     *
     * @param callable $callback fn(generate_text $action): response_base
     * @return self
     */
    public function compute(callable $callback): self {
        $this->responses[] = $callback;
        return $this;
    }

    /**
     * Text part of a prompt built from the default template.
     *
     * @param generate_text $action
     * @return string
     */
    public static function prompt_text(generate_text $action): string {
        $prompt = (string)$action->get_configuration('prompttext');
        $pos = strrpos($prompt, "Text:\n");
        return $pos === false ? $prompt : substr($prompt, $pos + 6);
    }

    #[\Override]
    public function is_available(): bool {
        // No provider is configured in PHPUnit; availability of the action is core's business.
        return true;
    }

    #[\Override]
    protected function process(generate_text $action): response_base {
        $this->actions[] = $action;
        $next = array_shift($this->responses);
        if ($next === null) {
            throw new \coding_exception('fake_core_ai_engine: no response prepared');
        }
        if ($next instanceof \Throwable) {
            throw $next;
        }
        if (is_callable($next)) {
            return $next($action);
        }
        return $next;
    }
}
