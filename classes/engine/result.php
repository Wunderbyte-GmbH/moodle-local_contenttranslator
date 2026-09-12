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
 * Result of translating one segment.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class result {
    /**
     * Constructor.
     *
     * @param string $id Segment id.
     * @param bool $success
     * @param string $text Translated text.
     * @param string $error Error message.
     * @param bool $ratelimited True when the engine asked us to back off.
     * @param string|null $model Model used.
     * @param int $prompttokens
     * @param int $completiontokens
     */
    public function __construct(
        /** @var string Segment id */
        public readonly string $id,
        /** @var bool Success */
        public readonly bool $success,
        /** @var string Text */
        public readonly string $text = '',
        /** @var string Error */
        public readonly string $error = '',
        /** @var bool Rate limited */
        public readonly bool $ratelimited = false,
        /** @var string|null Model */
        public readonly ?string $model = null,
        /** @var int Prompt tokens */
        public readonly int $prompttokens = 0,
        /** @var int Completion tokens */
        public readonly int $completiontokens = 0,
    ) {
    }
}
