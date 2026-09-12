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
 * Restore of translations at course, section and module level.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_local_contenttranslator_plugin extends restore_local_plugin {
    /** @var array Items waiting for the activity id (module level) */
    private array $pendingitems = [];

    /** @var array Translations waiting for their item, keyed by old item id */
    private array $pendingtranslations = [];

    /**
     * Course level paths.
     *
     * @return restore_path_element[]
     */
    protected function define_course_plugin_structure() {
        return $this->paths('course');
    }

    /**
     * Section level paths.
     *
     * @return restore_path_element[]
     */
    protected function define_section_plugin_structure() {
        return $this->paths('section');
    }

    /**
     * Module level paths.
     *
     * @return restore_path_element[]
     */
    protected function define_module_plugin_structure() {
        return $this->paths('module');
    }

    /**
     * Shared path definitions.
     *
     * @param string $level
     * @return restore_path_element[]
     */
    private function paths(string $level): array {
        $base = $this->get_pathfor('/cttranslationitems/cttranslationitem');
        return [
            new restore_path_element('cttranslationitem_' . $level, $base),
            new restore_path_element('cttranslation_' . $level, $base . '/cttranslations/cttranslation'),
        ];
    }

    /**
     * Course item.
     *
     * @param array $data
     */
    public function process_cttranslationitem_course(array $data): void {
        $courseid = (int)$this->task->get_courseid();
        $this->store_item((object)$data, $courseid, context_course::instance($courseid)->id);
    }

    /**
     * Course translation.
     *
     * @param array $data
     */
    public function process_cttranslation_course(array $data): void {
        $this->store_translation((object)$data);
    }

    /**
     * Section item.
     *
     * @param array $data
     */
    public function process_cttranslationitem_section(array $data): void {
        $contextid = context_course::instance($this->task->get_courseid())->id;
        $this->store_item((object)$data, (int)$this->task->get_sectionid(), $contextid);
    }

    /**
     * Section translation.
     *
     * @param array $data
     */
    public function process_cttranslation_section(array $data): void {
        $this->store_translation((object)$data);
    }

    /**
     * Module item: the new instance / sub-table ids are only known after the activity is restored, so buffer.
     *
     * @param array $data
     */
    public function process_cttranslationitem_module(array $data): void {
        $this->pendingitems[] = (object)$data;
    }

    /**
     * Module translation: buffered with its item.
     *
     * @param array $data
     */
    public function process_cttranslation_module(array $data): void {
        $data = (object)$data;
        $olditemrecordid = $this->get_current_item_old_id();
        $this->pendingtranslations[$olditemrecordid][] = $data;
    }

    /**
     * Old id of the item element currently being processed (parent of the translation).
     *
     * @return int
     */
    private function get_current_item_old_id(): int {
        $data = $this->get_current_parent_data();
        return (int)($data['id'] ?? 0);
    }

    /**
     * Data of the parent path element (the item) while processing a translation.
     *
     * @return array
     */
    private function get_current_parent_data(): array {
        // Translations are nested in the item element; the restore structure parser keeps the parent's data.
        $item = end($this->pendingitems);
        return $item ? (array)$item : [];
    }

    /**
     * Module level: resolve ids after the whole activity has been restored.
     */
    public function after_restore_module(): void {
        $modname = $this->task->get_modulename();
        $newinstanceid = (int)$this->task->get_activityid();
        $contextid = (int)$this->task->get_contextid();
        $registry = \local_contenttranslator\source\registry::get();
        foreach ($this->pendingitems as $item) {
            if ($item->itemtype === $modname) {
                $newitemid = $newinstanceid;
            } else {
                $source = $registry->get_source($item->component, $item->itemtype);
                $mapping = $source ? $source->get_restore_mapping() : null;
                $candidates = array_filter([$mapping, $item->itemtype, rtrim($item->itemtype, 's')]);
                $newitemid = 0;
                foreach ($candidates as $candidate) {
                    $newitemid = (int)$this->get_mappingid($candidate, $item->itemid, 0);
                    if ($newitemid) {
                        break;
                    }
                }
                if (!$newitemid) {
                    continue;
                }
            }
            $newid = $this->store_item($item, $newitemid, $contextid);
            foreach ($this->pendingtranslations[(int)$item->id] ?? [] as $translation) {
                $this->store_translation($translation, $newid);
            }
        }
        $this->pendingitems = [];
        $this->pendingtranslations = [];
    }

    /** @var int Id of the last item stored (course/section levels process children right after the parent) */
    private int $lastitemid = 0;

    /**
     * Insert or update an item record.
     *
     * @param stdClass $data
     * @param int $newitemid
     * @param int $contextid
     * @return int New item record id.
     */
    private function store_item(stdClass $data, int $newitemid, int $contextid): int {
        global $DB;
        $courseid = (int)$this->task->get_courseid();
        $record = (object)[
            'component' => $data->component,
            'itemtype' => $data->itemtype,
            'field' => $data->field,
            'itemid' => $newitemid,
            'contextid' => $contextid,
            'courseid' => $courseid,
            'categoryid' => (int)($DB->get_field('course', 'category', ['id' => $courseid]) ?: 0),
            'label' => $data->label,
            'sourcelang' => $data->sourcelang,
            'langlocked' => (int)$data->langlocked,
            'sourcehash' => $data->sourcehash,
            'sourcetext' => $data->sourcetext,
            'sourceformat' => (int)$data->sourceformat,
            'isstring' => (int)$data->isstring,
            'chars' => (int)$data->chars,
            'excluded' => 0,
            'timechecked' => 0,
            'timecreated' => (int)$data->timecreated,
            'timemodified' => time(),
        ];
        $existing = $DB->get_record('local_contenttranslator_item', [
            'component' => $record->component, 'itemtype' => $record->itemtype, 'field' => $record->field, 'itemid' => $newitemid,
        ]);
        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record('local_contenttranslator_item', $record);
            $this->lastitemid = (int)$existing->id;
        } else {
            $this->lastitemid = (int)$DB->insert_record('local_contenttranslator_item', $record);
        }
        \local_contenttranslator\cache_helper::invalidate($record->sourcehash, $courseid, (int)$record->categoryid);
        return $this->lastitemid;
    }

    /**
     * Insert a translation for the last stored item.
     *
     * @param stdClass $data
     * @param int|null $itemid
     */
    private function store_translation(stdClass $data, ?int $itemid = null): void {
        global $DB;
        $itemid = $itemid ?? $this->lastitemid;
        if (!$itemid) {
            return;
        }
        $record = (object)[
            'itemid' => $itemid,
            'targetlang' => $data->targetlang,
            'text' => $data->text,
            'format' => (int)$data->format,
            'status' => $data->status,
            'origin' => $data->origin,
            'locked' => (int)$data->locked,
            'sourcehash' => $data->sourcehash,
            'sourcesnapshot' => $data->sourcesnapshot ?? null,
            'engine' => $data->engine,
            'model' => $data->model,
            'chars' => (int)$data->chars,
            'reviewerid' => (int)$this->get_mappingid('user', $data->reviewerid, 0),
            'usermodified' => (int)$this->get_mappingid('user', $data->usermodified, 0),
            'timecreated' => (int)$data->timecreated,
            'timemodified' => (int)$data->timemodified,
            'timereviewed' => (int)$data->timereviewed,
        ];
        $existing = $DB->get_record('local_contenttranslator_tr', ['itemid' => $itemid, 'targetlang' => $record->targetlang]);
        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record('local_contenttranslator_tr', $record);
        } else {
            $DB->insert_record('local_contenttranslator_tr', $record);
        }
    }
}
