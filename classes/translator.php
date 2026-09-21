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

use local_contenttranslator\engine\budget_exceeded_exception;
use local_contenttranslator\engine\engine_manager;
use local_contenttranslator\engine\html_protector;
use local_contenttranslator\engine\rate_limited_exception;
use local_contenttranslator\engine\segment;

/**
 * The translation pipeline for one item and one language: TM -> budget -> engines -> validate -> store.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class translator {
    /**
     * Translate an item into a language.
     *
     * @param \stdClass $item Item record.
     * @param string $lang Target language.
     * @param string $trigger One of the budget::TRIGGER_* constants.
     * @param int $userid User the engine call runs as (service user for automatic jobs).
     * @return \stdClass The translation record.
     * @throws budget_exceeded_exception When the monthly budget does not allow the call.
     * @throws rate_limited_exception When the engine asked us to back off.
     */
    public static function translate_item(\stdClass $item, string $lang, string $trigger, int $userid): \stdClass {
        $translation = translation_manager::ensure((int)$item->id, $lang);
        if ($item->excluded || $item->sourcelang === $lang) {
            return $translation;
        }
        if (!translation_manager::is_overwritable($translation) && $translation->suggestionhash === $item->sourcehash) {
            // A suggestion for the current source already exists.
            return $translation;
        }
        $tenantkey = tenant::key((int)$item->courseid, (int)$item->categoryid);

        // 1. Translation memory: exact match is free, but only when the formatting matches too.
        $source = $item->sourceformat == FORMAT_MARKDOWN ? markdown_to_html((string)$item->sourcetext) : (string)$item->sourcetext;
        $hit = tm::find($tenantkey, $item->sourcelang, $lang, $item->sourcehash);
        // A hit that equals the item's own translation means someone asked to translate again: ask the engine.
        if (
            $hit && (string)$hit->targettext !== '' && tm::same_markup((string)$hit->sourcetext, $source)
            && (string)$hit->targettext !== (string)$translation->text
        ) {
            tm::increment((int)$hit->id);
            return translation_manager::store_machine(
                $translation,
                $item,
                (string)$hit->targettext,
                (int)$hit->format,
                translation_manager::ORIGIN_TM,
                'tm',
                null,
                0,
                $userid
            );
        }

        // 2. Budget.
        if (!budget::can_spend((int)$item->chars, $trigger, $userid)) {
            throw new budget_exceeded_exception();
        }

        // 3. Engines.
        $externalallowed = $item->courseid > 0 ? config::get_effective((int)$item->courseid)->externalallowed : true;
        $engines = engine_manager::get_engines_for_lang($item->sourcelang, $lang, $externalallowed);
        if (!$engines) {
            translation_manager::mark_failed($translation, get_string('error:noengine', 'local_contenttranslator'), $userid);
            return $translation;
        }

        $text = (string)$item->sourcetext;
        $format = (int)$item->sourceformat;
        if ($format == FORMAT_MARKDOWN) {
            $text = markdown_to_html($text);
            $format = FORMAT_HTML;
        }
        $ishtml = !$item->isstring && $format != FORMAT_PLAIN;
        $segment = new segment((string)$item->id, $text, $ishtml, self::describe($item));
        $lasterror = '';

        foreach ($engines as $engine) {
            if (!$engine->is_available_for_user($userid)) {
                $lasterror = get_string('error:engineunavailable', 'local_contenttranslator', $engine->get_display_name());
                continue;
            }
            $protect = !$engine->supports_html();
            $map = [];
            $input = $segment;
            if ($protect) {
                [$protected, $map] = html_protector::protect($text, $ishtml);
                if (!html_protector::has_translatable_text($protected)) {
                    // Nothing but markup, links or code: there is nothing to translate. Store no copy of the source,
                    // so that learners always see the current source and no "machine translated" label appears.
                    return translation_manager::store_machine(
                        $translation,
                        $item,
                        '',
                        $format,
                        translation_manager::ORIGIN_MACHINE,
                        'none',
                        null,
                        0,
                        $userid
                    );
                }
                $input = new segment($segment->id, $protected, $ishtml, $segment->context);
            }
            $options = [
                'contextid' => (int)$item->contextid,
                'userid' => $userid,
                'strict' => false,
                'previous' => self::previous_version($translation, $item),
            ];
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $results = $engine->translate_batch([$input], $item->sourcelang, $lang, $options);
                $result = $results[$segment->id] ?? null;
                if (!$result) {
                    $lasterror = 'engine returned no result';
                    break;
                }
                budget::log_usage([
                    'engine' => $engine->get_name(),
                    'model' => $result->model,
                    'sourcelang' => $item->sourcelang,
                    'targetlang' => $lang,
                    'courseid' => (int)$item->courseid,
                    'contextid' => (int)$item->contextid,
                    'itemid' => (int)$item->id,
                    'chars' => (int)$item->chars,
                    'prompttokens' => $result->prompttokens,
                    'completiontokens' => $result->completiontokens,
                    'triggertype' => $trigger,
                    'userid' => $userid,
                    'success' => (int)$result->success,
                    'error' => $result->success ? null : $result->error,
                ]);
                if ($result->ratelimited) {
                    throw new rate_limited_exception($engine->get_name(), $result->error);
                }
                if (!$result->success) {
                    $lasterror = $result->error;
                    if ($result->retryable && $attempt === 0) {
                        // Probably temporary (gateway timeout, a model that got stuck): one more call, same prompt.
                        continue;
                    }
                    break;
                }
                $output = $result->text;
                if ($protect) {
                    $problem = html_protector::validate($output, $map);
                    if ($problem !== null) {
                        $lasterror = get_string('error:markup', 'local_contenttranslator', $problem);
                        $options['strict'] = true;
                        continue;
                    }
                    $output = html_protector::restore($output, $map);
                }
                if ($ishtml) {
                    $output = clean_text($output, FORMAT_HTML);
                } else {
                    $output = strip_tags($output);
                }
                $translation = translation_manager::store_machine(
                    $translation,
                    $item,
                    $output,
                    $format,
                    translation_manager::ORIGIN_MACHINE,
                    $engine->get_name(),
                    $result->model,
                    (int)$item->chars,
                    $userid
                );
                tm::store($tenantkey, $item->sourcelang, $lang, $item->sourcehash, $text, $output, $format, tm::QUALITY_MACHINE);
                return $translation;
            }
        }
        translation_manager::mark_failed($translation, $lasterror ?: 'unknown error', $userid);
        return $translation;
    }

    /**
     * The earlier source and its translation by a person, as plain text, so the engine can keep that wording.
     *
     * @param \stdClass $translation
     * @param \stdClass $item
     * @return array|null ['source' => ..., 'translation' => ...], null when no person worked on the translation
     */
    private static function previous_version(\stdClass $translation, \stdClass $item): ?array {
        $human = $translation->origin === translation_manager::ORIGIN_HUMAN
            || $translation->status === translation_manager::STATUS_REVIEWED
            || !empty($translation->reviewerid);
        if (!$human || (string)$translation->text === '' || (string)$translation->sourcesnapshot === '') {
            return null;
        }
        return [
            'source' => normaliser::normalise((string)$translation->sourcesnapshot, (int)$item->sourceformat),
            'translation' => normaliser::normalise((string)$translation->text, (int)$translation->format),
        ];
    }

    /**
     * Short description of an item for the engine prompt.
     *
     * @param \stdClass $item
     * @return string
     */
    private static function describe(\stdClass $item): string {
        $parts = [];
        if ($item->courseid > 0) {
            $course = get_course((int)$item->courseid);
            $parts[] = 'course "' . $course->fullname . '"';
        }
        if (!empty($item->label)) {
            $parts[] = $item->label;
        }
        $parts[] = 'field "' . $item->field . '"';
        return implode(', ', $parts);
    }
}
