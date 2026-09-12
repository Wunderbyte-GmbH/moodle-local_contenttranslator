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

namespace local_contenttranslator\source;

use moodle_url;

/**
 * One translatable content record as reported by a content source.
 *
 * Holds every translatable field of the record with its current text and format.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class source_item {
    /**
     * Constructor.
     *
     * @param int $itemid Id of the record in the source table.
     * @param int $contextid Context the content lives in.
     * @param int $courseid Course id or 0 for site level content.
     * @param string $label Human readable label, e.g. "Page: Introduction".
     * @param array $fields field => ['text' => string, 'format' => int, 'string' => bool]
     * @param string|null $lang Explicit source language (forced course/activity language) or null.
     * @param moodle_url|null $editurl Deep link to the edit page of the record.
     */
    public function __construct(
        /** @var int Record id */
        public readonly int $itemid,
        /** @var int Context id */
        public readonly int $contextid,
        /** @var int Course id */
        public readonly int $courseid,
        /** @var string Label */
        public readonly string $label,
        /** @var array Fields */
        public readonly array $fields,
        /** @var string|null Explicit source language */
        public readonly ?string $lang = null,
        /** @var moodle_url|null Edit url */
        public readonly ?moodle_url $editurl = null,
    ) {
    }
}
