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
 * Backup of translations at course, section and module level.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_local_contenttranslator_plugin extends backup_local_plugin {
    /**
     * Course level: items of the course record itself.
     *
     * @return backup_plugin_element
     */
    protected function define_course_plugin_structure() {
        $plugin = $this->get_plugin_element();
        $wrapper = new backup_nested_element($this->get_recommended_name());
        $plugin->add_child($wrapper);
        $this->add_items(
            $wrapper,
            'i.courseid = ? AND i.itemtype = ?',
            [backup::VAR_COURSEID, backup_helper::is_sqlparam('course')]
        );
        return $plugin;
    }

    /**
     * Section level.
     *
     * @return backup_plugin_element
     */
    protected function define_section_plugin_structure() {
        $plugin = $this->get_plugin_element();
        $wrapper = new backup_nested_element($this->get_recommended_name());
        $plugin->add_child($wrapper);
        $this->add_items(
            $wrapper,
            'i.itemid = ? AND i.itemtype = ?',
            [backup::VAR_SECTIONID, backup_helper::is_sqlparam('course_sections')]
        );
        return $plugin;
    }

    /**
     * Module level: every item in the module context (main table and sub-tables).
     *
     * @return backup_plugin_element
     */
    protected function define_module_plugin_structure() {
        $plugin = $this->get_plugin_element();
        $wrapper = new backup_nested_element($this->get_recommended_name());
        $plugin->add_child($wrapper);
        $this->add_items($wrapper, 'i.contextid = ?', [backup::VAR_CONTEXTID]);
        return $plugin;
    }

    /**
     * Shared structure: items with their translations.
     *
     * @param backup_nested_element $wrapper
     * @param string $where
     * @param array $params
     */
    private function add_items(backup_nested_element $wrapper, string $where, array $params): void {
        $items = new backup_nested_element('cttranslationitems');
        $item = new backup_nested_element('cttranslationitem', ['id'], [
            'component', 'itemtype', 'field', 'itemid', 'label', 'sourcelang', 'langlocked', 'sourcehash', 'sourcetext',
            'sourceformat', 'isstring', 'chars', 'excluded', 'timecreated', 'timemodified',
        ]);
        $translations = new backup_nested_element('cttranslations');
        $translation = new backup_nested_element('cttranslation', ['id'], [
            'targetlang', 'text', 'format', 'status', 'origin', 'locked', 'sourcehash', 'sourcesnapshot', 'engine', 'model',
            'chars', 'reviewerid', 'usermodified', 'timecreated', 'timemodified', 'timereviewed',
        ]);
        $wrapper->add_child($items);
        $items->add_child($item);
        $item->add_child($translations);
        $translations->add_child($translation);

        $item->set_source_sql("SELECT i.* FROM {local_contenttranslator_item} i WHERE $where AND i.excluded = 0", $params);
        $translation->set_source_table('local_contenttranslator_tr', ['itemid' => backup::VAR_PARENTID]);
        $translation->annotate_ids('user', 'reviewerid');
        $translation->annotate_ids('user', 'usermodified');
    }
}
