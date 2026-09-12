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

namespace local_contenttranslator\task;

use local_contenttranslator\config;

/**
 * Scheduled task: history retention and orphan clean-up.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup_task extends \core\task\scheduled_task {
    #[\Override]
    public function get_name(): string {
        return get_string('task:cleanup', 'local_contenttranslator');
    }

    #[\Override]
    public function execute(): void {
        global $DB;
        // Translations whose item is gone.
        $DB->delete_records_select(
            'local_contenttranslator_tr',
            'itemid NOT IN (SELECT id FROM {local_contenttranslator_item})'
        );
        // History of deleted translations older than the retention period, and old history in general.
        $days = (int)config::get('historyretention', 365);
        if ($days > 0) {
            $cutoff = time() - $days * DAYSECS;
            $DB->delete_records_select('local_contenttranslator_hist', 'timecreated < :cutoff', ['cutoff' => $cutoff]);
        }
        $DB->delete_records_select(
            'local_contenttranslator_hist',
            'translationid NOT IN (SELECT id FROM {local_contenttranslator_tr}) AND timecreated < :cutoff',
            ['cutoff' => time() - 30 * DAYSECS]
        );
    }
}
