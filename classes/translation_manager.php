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
 * Translation records: status transitions, history, rollback, human edits.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class translation_manager {
    /** Waiting for an engine */
    public const STATUS_QUEUED = 'queued';
    /** Machine translated (or human edited, not yet reviewed) */
    public const STATUS_MACHINE = 'machine';
    /** Approved by a human */
    public const STATUS_REVIEWED = 'reviewed';
    /** Source changed since the translation was made */
    public const STATUS_STALE = 'stale';
    /** Engine error */
    public const STATUS_FAILED = 'failed';

    /** Origin: engine */
    public const ORIGIN_MACHINE = 'machine';
    /** Origin: human */
    public const ORIGIN_HUMAN = 'human';
    /** Origin: translation memory */
    public const ORIGIN_TM = 'tm';
    /** Origin: import */
    public const ORIGIN_IMPORT = 'import';

    /** All statuses */
    public const STATUSES = [
        self::STATUS_QUEUED, self::STATUS_MACHINE, self::STATUS_REVIEWED, self::STATUS_STALE, self::STATUS_FAILED,
    ];

    /**
     * Load a translation.
     *
     * @param int $id
     * @return \stdClass
     */
    public static function get(int $id): \stdClass {
        global $DB;
        return $DB->get_record('local_contenttranslator_tr', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Load the translation of an item in a language, if any.
     *
     * @param int $itemid
     * @param string $lang
     * @return \stdClass|null
     */
    public static function get_for_item(int $itemid, string $lang): ?\stdClass {
        global $DB;
        $record = $DB->get_record('local_contenttranslator_tr', ['itemid' => $itemid, 'targetlang' => $lang]);
        return $record ?: null;
    }

    /**
     * Get or create (queued) the translation row of an item in a language.
     *
     * @param int $itemid
     * @param string $lang
     * @return \stdClass
     */
    public static function ensure(int $itemid, string $lang): \stdClass {
        global $DB;
        $existing = self::get_for_item($itemid, $lang);
        if ($existing) {
            return $existing;
        }
        $now = time();
        $record = (object)[
            'itemid' => $itemid,
            'targetlang' => $lang,
            'text' => null,
            'format' => FORMAT_HTML,
            'status' => self::STATUS_QUEUED,
            'origin' => self::ORIGIN_MACHINE,
            'locked' => 0,
            'sourcehash' => null,
            'chars' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        try {
            $record->id = $DB->insert_record('local_contenttranslator_tr', $record);
        } catch (\dml_write_exception $e) {
            // Concurrent insert: reload.
            return self::get_for_item($itemid, $lang);
        }
        // Reload so that every column (defaults included) is present.
        return self::get((int)$record->id);
    }

    /**
     * Can the pipeline overwrite this translation with a new machine translation?
     *
     * @param \stdClass $translation
     * @return bool
     */
    public static function is_overwritable(\stdClass $translation): bool {
        if ($translation->locked) {
            return false;
        }
        if ($translation->status === self::STATUS_REVIEWED) {
            return false;
        }
        if ($translation->origin === self::ORIGIN_HUMAN) {
            return false;
        }
        return true;
    }

    /**
     * Write a history row for the current state of a translation.
     *
     * @param \stdClass $translation
     * @param string $reason
     * @param int $userid
     */
    public static function add_history(\stdClass $translation, string $reason, int $userid): void {
        global $DB;
        if ($translation->text === null || $translation->text === '') {
            return;
        }
        $DB->insert_record('local_contenttranslator_hist', (object)[
            'translationid' => $translation->id,
            'itemid' => $translation->itemid,
            'targetlang' => $translation->targetlang,
            'text' => $translation->text,
            'format' => $translation->format,
            'status' => $translation->status,
            'origin' => $translation->origin,
            'reason' => $reason,
            'userid' => $userid,
            'timecreated' => time(),
        ]);
    }

    /**
     * History of a translation, newest first.
     *
     * @param int $translationid
     * @return \stdClass[]
     */
    public static function get_history(int $translationid): array {
        global $DB;
        return $DB->get_records('local_contenttranslator_hist', ['translationid' => $translationid], 'timecreated DESC, id DESC');
    }

    /**
     * Persist changes, invalidate caches and fire the event.
     *
     * @param \stdClass $translation
     * @param string $reason
     * @param int $userid
     */
    private static function update(\stdClass $translation, string $reason, int $userid): void {
        global $DB;
        $translation->usermodified = $userid;
        $translation->timemodified = time();
        $DB->update_record('local_contenttranslator_tr', $translation);
        $item = item_manager::get_item((int)$translation->itemid);
        if ($item) {
            cache_helper::invalidate($item->sourcehash, (int)$item->courseid, (int)$item->categoryid);
            if (!empty($translation->sourcehash) && $translation->sourcehash !== $item->sourcehash) {
                cache_helper::invalidate($translation->sourcehash, (int)$item->courseid, (int)$item->categoryid);
            }
            event\translation_updated::create([
                'objectid' => $translation->id,
                'context' => \context::instance_by_id($item->contextid, IGNORE_MISSING) ?: \context_system::instance(),
                'relateduserid' => null,
                'other' => [
                    'itemid' => $item->id,
                    'targetlang' => $translation->targetlang,
                    'status' => $translation->status,
                    'reason' => $reason,
                ],
            ])->trigger();
        }
    }

    /**
     * Store a machine (or TM) translation produced by the pipeline.
     *
     * If the existing translation must not be overwritten, the new text is stored as a suggestion.
     *
     * @param \stdClass $translation
     * @param \stdClass $item
     * @param string $text
     * @param int $format
     * @param string $origin machine|tm
     * @param string $engine
     * @param string|null $model
     * @param int $chars
     * @param int $userid
     * @return \stdClass
     */
    public static function store_machine(
        \stdClass $translation,
        \stdClass $item,
        string $text,
        int $format,
        string $origin,
        string $engine,
        ?string $model,
        int $chars,
        int $userid
    ): \stdClass {
        if (!self::is_overwritable($translation) && !empty($translation->text)) {
            $translation->suggestion = $text;
            $translation->suggestionhash = $item->sourcehash;
            $translation->engine = $engine;
            $translation->model = $model;
            self::update($translation, 'suggestion', $userid);
            return $translation;
        }
        $reason = empty($translation->text) ? 'mt' : 'remt';
        if ($origin === self::ORIGIN_TM) {
            $reason = 'tm';
        }
        self::add_history($translation, $reason, $userid);
        $translation->text = $text;
        $translation->format = $format;
        $translation->status = self::STATUS_MACHINE;
        $translation->origin = $origin;
        $translation->sourcehash = $item->sourcehash;
        $translation->sourcesnapshot = $item->sourcetext;
        $translation->suggestion = null;
        $translation->suggestionhash = null;
        $translation->engine = $engine;
        $translation->model = $model;
        $translation->chars = $chars;
        $translation->failreason = null;
        self::update($translation, $reason, $userid);
        return $translation;
    }

    /**
     * Mark a translation as failed.
     *
     * @param \stdClass $translation
     * @param string $reason
     * @param int $userid
     */
    public static function mark_failed(\stdClass $translation, string $reason, int $userid): void {
        if (!self::is_overwritable($translation) && !empty($translation->text)) {
            // Keep the reviewed text; remember the failure only.
            $translation->failreason = $reason;
            self::update($translation, 'failed', $userid);
            return;
        }
        $translation->status = self::STATUS_FAILED;
        $translation->failreason = $reason;
        self::update($translation, 'failed', $userid);
    }

    /**
     * Save a human edit.
     *
     * @param \stdClass $translation
     * @param string $text
     * @param int $format
     * @param int $userid
     * @param bool $review Mark reviewed as well.
     * @return \stdClass
     */
    public static function save_human(\stdClass $translation, string $text, int $format, int $userid, bool $review): \stdClass {
        $item = item_manager::get_item((int)$translation->itemid);
        $changed = $text !== (string)$translation->text || $format != $translation->format;
        if ($changed) {
            self::add_history($translation, 'edit', $userid);
            $translation->text = $text;
            $translation->format = $format;
            $translation->origin = self::ORIGIN_HUMAN;
        }
        if ($item) {
            $translation->sourcehash = $item->sourcehash;
            $translation->sourcesnapshot = $item->sourcetext;
        }
        $translation->suggestion = null;
        $translation->suggestionhash = null;
        $translation->failreason = null;
        if ($review) {
            $translation->status = self::STATUS_REVIEWED;
            $translation->reviewerid = $userid;
            $translation->timereviewed = time();
        } else if ($changed || $translation->status !== self::STATUS_REVIEWED) {
            $translation->status = self::STATUS_MACHINE;
        }
        self::update($translation, $review ? 'review' : 'edit', $userid);
        if ($item && $changed) {
            tm::store(
                tenant::key((int)$item->courseid, (int)$item->categoryid),
                $item->sourcelang,
                $translation->targetlang,
                $item->sourcehash,
                (string)$item->sourcetext,
                $text,
                $format,
                tm::QUALITY_HUMAN
            );
        }
        return $translation;
    }

    /**
     * Mark reviewed (keeping the current text).
     *
     * @param \stdClass $translation
     * @param int $userid
     */
    public static function mark_reviewed(\stdClass $translation, int $userid): void {
        if (empty($translation->text)) {
            return;
        }
        $item = item_manager::get_item((int)$translation->itemid);
        $translation->status = self::STATUS_REVIEWED;
        $translation->reviewerid = $userid;
        $translation->timereviewed = time();
        $translation->suggestion = null;
        $translation->suggestionhash = null;
        if ($item) {
            $translation->sourcehash = $item->sourcehash;
            $translation->sourcesnapshot = $item->sourcetext;
        }
        self::update($translation, 'review', $userid);
        if ($item) {
            tm::store(
                tenant::key((int)$item->courseid, (int)$item->categoryid),
                $item->sourcelang,
                $translation->targetlang,
                $item->sourcehash,
                (string)$item->sourcetext,
                (string)$translation->text,
                (int)$translation->format,
                tm::QUALITY_HUMAN
            );
        }
    }

    /**
     * Accept the stored machine suggestion as the new text (reviewed).
     *
     * @param \stdClass $translation
     * @param int $userid
     */
    public static function accept_suggestion(\stdClass $translation, int $userid): void {
        if ($translation->suggestion === null) {
            return;
        }
        self::add_history($translation, 'suggestion', $userid);
        $translation->text = $translation->suggestion;
        $translation->sourcehash = $translation->suggestionhash;
        $item = item_manager::get_item((int)$translation->itemid);
        $translation->sourcesnapshot = $item ? $item->sourcetext : null;
        $translation->origin = self::ORIGIN_MACHINE;
        $translation->suggestion = null;
        $translation->suggestionhash = null;
        $translation->status = self::STATUS_REVIEWED;
        $translation->reviewerid = $userid;
        $translation->timereviewed = time();
        self::update($translation, 'review', $userid);
    }

    /**
     * Lock or unlock.
     *
     * @param \stdClass $translation
     * @param bool $locked
     * @param int $userid
     */
    public static function set_locked(\stdClass $translation, bool $locked, int $userid): void {
        $translation->locked = (int)$locked;
        self::update($translation, $locked ? 'lock' : 'unlock', $userid);
    }

    /**
     * Queue for (re-)translation. Reviewed or locked translations receive a suggestion instead.
     *
     * @param \stdClass $translation
     * @param int $userid
     */
    public static function requeue(\stdClass $translation, int $userid): void {
        if (self::is_overwritable($translation)) {
            $translation->status = self::STATUS_QUEUED;
        } else {
            $translation->suggestionhash = null; // Forces a fresh suggestion.
            $translation->status = self::STATUS_STALE;
        }
        $translation->failreason = null;
        self::update($translation, 'requeue', $userid);
    }

    /**
     * Queue passed through translations of an item again (engine "none": the source had nothing to translate).
     *
     * Called when only the markup of the source changed: content that was all code may now contain text.
     * Real machine translations, human translations and locked rows are left alone.
     *
     * @param \stdClass $item Item record with id, sourcehash, courseid and categoryid.
     * @return bool Whether a translation was queued.
     */
    public static function requeue_passthrough(\stdClass $item): bool {
        global $DB;
        $select = 'itemid = :itemid AND engine = :engine AND status = :machine AND locked = 0';
        $params = ['itemid' => (int)$item->id, 'engine' => 'none', 'machine' => self::STATUS_MACHINE];
        if (!$DB->record_exists_select('local_contenttranslator_tr', $select, $params)) {
            return false;
        }
        $DB->set_field_select('local_contenttranslator_tr', 'status', self::STATUS_QUEUED, $select, $params);
        // Rows written before empty pass-through texts still carry a copy of the old source: drop it from the cache.
        cache_helper::invalidate($item->sourcehash, (int)$item->courseid, (int)$item->categoryid);
        return true;
    }

    /**
     * Roll back to a history entry.
     *
     * @param \stdClass $translation
     * @param int $historyid
     * @param int $userid
     */
    public static function rollback(\stdClass $translation, int $historyid, int $userid): void {
        global $DB;
        $history = $DB->get_record(
            'local_contenttranslator_hist',
            ['id' => $historyid, 'translationid' => $translation->id],
            '*',
            MUST_EXIST
        );
        self::add_history($translation, 'rollback', $userid);
        $translation->text = $history->text;
        $translation->format = $history->format;
        $translation->origin = self::ORIGIN_HUMAN;
        $translation->status = self::STATUS_MACHINE;
        $translation->suggestion = null;
        $translation->suggestionhash = null;
        $item = item_manager::get_item((int)$translation->itemid);
        if ($item) {
            $translation->sourcehash = $item->sourcehash;
            $translation->sourcesnapshot = $item->sourcetext;
        }
        self::update($translation, 'rollback', $userid);
    }

    /**
     * Delete a translation (history is kept until cleanup).
     *
     * @param \stdClass $translation
     * @param int $userid
     */
    public static function delete(\stdClass $translation, int $userid): void {
        global $DB;
        $item = item_manager::get_item((int)$translation->itemid);
        $DB->delete_records('local_contenttranslator_tr', ['id' => $translation->id]);
        if ($item) {
            cache_helper::invalidate($item->sourcehash, (int)$item->courseid, (int)$item->categoryid);
            event\translation_deleted::create([
                'objectid' => $translation->id,
                'context' => \context::instance_by_id($item->contextid, IGNORE_MISSING) ?: \context_system::instance(),
                'other' => ['itemid' => $item->id, 'targetlang' => $translation->targetlang],
            ])->trigger();
        }
    }

    /**
     * Source changed: flip every translation of the item to stale (queued/failed ones back to queued).
     *
     * @param int $itemid
     * @param int $userid
     */
    public static function mark_stale_for_item(int $itemid, int $userid): void {
        global $DB;
        $translations = $DB->get_records('local_contenttranslator_tr', ['itemid' => $itemid]);
        foreach ($translations as $translation) {
            if (empty($translation->text) || $translation->status === self::STATUS_QUEUED) {
                $translation->status = self::STATUS_QUEUED;
            } else {
                $translation->status = self::STATUS_STALE;
            }
            $translation->failreason = null;
            self::update($translation, 'stale', $userid);
        }
    }

    /**
     * Bootstrap style badge class for a status.
     *
     * @param \stdClass $translation
     * @return string
     */
    public static function badge_class(\stdClass $translation): string {
        if (!empty($translation->locked)) {
            return 'badge bg-dark text-white';
        }
        return match ($translation->status) {
            self::STATUS_REVIEWED => 'badge bg-success text-white',
            self::STATUS_MACHINE => 'badge bg-info text-white',
            self::STATUS_STALE => 'badge bg-warning text-dark',
            self::STATUS_FAILED => 'badge bg-danger text-white',
            default => 'badge bg-secondary text-white',
        };
    }

    /**
     * Label for a status.
     *
     * @param string $status
     * @return string
     */
    public static function status_label(string $status): string {
        return get_string('status:' . $status, 'local_contenttranslator');
    }
}
