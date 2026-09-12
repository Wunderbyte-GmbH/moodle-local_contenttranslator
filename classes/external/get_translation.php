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

namespace local_contenttranslator\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_contenttranslator\api;

/**
 * External function: render lookup for a text.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_translation extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'text' => new external_value(PARAM_RAW, 'Source text'),
            'lang' => new external_value(PARAM_ALPHANUMEXT, 'Language', VALUE_DEFAULT, ''),
            'contextid' => new external_value(PARAM_INT, 'Render context', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Execute.
     *
     * @param string $text
     * @param string $lang
     * @param int $contextid
     * @return array
     */
    public static function execute(string $text, string $lang = '', int $contextid = 0): array {
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['text' => $text, 'lang' => $lang, 'contextid' => $contextid]
        );
        $context = $params['contextid'] ? \context::instance_by_id($params['contextid']) : \context_system::instance();
        self::validate_context($context);
        $lang = $params['lang'] ?: current_language();
        if (!\local_contenttranslator\config::parse_langs($lang)) {
            throw new \invalid_parameter_exception('Unknown language');
        }
        $result = api::lookup($params['text'], $lang, $context);
        $found = $result && $result['found'];
        return [
            'found' => $found,
            'text' => $found ? \local_contenttranslator\normaliser::rewrite_file_urls((string)$result['text'], $params['text'])
                : $params['text'],
            'lang' => $found ? $result['lang'] : ($result['sourcelang'] ?? ''),
            'status' => $found ? (string)$result['status'] : '',
        ];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'found' => new external_value(PARAM_BOOL, 'Whether a visible translation exists'),
            'text' => new external_value(PARAM_RAW, 'Translation or source text'),
            'lang' => new external_value(PARAM_ALPHANUMEXT, 'Language of the returned text'),
            'status' => new external_value(PARAM_ALPHA, 'Translation status'),
        ]);
    }
}
