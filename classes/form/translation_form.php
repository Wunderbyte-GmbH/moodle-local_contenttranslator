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

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Side-by-side editor form (translation side).
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class translation_form extends \moodleform {
    #[\Override]
    protected function definition(): void {
        $mform = $this->_form;
        $item = $this->_customdata['item'];
        $translation = $this->_customdata['translation'];
        $canreview = !empty($this->_customdata['canreview']);

        $mform->addElement('hidden', 'id', $translation->id);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'timemodified', $translation->timemodified);
        $mform->setType('timemodified', PARAM_INT);
        $mform->addElement('hidden', 'returnto');
        $mform->setType('returnto', PARAM_LOCALURL);

        if ($item->isstring) {
            $mform->addElement('text', 'text', get_string('translation', 'local_contenttranslator'), ['size' => 80]);
            $mform->setType('text', PARAM_TEXT);
            $mform->setDefault('text', (string)$translation->text);
        } else if ($translation->format == FORMAT_PLAIN || $item->sourceformat == FORMAT_PLAIN) {
            $mform->addElement(
                'textarea',
                'text',
                get_string('translation', 'local_contenttranslator'),
                ['rows' => 12, 'cols' => 80, 'class' => 'w-100']
            );
            $mform->setType('text', PARAM_RAW);
            $mform->setDefault('text', (string)$translation->text);
        } else {
            $mform->addElement(
                'editor',
                'texteditor',
                get_string('translation', 'local_contenttranslator'),
                ['rows' => 18],
                ['maxfiles' => 0, 'noclean' => false, 'context' => $this->_customdata['context'], 'autosave' => false]
            );
            $mform->setType('texteditor', PARAM_RAW);
            $mform->setDefault('texteditor', ['text' => (string)$translation->text, 'format' => FORMAT_HTML]);
        }

        $buttons = [];
        $buttons[] = $mform->createElement('submit', 'save', get_string('save', 'local_contenttranslator'));
        if ($canreview) {
            $buttons[] = $mform->createElement('submit', 'savereview', get_string('savereview', 'local_contenttranslator'));
        }
        $buttons[] = $mform->createElement('submit', 'savenext', get_string('savenext', 'local_contenttranslator'));
        $buttons[] = $mform->createElement('cancel');
        $mform->addGroup($buttons, 'buttons', '', ' ', false);
    }
}
