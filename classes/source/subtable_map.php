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

namespace local_contenttranslator\source;

/**
 * Declarative map of module sub-tables and the column skip list for auto-discovery.
 *
 * Admins extend the map through the "subtablemap" setting (JSON with the same structure).
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class subtable_map {
    /** @var array|null Cached excluded fields */
    private static ?array $excluded = null;

    /**
     * Shipped sub-table definitions.
     *
     * @return array table => definition
     */
    public static function defaults(): array {
        return [
            'book_chapters' => [
                'module' => 'book',
                'parentfield' => 'bookid',
                'labelfield' => 'title',
                'restoremapping' => 'book_chapter',
                'editurl' => '/mod/book/edit.php?cmid={cmid}&id={id}',
                'fields' => [
                    'title' => ['string' => true, 'format' => FORMAT_PLAIN],
                    'content' => ['formatfield' => 'contentformat'],
                ],
            ],
            'choice_options' => [
                'module' => 'choice',
                'parentfield' => 'choiceid',
                'labelfield' => 'text',
                'restoremapping' => 'choice_option',
                'editurl' => '/course/modedit.php?update={cmid}',
                'fields' => [
                    'text' => ['string' => true, 'format' => FORMAT_PLAIN],
                ],
            ],
            'lesson_pages' => [
                'module' => 'lesson',
                'parentfield' => 'lessonid',
                'labelfield' => 'title',
                'restoremapping' => 'lesson_page',
                'editurl' => '/mod/lesson/editpage.php?id={cmid}&pageid={id}&edit=1',
                'fields' => [
                    'title' => ['string' => true, 'format' => FORMAT_PLAIN],
                    'contents' => ['formatfield' => 'contentsformat'],
                ],
            ],
            'feedback_item' => [
                'module' => 'feedback',
                'parentfield' => 'feedback',
                'labelfield' => 'name',
                'restoremapping' => 'feedback_item',
                'editurl' => '/mod/feedback/edit.php?id={cmid}',
                'fields' => [
                    'name' => ['string' => true, 'format' => FORMAT_PLAIN],
                    'label' => ['string' => true, 'format' => FORMAT_PLAIN],
                ],
            ],
            'booking_options' => [
                'module' => 'booking',
                'parentfield' => 'bookingid',
                'labelfield' => 'text',
                'restoremapping' => 'booking_option',
                'editurl' => '/mod/booking/editoptions.php?id={cmid}&optionid={id}',
                'fields' => [
                    'text' => ['string' => true, 'format' => FORMAT_PLAIN],
                    'description' => ['formatfield' => 'descriptionformat'],
                    'location' => ['string' => true, 'format' => FORMAT_PLAIN],
                    'institution' => ['string' => true, 'format' => FORMAT_PLAIN],
                    'address' => ['string' => true, 'format' => FORMAT_PLAIN],
                    'beforebookedtext' => [],
                    'beforecompletedtext' => [],
                    'aftercompletedtext' => [],
                    'notificationtext' => [],
                ],
            ],
        ];
    }

    /**
     * Effective map: defaults merged with the admin JSON, restricted to installed tables.
     *
     * @return array
     */
    public static function get(): array {
        global $DB;
        $map = self::defaults();
        $json = trim((string)get_config('local_contenttranslator', 'subtablemap'));
        if ($json !== '') {
            $extra = json_decode($json, true);
            if (is_array($extra)) {
                foreach ($extra as $table => $def) {
                    if (empty($def['module']) || empty($def['parentfield']) || empty($def['fields'])) {
                        continue;
                    }
                    $fields = [];
                    foreach ($def['fields'] as $key => $value) {
                        if (is_int($key)) {
                            $fields[$value] = [];
                        } else {
                            $fields[$key] = is_array($value) ? $value : [];
                        }
                    }
                    $def['fields'] = $fields;
                    $map[$table] = $def;
                }
            }
        }
        $dbman = $DB->get_manager();
        $result = [];
        foreach ($map as $table => $def) {
            if (!$dbman->table_exists($table) || !$dbman->table_exists($def['module'])) {
                continue;
            }
            $result[$table] = $def;
        }
        return $result;
    }

    /**
     * Column skip patterns for auto-discovery (fnmatch style).
     *
     * @return string[]
     */
    public static function skip_patterns(): array {
        $defaults = [
            '*format', '*options', '*template*', 'params', 'config*', '*url*', 'path', 'reference',
            'structure', 'participants', '*password*', '*pass', 'instructorcustomparameters', 'keywords',
            '*hash', 'json', '*settings*', 'questions', '*css*', '*js*', 'icon', 'toolurl', 'securetoolurl',
            'subnet', 'grade_item', 'legacyfiles*', 'introattachment', 'displayoptions', 'customfield*',
            'timemodified', 'timecreated', 'sortorder',
            // Settings of mod_booking: comma separated field lists (responsesfields, reportfields, optionsfields,
            // optionsdownloadfields, signinsheetfields), not content.
            'booking.*fields', 'lesson.conditions', 'lti.secureicon', 'booking.banusernames', 'booking.categoryid',
        ];
        $custom = trim((string)get_config('local_contenttranslator', 'skipcolumns'));
        if ($custom !== '') {
            $defaults = array_merge($defaults, preg_split('/[\s,]+/', $custom, -1, PREG_SPLIT_NO_EMPTY));
        }
        return $defaults;
    }

    /**
     * Whether a discovered column must be skipped.
     *
     * @param string $table
     * @param string $column
     * @return bool
     */
    public static function is_skipped_column(string $table, string $column): bool {
        foreach (self::skip_patterns() as $pattern) {
            if (fnmatch($pattern, $column) || fnmatch($pattern, $table . '.' . $column)) {
                return true;
            }
        }
        return self::is_excluded_field($table, $column);
    }

    /**
     * Fields excluded by the admin ("table.field" per line), e.g. "course.shortname".
     *
     * @param string $table
     * @param string $field
     * @return bool
     */
    public static function is_excluded_field(string $table, string $field): bool {
        if (self::$excluded === null) {
            self::$excluded = [];
            $raw = trim((string)get_config('local_contenttranslator', 'excludedfields'));
            if ($raw !== '') {
                foreach (preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) as $line) {
                    self::$excluded[strtolower($line)] = true;
                }
            }
        }
        return isset(self::$excluded[strtolower($table . '.' . $field)]);
    }

    /**
     * Reset static caches (tests, settings changes).
     */
    public static function reset(): void {
        self::$excluded = null;
    }
}
