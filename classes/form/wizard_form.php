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

use local_contenttranslator\budget;
use local_contenttranslator\config;
use local_contenttranslator\engine\engine_manager;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Setup wizard: engine, target languages, service user, AI policy and monthly budget.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class wizard_form extends \moodleform {
    #[\Override]
    protected function definition(): void {
        $mform = $this->_form;

        $mform->addElement('header', 'enginehdr', get_string('wizard:engine', 'local_contenttranslator'));
        $mform->addElement('select', 'engine', get_string('engine', 'local_contenttranslator'), engine_manager::get_menu());
        $mform->setDefault('engine', config::get('engine', 'core_ai'));
        $mform->addHelpButton('engine', 'engine', 'local_contenttranslator');
        $mform->addElement('text', 'price', get_string('price', 'local_contenttranslator'), ['size' => 8]);
        $mform->setType('price', PARAM_FLOAT);
        $mform->setDefault('price', budget::get_price((string)config::get('engine', 'core_ai')));
        $mform->addHelpButton('price', 'price', 'local_contenttranslator');

        $mform->addElement('header', 'langhdr', get_string('wizard:languages', 'local_contenttranslator'));
        $langs = get_string_manager()->get_list_of_translations(true);
        $select = $mform->addElement('select', 'targetlangs', get_string('targetlangs', 'local_contenttranslator'), $langs);
        $select->setMultiple(true);
        $mform->setDefault('targetlangs', config::get_site_target_langs());
        $mform->addHelpButton('targetlangs', 'targetlangs', 'local_contenttranslator');

        $mform->addElement('header', 'userhdr', get_string('wizard:serviceuser', 'local_contenttranslator'));
        $mform->addElement('static', 'serviceuserinfo', '', get_string('serviceuser_desc', 'local_contenttranslator'));
        $options = [
            'ajax' => 'core_user/form_user_selector',
            'multiple' => false,
            'noselectionstring' => get_string('none'),
            'valuehtmlcallback' => function ($userid) {
                $user = \core_user::get_user($userid);
                return $user ? fullname($user) : '';
            },
        ];
        $mform->addElement('autocomplete', 'serviceuserid', get_string('serviceuser', 'local_contenttranslator'), [], $options);
        $mform->setDefault('serviceuserid', (int)config::get('serviceuserid', 0));
        $mform->addElement('advcheckbox', 'createserviceuser', '', get_string('createserviceuser', 'local_contenttranslator'));
        $mform->addElement('advcheckbox', 'acceptpolicy', '', get_string('acceptpolicy', 'local_contenttranslator'));
        $mform->addHelpButton('acceptpolicy', 'acceptpolicy', 'local_contenttranslator');

        $mform->addElement('header', 'budgethdr', get_string('wizard:budget', 'local_contenttranslator'));
        $mform->addElement('static', 'budgetinfo', '', get_string('budget_desc', 'local_contenttranslator'));
        $mform->addElement('text', 'budgetchars', get_string('budgetchars', 'local_contenttranslator'), ['size' => 12]);
        $mform->setType('budgetchars', PARAM_INT);
        $mform->setDefault('budgetchars', budget::get_limit() ?: 2000000);
        $mform->addElement('advcheckbox', 'enableauto', '', get_string('enableauto', 'local_contenttranslator'));
        $mform->setDefault('enableauto', (int)config::get('enableauto', 1));

        $this->add_action_buttons(true, get_string('savechanges'));
    }

    #[\Override]
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        if (!empty($data['enableauto']) && (int)$data['budgetchars'] <= 0) {
            $errors['budgetchars'] = get_string('error:budgetrequired', 'local_contenttranslator');
        }
        return $errors;
    }
}
