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

/**
 * External functions for local_contenttranslator.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_contenttranslator_translate_item' => [
        'classname' => 'local_contenttranslator\external\translate_item',
        'description' => 'Translate one content item into one language now (interactive, runs as the calling user).',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/contenttranslator:translate',
    ],
    'local_contenttranslator_save_translation' => [
        'classname' => 'local_contenttranslator\external\save_translation',
        'description' => 'Save a human edited translation, optionally marking it reviewed.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/contenttranslator:translate',
    ],
    'local_contenttranslator_set_status' => [
        'classname' => 'local_contenttranslator\external\set_status',
        'description' => 'Review, lock, unlock, accept suggestion or delete a translation.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/contenttranslator:review',
    ],
    'local_contenttranslator_get_translation' => [
        'classname' => 'local_contenttranslator\external\get_translation',
        'description' => 'Look up the translation of a text for a language (render lookup, never calls an engine).',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => false,
    ],
];
