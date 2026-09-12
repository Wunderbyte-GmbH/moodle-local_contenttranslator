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
 * Translation engine interface.
 *
 * Engines never run during page rendering. They receive whole fields (segments) and return
 * translated text. HTML protection is done by the caller ({@see html_protector}) for engines that
 * do not support HTML natively; engines that do (DeepL tag_handling=html) return true from
 * supports_html() and get the raw HTML.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface engine {
    /**
     * Machine name, e.g. "core_ai".
     *
     * @return string
     */
    public function get_name(): string;

    /**
     * Human readable name.
     *
     * @return string
     */
    public function get_display_name(): string;

    /**
     * Whether the engine is configured and usable at all.
     *
     * @return bool
     */
    public function is_available(): bool;

    /**
     * Whether the engine can run for a user (AI policy accepted, ...).
     *
     * @param int $userid
     * @return bool
     */
    public function is_available_for_user(int $userid): bool;

    /**
     * Whether the engine sends data to an external service.
     *
     * @return bool
     */
    public function is_external(): bool;

    /**
     * Language pair support.
     *
     * @param string $sourcelang
     * @param string $targetlang
     * @return bool
     */
    public function supports(string $sourcelang, string $targetlang): bool;

    /**
     * Whether HTML can be sent as is (otherwise the caller tokenises markup first).
     *
     * @return bool
     */
    public function supports_html(): bool;

    /**
     * Maximum number of segments per translate_batch() call.
     *
     * @return int
     */
    public function max_batch_size(): int;

    /**
     * Translate segments.
     *
     * @param segment[] $segments
     * @param string $sourcelang
     * @param string $targetlang
     * @param array $options contextid, userid, formality, styleguide, glossary (term => translation), strict (bool)
     * @return result[] keyed by segment id
     */
    public function translate_batch(array $segments, string $sourcelang, string $targetlang, array $options = []): array;
}
