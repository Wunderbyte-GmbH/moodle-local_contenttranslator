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
 * External function: review workflow actions on a translation.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class set_status extends external_api {
    /** Allowed actions */
    public const ACTIONS = ['review', 'lock', 'unlock', 'acceptsuggestion', 'keepprevious', 'requeue', 'delete', 'rollback'];

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'translationid' => new external_value(PARAM_INT, 'Translation id'),
            'action' => new external_value(PARAM_ALPHA, 'One of ' . implode(', ', self::ACTIONS)),
            'historyid' => new external_value(PARAM_INT, 'History id for rollback', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Apply an action (shared with the editor page).
     *
     * @param \stdClass $translation
     * @param string $action
     * @param int $userid
     * @param int $historyid
     * @param \context $context
     */
    public static function apply(\stdClass $translation, string $action, int $userid, int $historyid, \context $context): void {
        switch ($action) {
            case 'review':
            case 'keepprevious':
                require_capability('local/contenttranslator:review', $context);
                translation_manager::mark_reviewed($translation, $userid);
                break;
            case 'acceptsuggestion':
                require_capability('local/contenttranslator:review', $context);
                translation_manager::accept_suggestion($translation, $userid);
                break;
            case 'lock':
            case 'unlock':
                require_capability('local/contenttranslator:review', $context);
                translation_manager::set_locked($translation, $action === 'lock', $userid);
                break;
            case 'requeue':
                require_capability('local/contenttranslator:translate', $context);
                translation_manager::requeue($translation, $userid);
                break;
            case 'delete':
                require_capability('local/contenttranslator:review', $context);
                translation_manager::delete($translation, $userid);
                break;
            case 'rollback':
                require_capability('local/contenttranslator:translate', $context);
                translation_manager::rollback($translation, $historyid, $userid);
                break;
            default:
                throw new \invalid_parameter_exception('Unknown action ' . $action);
        }
    }

    /**
     * Execute.
     *
     * @param int $translationid
     * @param string $action
     * @param int $historyid
     * @return array
     */
    public static function execute(int $translationid, string $action, int $historyid = 0): array {
        global $USER;
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['translationid' => $translationid, 'action' => $action, 'historyid' => $historyid]
        );
        if (!in_array($params['action'], self::ACTIONS, true)) {
            throw new \invalid_parameter_exception('Unknown action');
        }
        $translation = translation_manager::get($params['translationid']);
        $item = item_manager::get_item((int)$translation->itemid);
        $context = \context::instance_by_id($item->contextid);
        self::validate_context($context);
        self::apply($translation, $params['action'], (int)$USER->id, $params['historyid'], $context);
        $status = $params['action'] === 'delete' ? 'deleted' : translation_manager::get($params['translationid'])->status;
        return ['status' => $status];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_ALPHA, 'Status after the action'),
        ]);
    }
}
