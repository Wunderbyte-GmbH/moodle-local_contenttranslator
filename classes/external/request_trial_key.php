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
use local_contenttranslator\event\trial_consent_given;
use local_contenttranslator\trial\trial_provisioner;

/**
 * External function: start the Wunderbyte free trial and provision an AI provider from it.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class request_trial_key extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'consented' => new external_value(
                PARAM_BOOL,
                'Whether the user confirmed the data-protection consent in the trial modal.',
                VALUE_DEFAULT,
                false
            ),
            'strategy' => new external_value(
                PARAM_ALPHA,
                'Provider path: "wunderbyte" or "openai" (standard provider). Empty = auto-detect.',
                VALUE_DEFAULT,
                ''
            ),
            'confirmoverwrite' => new external_value(
                PARAM_BOOL,
                'Moodle 4.5 only: the admin confirmed that an existing provider configuration is replaced.',
                VALUE_DEFAULT,
                false
            ),
        ]);
    }

    /**
     * Start the trial: reuse the Wunderbyte provider of this site or request a key and create one.
     *
     * @param bool $consented
     * @param string $strategy
     * @param bool $confirmoverwrite
     * @return array
     */
    public static function execute(bool $consented = false, string $strategy = '', bool $confirmoverwrite = false): array {
        global $USER;

        require_sesskey();

        $params = self::validate_parameters(self::execute_parameters(), [
            'consented' => $consented,
            'strategy' => $strategy,
            'confirmoverwrite' => $confirmoverwrite,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);
        // Writes site-wide AI provider configuration: system context only.
        require_capability('local/contenttranslator:requesttrial', $context);

        $provisioner = new trial_provisioner();

        // GDPR gate: a key is only requested from Wunderbyte after the consent shown in the modal was confirmed.
        // Refuse otherwise (defends against direct web service calls that bypass the UI). Reusing a provider
        // that is already set up transmits nothing to Wunderbyte and needs no consent.
        if (!$provisioner->has_wunderbyte_provider()) {
            if (empty($params['consented'])) {
                return [
                    'success' => false,
                    'message' => get_string('trial_consent_required', 'local_contenttranslator'),
                    'code' => 'noconsent',
                ];
            }
            // Durable audit trail, recorded before anything is requested.
            trial_consent_given::create(['context' => $context, 'userid' => (int)$USER->id])->trigger();
        }

        $strategy = in_array($params['strategy'], ['wunderbyte', 'openai'], true) ? $params['strategy'] : null;
        return $provisioner->provision($strategy, !empty($params['confirmoverwrite']));
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether a working provider is set up now.'),
            'message' => new external_value(PARAM_RAW, 'User-facing status message.'),
            'code' => new external_value(PARAM_ALPHANUMEXT, 'Machine readable outcome.'),
        ]);
    }
}
