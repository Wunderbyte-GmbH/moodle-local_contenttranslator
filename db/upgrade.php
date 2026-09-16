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
 * Upgrade steps for local_contenttranslator.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade the plugin.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_contenttranslator_upgrade(int $oldversion): bool {
    global $DB;

    if ($oldversion < 2026091600) {
        // Content without translatable text used to get a copy of its source as "translation", which froze that
        // source on the page. Queue those rows again: the pipeline now stores no copy and translates text that
        // appeared since.
        $DB->execute(
            "UPDATE {local_contenttranslator_tr}
                SET status = :queued, text = NULL
              WHERE engine = :engine AND status = :machine AND locked = 0",
            ['queued' => 'queued', 'engine' => 'none', 'machine' => 'machine']
        );
        upgrade_plugin_savepoint(true, 2026091600, 'local', 'contenttranslator');
    }

    return true;
}
