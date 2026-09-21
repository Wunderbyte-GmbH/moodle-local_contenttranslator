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

namespace local_contenttranslator\event;

/**
 * Event: the data-protection consent for the Wunderbyte free trial was given.
 *
 * Recorded server-side when the trial key is requested with the consent flag set, so there is a durable
 * record of who agreed and when. The key request is gated on this consent.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class trial_consent_given extends \core\event\base {
    #[\Override]
    protected function init(): void {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    #[\Override]
    public static function get_name(): string {
        return get_string('event:trial_consent_given', 'local_contenttranslator');
    }

    #[\Override]
    public function get_description(): string {
        return "The user with id '$this->userid' confirmed the data-protection consent and requested the "
            . "Wunderbyte free trial for the content translator.";
    }
}
