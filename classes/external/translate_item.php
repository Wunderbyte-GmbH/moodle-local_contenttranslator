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
use local_contenttranslator\item_manager;

/**
 * External function: translate one item into one language now.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class translate_item extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'itemid' => new external_value(PARAM_INT, 'Item id'),
            'lang' => new external_value(PARAM_ALPHANUMEXT, 'Target language'),
        ]);
    }

    /**
     * Execute.
     *
     * @param int $itemid
     * @param string $lang
     * @return array
     */
    public static function execute(int $itemid, string $lang): array {
        global $USER;
        ['itemid' => $itemid, 'lang' => $lang] = self::validate_parameters(
            self::execute_parameters(),
            ['itemid' => $itemid, 'lang' => $lang]
        );
        if (!\local_contenttranslator\config::parse_langs($lang)) {
            throw new \invalid_parameter_exception('Unknown language');
        }
        $item = item_manager::get_item($itemid);
        if (!$item) {
            throw new \moodle_exception('error:itemnotfound', 'local_contenttranslator');
        }
        $context = \context::instance_by_id($item->contextid);
        self::validate_context($context);
        require_capability('local/contenttranslator:translate', $context);
        $translation = api::translate_now($itemid, $lang, (int)$USER->id);
        return [
            'translationid' => (int)$translation->id,
            'status' => $translation->status,
            'text' => (string)($translation->text ?? ''),
            'suggestion' => (string)($translation->suggestion ?? ''),
            'error' => (string)($translation->failreason ?? ''),
        ];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'translationid' => new external_value(PARAM_INT, 'Translation id'),
            'status' => new external_value(PARAM_ALPHA, 'Status'),
            'text' => new external_value(PARAM_RAW, 'Translated text'),
            'suggestion' => new external_value(PARAM_RAW, 'Suggestion (for reviewed or locked translations)'),
            'error' => new external_value(PARAM_RAW, 'Failure reason'),
        ]);
    }
}
