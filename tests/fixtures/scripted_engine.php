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

use local_contenttranslator\engine\engine;
use local_contenttranslator\engine\result;
use local_contenttranslator\engine\segment;

/**
 * Test engine that answers from a script and records every call. Never touches the network.
 *
 * Script entries are consumed one per translated segment; once the script is empty the engine
 * echoes the input with a "[name] " prefix, which keeps every placeholder intact.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scripted_engine implements engine {
    /** Answer: echo the input with prefix */
    public const ECHO = 'echo';
    /** Answer: drop every placeholder (broken markup) */
    public const BROKEN = 'broken';
    /** Answer: engine error */
    public const ERROR = 'error';
    /** Answer: rate limited */
    public const RATELIMIT = 'ratelimit';
    /** Answer: temporary failure (gateway timeout) that is worth one more try */
    public const TRANSIENT = 'transient';

    /** @var array[] Recorded calls: text, sourcelang, targetlang, options */
    public array $calls = [];

    /** @var string[] Remaining script */
    private array $script = [];

    /**
     * Constructor.
     *
     * @param string $name Engine name.
     * @param bool $external Whether the engine counts as external (course opt-out).
     * @param bool $available Whether the engine is available at all.
     * @param bool $requirepolicy Whether a user must have accepted the core AI policy.
     */
    public function __construct(
        /** @var string Engine name */
        private string $name = 'scripted',
        /** @var bool External engine */
        private bool $external = true,
        /** @var bool Available */
        private bool $available = true,
        /** @var bool Needs the AI policy */
        private bool $requirepolicy = false,
    ) {
    }

    /**
     * Append answers to the script.
     *
     * @param string ...$answers
     * @return self
     */
    public function script(string ...$answers): self {
        $this->script = array_merge($this->script, $answers);
        return $this;
    }

    #[\Override]
    public function get_name(): string {
        return $this->name;
    }

    #[\Override]
    public function get_display_name(): string {
        return 'Scripted ' . $this->name;
    }

    #[\Override]
    public function is_available(): bool {
        return $this->available;
    }

    #[\Override]
    public function is_available_for_user(int $userid): bool {
        if (!$this->available) {
            return false;
        }
        if ($this->requirepolicy) {
            return $userid > 0 && \core_ai\manager::get_user_policy_status($userid);
        }
        return true;
    }

    #[\Override]
    public function is_external(): bool {
        return $this->external;
    }

    #[\Override]
    public function supports(string $sourcelang, string $targetlang): bool {
        return true;
    }

    #[\Override]
    public function supports_html(): bool {
        return false;
    }

    #[\Override]
    public function max_batch_size(): int {
        return 1;
    }

    #[\Override]
    public function translate_batch(array $segments, string $sourcelang, string $targetlang, array $options = []): array {
        $results = [];
        /** @var segment $segment */
        foreach ($segments as $segment) {
            $this->calls[] = [
                'text' => $segment->text,
                'sourcelang' => $sourcelang,
                'targetlang' => $targetlang,
                'options' => $options,
            ];
            $answer = array_shift($this->script) ?? self::ECHO;
            $results[$segment->id] = match ($answer) {
                self::BROKEN => new result(
                    $segment->id,
                    true,
                    preg_replace('~<ph id="\d+"/>~', '', $segment->text),
                    '',
                    false,
                    'm1'
                ),
                self::ERROR => new result($segment->id, false, '', '500 upstream failure'),
                self::RATELIMIT => new result($segment->id, false, '', '429 too many requests', true),
                self::TRANSIENT => new result($segment->id, false, '', '504 gateway timeout', false, null, 0, 0, true),
                default => new result($segment->id, true, '[' . $this->name . '] ' . $segment->text, '', false, 'm1'),
            };
        }
        return $results;
    }
}
