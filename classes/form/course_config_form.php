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

namespace local_contenttranslator\form;

use local_contenttranslator\config;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Per course settings: on/off, target languages, visibility, external engines.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_config_form extends \moodleform {
    #[\Override]
    protected function definition(): void {
        $mform = $this->_form;
        $effective = $this->_customdata['effective'];
        $mform->addElement('hidden', 'courseid');
        $mform->setType('courseid', PARAM_INT);

        $inherit = get_string('inherit', 'local_contenttranslator');
        $mform->addElement('select', 'enabled', get_string('courseenabled', 'local_contenttranslator'), [
            -1 => $inherit . ' (' . ($effective->enabled ? get_string('yes') : get_string('no')) . ')',
            1 => get_string('yes'),
            0 => get_string('no'),
        ]);
        $mform->addHelpButton('enabled', 'courseenabled', 'local_contenttranslator');

        $mform->addElement(
            'advcheckbox',
            'inherittargetlangs',
            get_string('targetlangs', 'local_contenttranslator'),
            get_string('inheritfrom', 'local_contenttranslator', $this->_customdata['inheritedlabels']['targetlangs'])
            . ': ' . implode(', ', array_map([config::class, 'lang_name'], $effective->targetlangs))
        );
        $mform->setDefault('inherittargetlangs', 1);
        $langs = get_string_manager()->get_list_of_translations(true);
        $select = $mform->addElement('select', 'targetlangs', get_string('courselangs', 'local_contenttranslator'), $langs);
        $select->setMultiple(true);
        $mform->hideIf('targetlangs', 'inherittargetlangs', 'checked');

        $mform->addElement('select', 'visibility', get_string('visibility', 'local_contenttranslator'), [
            '' => $inherit,
            config::VISIBILITY_IMMEDIATE => get_string('visibility:immediate', 'local_contenttranslator'),
            config::VISIBILITY_REVIEWED => get_string('visibility:reviewed', 'local_contenttranslator'),
        ]);
        $mform->addHelpButton('visibility', 'visibility', 'local_contenttranslator');

        $mform->addElement('select', 'externalallowed', get_string('externalallowed', 'local_contenttranslator'), [
            -1 => $inherit . ' (' . ($effective->externalallowed ? get_string('yes') : get_string('no')) . ')',
            1 => get_string('yes'),
            0 => get_string('no'),
        ]);
        $mform->addHelpButton('externalallowed', 'externalallowed', 'local_contenttranslator');

        $this->add_action_buttons();
    }
}
