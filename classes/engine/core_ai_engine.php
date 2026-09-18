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

namespace local_contenttranslator\engine;

use core_ai\aiactions\generate_text;
use core_ai\manager;
use local_contenttranslator\config;

/**
 * Engine using Moodle's core AI subsystem (generate_text with a translation prompt).
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class core_ai_engine implements engine {
    #[\Override]
    public function get_name(): string {
        return 'core_ai';
    }

    #[\Override]
    public function get_display_name(): string {
        return get_string('engine:core_ai', 'local_contenttranslator');
    }

    #[\Override]
    public function is_available(): bool {
        if (!class_exists(manager::class) || !class_exists(generate_text::class)) {
            return false;
        }
        // Moodle 5.0+: instance method on the DI-managed manager; on 4.5 the method is static, which PHP also
        // allows to be called through an instance.
        return \core\di::get(manager::class)->is_action_available(generate_text::class);
    }

    #[\Override]
    public function is_available_for_user(int $userid): bool {
        return $userid > 0 && $this->is_available() && manager::get_user_policy_status($userid);
    }

    #[\Override]
    public function is_external(): bool {
        return true;
    }

    #[\Override]
    public function supports(string $sourcelang, string $targetlang): bool {
        return true;
    }

    #[\Override]
    public function supports_html(): bool {
        return false;
    }

    #[\Override]
    public function max_batch_size(): int {
        return 1;
    }

    /**
     * Default prompt template.
     *
     * @return string
     */
    public static function default_prompt(): string {
        return "You are a professional translator for e-learning content.\n"
            . "Translate the following text from {sourcelang} into {targetlang}.\n\n"
            . "Rules:\n"
            . "- Return ONLY the translated text. No explanations, no quotes, no code fences, no preamble.\n"
            . "- Placeholders of the form <ph id=\"N\"/> stand for markup and must be kept EXACTLY as they are. "
            . "Keep every placeholder exactly once, keep their order, and place them so the sentence stays grammatical.\n"
            . "- Preserve line breaks and paragraph structure.\n"
            . "- Translate titles and headings too, even when they are short or capitalised. Keep unchanged only "
            . "names of people, brands and products, course codes, file names and anything that looks like code.\n"
            . "- If the text is already in {targetlang}, return it unchanged.\n"
            . "{formality}{styleguide}{glossary}{previous}{context}\n"
            . "Text:\n{text}";
    }

    /**
     * Build the prompt for a segment.
     *
     * @param segment $segment
     * @param string $sourcelang
     * @param string $targetlang
     * @param array $options
     * @param bool $strict
     * @return string
     */
    protected function build_prompt(
        segment $segment,
        string $sourcelang,
        string $targetlang,
        array $options,
        bool $strict
    ): string {
        $template = (string)config::get('prompttemplate', '');
        if (trim($template) === '') {
            $template = self::default_prompt();
        }
        $formality = '';
        switch ($options['formality'] ?? config::get_lang_setting($targetlang, 'formality', 'default')) {
            case 'more':
                $formality = "- Use a formal register (e.g. \"Sie\" in German, \"vous\" in French).\n";
                break;
            case 'less':
                $formality = "- Use an informal register (e.g. \"du\" in German, \"tu\" in French).\n";
                break;
        }
        $styleguide = trim((string)($options['styleguide'] ?? config::get_lang_setting($targetlang, 'styleguide', '')));
        if ($styleguide !== '') {
            $styleguide = "- Style guide: " . str_replace("\n", ' ', $styleguide) . "\n";
        }
        $glossary = '';
        if (!empty($options['glossary'])) {
            $lines = [];
            foreach ($options['glossary'] as $term => $translation) {
                $lines[] = $translation === '' ? "  \"$term\" (do not translate)" : "  \"$term\" -> \"$translation\"";
            }
            $glossary = "- Use these terms:\n" . implode("\n", $lines) . "\n";
        }
        $previous = '';
        if (!empty($options['previous'])) {
            $previous = "- An earlier version of this text was translated and corrected by a person. Keep their wording, "
                . "terms and style wherever the text has not changed.\n"
                . "  Earlier text: " . $options['previous']['source'] . "\n"
                . "  Their translation: " . $options['previous']['translation'] . "\n";
        }
        $context = '';
        if ($segment->context !== '') {
            $context = "\nContext of the text: " . $segment->context . "\n";
        }
        if ($strict) {
            $context .= "\nIMPORTANT: Your previous answer altered the placeholders. Output every <ph id=\"N\"/> placeholder "
                . "exactly once, unchanged and in the original order.\n";
        }
        return strtr($template, [
            '{sourcelang}' => config::lang_name_english($sourcelang),
            '{targetlang}' => config::lang_name_english($targetlang),
            '{formality}' => $formality,
            '{styleguide}' => $styleguide,
            '{glossary}' => $glossary,
            '{previous}' => $previous,
            '{context}' => $context,
            '{text}' => $segment->text,
        ]);
    }

    #[\Override]
    public function translate_batch(array $segments, string $sourcelang, string $targetlang, array $options = []): array {
        $results = [];
        $userid = (int)($options['userid'] ?? 0);
        $contextid = (int)($options['contextid'] ?? \context_system::instance()->id);
        foreach ($segments as $segment) {
            $results[$segment->id] = $this->translate_one($segment, $sourcelang, $targetlang, $options, $userid, $contextid);
        }
        return $results;
    }

    /**
     * Translate one segment through core_ai.
     *
     * @param segment $segment
     * @param string $sourcelang
     * @param string $targetlang
     * @param array $options
     * @param int $userid
     * @param int $contextid
     * @return result
     */
    protected function translate_one(
        segment $segment,
        string $sourcelang,
        string $targetlang,
        array $options,
        int $userid,
        int $contextid
    ): result {
        $prompt = $this->build_prompt($segment, $sourcelang, $targetlang, $options, !empty($options['strict']));
        $action = new generate_text(contextid: $contextid, userid: $userid, prompttext: $prompt);
        try {
            $response = $this->process($action);
        } catch (\Throwable $e) {
            return new result($segment->id, false, '', $e->getMessage(), $this->is_rate_limit_message($e->getMessage()));
        }
        if (!$response->get_success()) {
            $code = (int)$response->get_errorcode();
            $message = (string)$response->get_errormessage();
            $ratelimited = in_array($code, [429, 503, 529], true) || $this->is_rate_limit_message($message);
            return new result($segment->id, false, '', trim($code . ' ' . $message), $ratelimited);
        }
        $data = $response->get_response_data();
        $text = html_protector::clean_llm_output((string)($data['generatedcontent'] ?? ''));
        if ($text === '') {
            return new result($segment->id, false, '', 'empty response');
        }
        return new result(
            $segment->id,
            true,
            $text,
            '',
            false,
            $data['model'] ?? ($data['fingerprint'] ?? null),
            (int)($data['prompttokens'] ?? 0),
            (int)($data['completiontokens'] ?? 0),
        );
    }

    /**
     * Send the action to the AI manager (overridable in tests).
     *
     * @param generate_text $action
     * @return \core_ai\aiactions\responses\response_base
     */
    protected function process(generate_text $action): \core_ai\aiactions\responses\response_base {
        return \core\di::get(manager::class)->process_action($action);
    }

    /**
     * Heuristic rate limit detection on error messages.
     *
     * @param string $message
     * @return bool
     */
    protected function is_rate_limit_message(string $message): bool {
        return (bool)preg_match('~rate ?limit|too many requests|429|quota|overloaded~i', $message);
    }
}
