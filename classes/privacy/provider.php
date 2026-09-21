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

namespace local_contenttranslator\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider.
 *
 * Translations store who edited or reviewed them; the usage log stores who triggered engine calls.
 * Content text is authored content, not personal data; only the user references are exported and removed.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    #[\Override]
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_contenttranslator_tr', [
            'usermodified' => 'privacy:metadata:usermodified',
            'reviewerid' => 'privacy:metadata:reviewerid',
            'text' => 'privacy:metadata:text',
            'timemodified' => 'privacy:metadata:timemodified',
        ], 'privacy:metadata:tr');
        $collection->add_database_table('local_contenttranslator_hist', [
            'userid' => 'privacy:metadata:userid',
            'text' => 'privacy:metadata:text',
            'reason' => 'privacy:metadata:reason',
            'timecreated' => 'privacy:metadata:timecreated',
        ], 'privacy:metadata:hist');
        $collection->add_database_table('local_contenttranslator_use', [
            'userid' => 'privacy:metadata:userid',
            'engine' => 'privacy:metadata:engine',
            'chars' => 'privacy:metadata:chars',
            'timecreated' => 'privacy:metadata:timecreated',
        ], 'privacy:metadata:use');
        $collection->add_external_location_link('core_ai', [
            'text' => 'privacy:metadata:external:text',
            'sourcelang' => 'privacy:metadata:external:sourcelang',
            'targetlang' => 'privacy:metadata:external:targetlang',
        ], 'privacy:metadata:external');
        $collection->add_external_location_link('llm.wunderbyte.at', [
            'wwwroot' => 'privacy:metadata:trial:wwwroot',
            'ip' => 'privacy:metadata:trial:ip',
        ], 'privacy:metadata:trial');
        return $collection;
    }

    #[\Override]
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $sql = "SELECT i.contextid
                  FROM {local_contenttranslator_tr} t
                  JOIN {local_contenttranslator_item} i ON i.id = t.itemid
                 WHERE t.usermodified = :u1 OR t.reviewerid = :u2";
        $contextlist->add_from_sql($sql, ['u1' => $userid, 'u2' => $userid]);
        $sql = "SELECT i.contextid
                  FROM {local_contenttranslator_hist} h
                  JOIN {local_contenttranslator_item} i ON i.id = h.itemid
                 WHERE h.userid = :userid";
        $contextlist->add_from_sql($sql, ['userid' => $userid]);
        $sql = "SELECT u.contextid FROM {local_contenttranslator_use} u WHERE u.userid = :userid AND u.contextid > 0";
        $contextlist->add_from_sql($sql, ['userid' => $userid]);
        return $contextlist;
    }

    #[\Override]
    public static function get_users_in_context(userlist $userlist) {
        $contextid = $userlist->get_context()->id;
        $sql = "SELECT t.usermodified AS userid FROM {local_contenttranslator_tr} t
                  JOIN {local_contenttranslator_item} i ON i.id = t.itemid WHERE i.contextid = :ctx";
        $userlist->add_from_sql('userid', $sql, ['ctx' => $contextid]);
        $sql = "SELECT t.reviewerid AS userid FROM {local_contenttranslator_tr} t
                  JOIN {local_contenttranslator_item} i ON i.id = t.itemid WHERE i.contextid = :ctx";
        $userlist->add_from_sql('userid', $sql, ['ctx' => $contextid]);
        $sql = "SELECT h.userid FROM {local_contenttranslator_hist} h
                  JOIN {local_contenttranslator_item} i ON i.id = h.itemid WHERE i.contextid = :ctx";
        $userlist->add_from_sql('userid', $sql, ['ctx' => $contextid]);
        $sql = "SELECT u.userid FROM {local_contenttranslator_use} u WHERE u.contextid = :ctx";
        $userlist->add_from_sql('userid', $sql, ['ctx' => $contextid]);
    }

    #[\Override]
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            $sql = "SELECT t.id, t.targetlang, t.status, t.origin, t.timemodified, t.timereviewed, i.label, i.field,
                           t.usermodified, t.reviewerid
                      FROM {local_contenttranslator_tr} t
                      JOIN {local_contenttranslator_item} i ON i.id = t.itemid
                     WHERE i.contextid = :ctx AND (t.usermodified = :u1 OR t.reviewerid = :u2)";
            $rows = $DB->get_records_sql($sql, ['ctx' => $context->id, 'u1' => $userid, 'u2' => $userid]);
            $data = [];
            foreach ($rows as $row) {
                $data[] = [
                    'item' => $row->label . ' (' . $row->field . ')',
                    'language' => $row->targetlang,
                    'status' => $row->status,
                    'origin' => $row->origin,
                    'edited' => $row->usermodified == $userid,
                    'reviewed' => $row->reviewerid == $userid,
                    'timemodified' => transform::datetime($row->timemodified),
                ];
            }
            $sql = "SELECT h.id, h.targetlang, h.reason, h.timecreated, i.label, i.field
                      FROM {local_contenttranslator_hist} h
                      JOIN {local_contenttranslator_item} i ON i.id = h.itemid
                     WHERE i.contextid = :ctx AND h.userid = :userid";
            $history = [];
            foreach ($DB->get_records_sql($sql, ['ctx' => $context->id, 'userid' => $userid]) as $row) {
                $history[] = [
                    'item' => $row->label . ' (' . $row->field . ')',
                    'language' => $row->targetlang,
                    'reason' => $row->reason,
                    'timecreated' => transform::datetime($row->timecreated),
                ];
            }
            $usage = [];
            $rows = $DB->get_records('local_contenttranslator_use', ['contextid' => $context->id, 'userid' => $userid]);
            foreach ($rows as $row) {
                $usage[] = [
                    'engine' => $row->engine,
                    'targetlang' => $row->targetlang,
                    'chars' => $row->chars,
                    'trigger' => $row->triggertype,
                    'timecreated' => transform::datetime($row->timecreated),
                ];
            }
            if ($data || $history || $usage) {
                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'local_contenttranslator')],
                    (object)['translations' => $data, 'history' => $history, 'usage' => $usage]
                );
            }
        }
    }

    #[\Override]
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;
        $itemids = $DB->get_fieldset_select('local_contenttranslator_item', 'id', 'contextid = :ctx', ['ctx' => $context->id]);
        if ($itemids) {
            [$insql, $params] = $DB->get_in_or_equal($itemids, SQL_PARAMS_NAMED);
            $DB->execute("UPDATE {local_contenttranslator_tr} SET usermodified = 0, reviewerid = 0 WHERE itemid $insql", $params);
            $DB->execute("UPDATE {local_contenttranslator_hist} SET userid = 0 WHERE itemid $insql", $params);
        }
        $DB->set_field('local_contenttranslator_use', 'userid', 0, ['contextid' => $context->id]);
    }

    #[\Override]
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        $userid = (int)$contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            self::anonymise($userid, (int)$context->id);
        }
    }

    #[\Override]
    public static function delete_data_for_users(approved_userlist $userlist) {
        $contextid = (int)$userlist->get_context()->id;
        foreach ($userlist->get_userids() as $userid) {
            self::anonymise((int)$userid, $contextid);
        }
    }

    /**
     * Remove the user references (translations themselves are authored content and stay).
     *
     * @param int $userid
     * @param int $contextid
     */
    private static function anonymise(int $userid, int $contextid): void {
        global $DB;
        $itemids = $DB->get_fieldset_select('local_contenttranslator_item', 'id', 'contextid = :ctx', ['ctx' => $contextid]);
        if ($itemids) {
            [$insql, $params] = $DB->get_in_or_equal($itemids, SQL_PARAMS_NAMED);
            $params['userid'] = $userid;
            $DB->execute("UPDATE {local_contenttranslator_tr} SET usermodified = 0
                           WHERE usermodified = :userid AND itemid $insql", $params);
            $DB->execute("UPDATE {local_contenttranslator_tr} SET reviewerid = 0
                           WHERE reviewerid = :userid AND itemid $insql", $params);
            $DB->execute("UPDATE {local_contenttranslator_hist} SET userid = 0 WHERE userid = :userid AND itemid $insql", $params);
        }
        $DB->set_field('local_contenttranslator_use', 'userid', 0, ['contextid' => $contextid, 'userid' => $userid]);
    }
}
