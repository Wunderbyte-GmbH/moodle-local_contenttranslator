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
 * One unit of text handed to an engine.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class segment {
    /**
     * Constructor.
     *
     * @param string $id Caller defined id, returned in the result.
     * @param string $text Text to translate.
     * @param bool $ishtml Whether the text is HTML.
     * @param string $context Free text context for the engine (course, activity, field).
     */
    public function __construct(
        /** @var string Id */
        public readonly string $id,
        /** @var string Text */
        public readonly string $text,
        /** @var bool HTML */
        public readonly bool $ishtml = true,
        /** @var string Context */
        public readonly string $context = '',
    ) {
    }
}
