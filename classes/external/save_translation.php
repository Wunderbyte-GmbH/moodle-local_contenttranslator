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
use local_contenttranslator\item_manager;
use local_contenttranslator\translation_manager;

/**
 * External function: save a human edited translation.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class save_translation extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'translationid' => new external_value(PARAM_INT, 'Translation id'),
            'text' => new external_value(PARAM_RAW, 'Translated text'),
            'format' => new external_value(PARAM_INT, 'Text format', VALUE_DEFAULT, FORMAT_HTML),
            'review' => new external_value(PARAM_BOOL, 'Mark reviewed', VALUE_DEFAULT, false),
            'timemodified' => new external_value(PARAM_INT, 'Last known timemodified (concurrency check)', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Execute.
     *
     * @param int $translationid
     * @param string $text
     * @param int $format
     * @param bool $review
     * @param int $timemodified
     * @return array
     */
    public static function execute(
        int $translationid,
        string $text,
        int $format = FORMAT_HTML,
        bool $review = false,
        int $timemodified = 0
    ): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), [
            'translationid' => $translationid, 'text' => $text, 'format' => $format, 'review' => $review,
            'timemodified' => $timemodified,
        ]);
        $translation = translation_manager::get($params['translationid']);
        $item = item_manager::get_item((int)$translation->itemid);
        $context = \context::instance_by_id($item->contextid);
        self::validate_context($context);
        require_capability('local/contenttranslator:translate', $context);
        if ($params['review']) {
            require_capability('local/contenttranslator:review', $context);
        }
        if ($params['timemodified'] && $translation->timemodified > $params['timemodified']) {
            throw new \moodle_exception('error:concurrentedit', 'local_contenttranslator');
        }
        $text = $item->isstring ? strip_tags($params['text']) : clean_text($params['text'], $params['format']);
        $translation = translation_manager::save_human($translation, $text, $params['format'], (int)$USER->id, $params['review']);
        return ['status' => $translation->status, 'timemodified' => (int)$translation->timemodified];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_ALPHA, 'Status'),
            'timemodified' => new external_value(PARAM_INT, 'New timemodified'),
        ]);
    }
}
