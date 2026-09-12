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

use context;

/**
 * Tenant boundary resolution for translation memory and render lookups.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class tenant {
    /** Site wide */
    public const BOUNDARY_NONE = 'none';
    /** Tenant = course */
    public const BOUNDARY_COURSE = 'course';
    /** Tenant = course category */
    public const BOUNDARY_CATEGORY = 'category';

    /**
     * Configured boundary.
     *
     * @return string
     */
    public static function get_boundary(): string {
        $boundary = get_config('local_contenttranslator', 'tenantboundary');
        return in_array($boundary, [self::BOUNDARY_COURSE, self::BOUNDARY_CATEGORY], true) ? $boundary : self::BOUNDARY_NONE;
    }

    /**
     * Tenant key for an item.
     *
     * @param int $courseid
     * @param int $categoryid
     * @return string
     */
    public static function key(int $courseid, int $categoryid = 0): string {
        switch (self::get_boundary()) {
            case self::BOUNDARY_COURSE:
                return $courseid > 0 ? 'course:' . $courseid : 'site';
            case self::BOUNDARY_CATEGORY:
                if ($categoryid <= 0 && $courseid > 0) {
                    $categoryid = (int)get_course($courseid)->category;
                }
                return $categoryid > 0 ? 'category:' . $categoryid : 'site';
            default:
                return 'site';
        }
    }

    /**
     * Tenant key for a render context.
     *
     * @param context|null $context
     * @return string
     */
    public static function key_for_context(?context $context): string {
        if (self::get_boundary() === self::BOUNDARY_NONE || $context === null) {
            return 'site';
        }
        if ($context->contextlevel == CONTEXT_COURSECAT) {
            return self::key(0, (int)$context->instanceid);
        }
        $coursecontext = $context->get_course_context(false);
        if ($coursecontext && $coursecontext->instanceid != SITEID) {
            return self::key((int)$coursecontext->instanceid);
        }
        return 'site';
    }
}
