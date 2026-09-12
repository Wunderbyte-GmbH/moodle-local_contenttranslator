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

/**
 * Pseudo translation engine for tests, Behat and demos. Never calls the network.
 *
 * Prefixes the first text chunk with "[lang]" and leaves everything else intact.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pseudo_engine implements engine {
    #[\Override]
    public function get_name(): string {
        return 'pseudo';
    }

    #[\Override]
    public function get_display_name(): string {
        return get_string('engine:pseudo', 'local_contenttranslator');
    }

    #[\Override]
    public function is_available(): bool {
        return true;
    }

    #[\Override]
    public function is_available_for_user(int $userid): bool {
        return true;
    }

    #[\Override]
    public function is_external(): bool {
        return false;
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
        return 100;
    }

    #[\Override]
    public function translate_batch(array $segments, string $sourcelang, string $targetlang, array $options = []): array {
        $results = [];
        foreach ($segments as $segment) {
            $prefix = '[' . $targetlang . '] ';
            // The pipeline hands us protected text; add the prefix to the first chunk that contains letters.
            $parts = preg_split('~(<ph id="\d+"/>)~', $segment->text, -1, PREG_SPLIT_DELIM_CAPTURE);
            $done = false;
            foreach ($parts as $i => $part) {
                if ($done || preg_match('~^<ph id="\d+"/>$~', $part) || !preg_match('/\p{L}/u', $part)) {
                    continue;
                }
                $parts[$i] = preg_replace('/^(\s*)/', '$1' . $prefix, $part, 1);
                $done = true;
            }
            $text = $done ? implode('', $parts) : $prefix . $segment->text;
            $results[$segment->id] = new result($segment->id, true, $text, '', false, 'pseudo');
        }
        return $results;
    }
}
