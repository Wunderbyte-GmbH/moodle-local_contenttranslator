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

namespace local_contenttranslator\check;

use core\check\check;
use core\check\result;
use local_contenttranslator\budget;
use local_contenttranslator\config;
use local_contenttranslator\engine\engine_manager;

/**
 * Status check: setup, budget, filter position and service user.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setup_check extends check {
    #[\Override]
    public function get_name(): string {
        return get_string('check:setup', 'local_contenttranslator');
    }

    #[\Override]
    public function get_action_link(): ?\action_link {
        return new \action_link(
            new \moodle_url('/local/contenttranslator/wizard.php'),
            get_string('wizard', 'local_contenttranslator')
        );
    }

    #[\Override]
    public function get_result(): result {
        global $CFG;
        $problems = [];
        $status = result::OK;

        if (!config::get_site_target_langs()) {
            $problems[] = get_string('check:notargetlangs', 'local_contenttranslator');
            $status = result::WARNING;
        }
        if (budget::get_limit() <= 0) {
            $problems[] = get_string('check:nobudget', 'local_contenttranslator');
            $status = result::WARNING;
        }

        // Filter plugin.
        $filters = filter_get_global_states();
        if (!isset($filters['contenttranslator'])) {
            $problems[] = get_string('check:filtermissing', 'local_contenttranslator');
            $status = result::ERROR;
        } else {
            $state = $filters['contenttranslator'];
            if ($state->active != TEXTFILTER_ON) {
                $problems[] = get_string('check:filteroff', 'local_contenttranslator');
                $status = result::ERROR;
            } else {
                $active = array_filter($filters, fn($s) => $s->active == TEXTFILTER_ON);
                uasort($active, fn($a, $b) => $a->sortorder <=> $b->sortorder);
                if (array_key_first($active) !== 'contenttranslator') {
                    $problems[] = get_string('check:filternotfirst', 'local_contenttranslator');
                    $status = $status === result::ERROR ? $status : result::WARNING;
                }
            }
        }
        if (empty($CFG->filterall)) {
            $problems[] = get_string('check:filterall', 'local_contenttranslator');
            $status = $status === result::ERROR ? $status : result::WARNING;
        } else if (isset($filters['contenttranslator']) && !in_array('contenttranslator', filter_get_string_filters(), true)) {
            // Filter all strings is on because another filter applies to headings, but ours only applies to content:
            // format_string() skips it, so course, section and activity names stay untranslated.
            $problems[] = get_string('check:filterheadings', 'local_contenttranslator');
            $status = $status === result::ERROR ? $status : result::WARNING;
        }

        // Service user for automatic core_ai calls.
        $engine = engine_manager::get_engine((string)config::get('engine', 'core_ai'));
        if ($engine && $engine->is_external() && budget::is_automation_enabled()) {
            $serviceuserid = (int)config::get('serviceuserid', 0);
            if (!$serviceuserid) {
                $problems[] = get_string('check:noserviceuser', 'local_contenttranslator');
                $status = $status === result::ERROR ? $status : result::WARNING;
            } else if (!$engine->is_available_for_user($serviceuserid)) {
                $problems[] = get_string('check:serviceuserpolicy', 'local_contenttranslator');
                $status = $status === result::ERROR ? $status : result::WARNING;
            }
        }
        if ($engine && !$engine->is_available()) {
            $problems[] = get_string('check:engineunavailable', 'local_contenttranslator', $engine->get_display_name());
            $status = $status === result::ERROR ? $status : result::WARNING;
        }

        if (!$problems) {
            return new result(result::OK, get_string('check:ok', 'local_contenttranslator'));
        }
        return new result(
            $status,
            get_string('check:problems', 'local_contenttranslator', count($problems)),
            \html_writer::alist($problems)
        );
    }
}
