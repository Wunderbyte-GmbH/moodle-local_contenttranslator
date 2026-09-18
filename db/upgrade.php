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

    if ($oldversion < 2026091800) {
        // The built-in prompt used to be stored as the setting's default, which froze it on every site. Clear the
        // setting where it still holds that untouched copy, so the site follows the built-in prompt again.
        $oldprompt = "You are a professional translator for e-learning content.\n"
            . "Translate the following text from {sourcelang} into {targetlang}.\n\n"
            . "Rules:\n"
            . "- Return ONLY the translated text. No explanations, no quotes, no code fences, no preamble.\n"
            . "- Placeholders of the form <ph id=\"N\"/> stand for markup and must be kept EXACTLY as they are. "
            . "Keep every placeholder exactly once, keep their order, and place them so the sentence stays grammatical.\n"
            . "- Preserve line breaks and paragraph structure.\n"
            . "- Do not translate proper names, product names, course codes, file names or anything that looks like code.\n"
            . "- If the text is already in {targetlang}, return it unchanged.\n"
            . "{formality}{styleguide}{glossary}{context}\n"
            . "Text:\n{text}";
        $current = (string)get_config('local_contenttranslator', 'prompttemplate');
        // Saving the settings page turns line breaks into \r\n.
        if (trim(str_replace("\r\n", "\n", $current)) === $oldprompt) {
            set_config('prompttemplate', '', 'local_contenttranslator');
        }
        upgrade_plugin_savepoint(true, 2026091800, 'local', 'contenttranslator');
    }

    return true;
}
