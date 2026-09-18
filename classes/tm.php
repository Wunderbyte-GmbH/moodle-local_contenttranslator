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

namespace local_contenttranslator;

/**
 * Exact match translation memory, isolated per tenant.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class tm {
    /** Human quality */
    public const QUALITY_HUMAN = 'human';
    /** Machine quality */
    public const QUALITY_MACHINE = 'machine';

    /**
     * Find an exact match.
     *
     * @param string $tenantkey
     * @param string $sourcelang
     * @param string $targetlang
     * @param string $sourcehash
     * @return \stdClass|null
     */
    public static function find(string $tenantkey, string $sourcelang, string $targetlang, string $sourcehash): ?\stdClass {
        global $DB;
        $record = $DB->get_record('local_contenttranslator_tm', [
            'tenantkey' => $tenantkey,
            'sourcelang' => $sourcelang,
            'targetlang' => $targetlang,
            'sourcehash' => $sourcehash,
        ]);
        return $record ?: null;
    }

    /**
     * Store or upgrade an entry. Human entries are never downgraded to machine ones.
     *
     * @param string $tenantkey
     * @param string $sourcelang
     * @param string $targetlang
     * @param string $sourcehash
     * @param string $sourcetext
     * @param string $targettext
     * @param int $format
     * @param string $quality
     */
    public static function store(
        string $tenantkey,
        string $sourcelang,
        string $targetlang,
        string $sourcehash,
        string $sourcetext,
        string $targettext,
        int $format,
        string $quality
    ): void {
        global $DB;
        $existing = self::find($tenantkey, $sourcelang, $targetlang, $sourcehash);
        $now = time();
        if ($existing) {
            if ($existing->quality === self::QUALITY_HUMAN && $quality !== self::QUALITY_HUMAN) {
                return;
            }
            $existing->targettext = $targettext;
            $existing->format = $format;
            $existing->quality = $quality;
            $existing->timemodified = $now;
            $DB->update_record('local_contenttranslator_tm', $existing);
            return;
        }
        $DB->insert_record('local_contenttranslator_tm', (object)[
            'tenantkey' => $tenantkey,
            'sourcelang' => $sourcelang,
            'targetlang' => $targetlang,
            'sourcehash' => $sourcehash,
            'sourcetext' => $sourcetext,
            'targettext' => $targettext,
            'format' => $format,
            'quality' => $quality,
            'usecount' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Count a reuse.
     *
     * @param int $id
     */
    public static function increment(int $id): void {
        global $DB;
        $DB->execute("UPDATE {local_contenttranslator_tm} SET usecount = usecount + 1 WHERE id = :id", ['id' => $id]);
    }

    /**
     * Whether two source texts carry the same markup. The memory is keyed on the visible text only, so a hit for
     * a text with different formatting (a paragraph instead of a code block, another link) must not be reused.
     *
     * @param string $a
     * @param string $b
     * @return bool
     */
    public static function same_markup(string $a, string $b): bool {
        return self::markup($a) === self::markup($b);
    }

    /**
     * The tags of a text in order, with file URLs neutralised and whitespace collapsed.
     *
     * @param string $text
     * @return string[]
     */
    private static function markup(string $text): array {
        $text = preg_replace(normaliser::FILEURL_REGEX, '@@FILE@@/', $text);
        preg_match_all('~<[^>]+>~', $text, $matches);
        return array_map(fn(string $tag): string => \core_text::strtolower(preg_replace('~\s+~', ' ', $tag)), $matches[0]);
    }
}
