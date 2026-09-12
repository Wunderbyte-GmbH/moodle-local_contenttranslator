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

/**
 * Protects markup and syntax from LLM translation by replacing it with placeholders.
 *
 * Protected: HTML tags, comments, whole pre/code/script/style/svg/math/iframe/embed elements,
 * elements marked translate="no" or class="notranslate", {mlang} blocks, TeX, [[...]] and {...}
 * filter/placeholder syntax, ##...## snippets, URLs and @@PLUGINFILE@@ paths.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class html_protector {
    /** Placeholder kind: an HTML tag (order must be preserved) */
    public const KIND_TAG = 'tag';
    /** Placeholder kind: inline content (may move) */
    public const KIND_INLINE = 'inline';

    /** @var string[] Regexes for whole blocks that are protected as one placeholder */
    private const BLOCK_PATTERNS = [
        '~<!--.*?-->~s',
        '~<(pre|code|script|style|svg|math|iframe|object|embed|video|audio|textarea|kbd|samp|var)\b[^>]*>.*?</\1\s*>~is',
        '~<([a-z][a-z0-9]*)\b[^>]*(?:translate="no"|class="[^"]*\bnotranslate\b[^"]*")[^>]*>.*?</\1\s*>~is',
        '~\{mlang\s+[^}]*\}.*?\{mlang\}~is',
        '~\$\$.+?\$\$~s',
        '~\\\\\(.+?\\\\\)~s',
        '~\\\\\[.+?\\\\\]~s',
        '~\[\[[^\]]*\]\]~',
        '~##[a-zA-Z0-9_.:-]+##~',
        '~\{\$a(?:->[a-zA-Z0-9_]+)?\}~',
        '~\{[a-zA-Z_][a-zA-Z0-9_.:-]*(?:\s+[^{}]*)?\}~',
        '~@@PLUGINFILE@@[^\s"\'<>]*~',
        '~(?:https?|ftp)://[^\s"\'<>]+~i',
        '~\bwww\.[^\s"\'<>]+~i',
        '~[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}~',
    ];

    /**
     * Replace protected parts with placeholders.
     *
     * @param string $text
     * @param bool $ishtml Whether tags must be protected too.
     * @return array [string $protected, array $map id => ['text' => original, 'kind' => tag|inline]]
     */
    public static function protect(string $text, bool $ishtml = true): array {
        $map = [];
        $counter = 0;
        $replace = function (string $original, string $kind) use (&$map, &$counter): string {
            $counter++;
            $map[$counter] = ['text' => $original, 'kind' => $kind];
            return '<ph id="' . $counter . '"/>';
        };
        foreach (self::BLOCK_PATTERNS as $pattern) {
            $text = preg_replace_callback($pattern, fn($m) => $replace($m[0], self::KIND_INLINE), $text);
        }
        if ($ishtml) {
            $text = preg_replace_callback('~<(?!ph id="\d+"/>)[^<>]+>~', fn($m) => $replace($m[0], self::KIND_TAG), $text);
        }
        return [$text, $map];
    }

    /**
     * Put the protected parts back.
     *
     * @param string $text Engine output containing placeholders.
     * @param array $map
     * @return string
     */
    public static function restore(string $text, array $map): string {
        return preg_replace_callback(
            '~<\s*ph\s+id\s*=\s*["\']?(\d+)["\']?\s*/?\s*>(?:\s*</\s*ph\s*>)?~i',
            fn($m) => $map[(int)$m[1]]['text'] ?? $m[0],
            $text
        );
    }

    /**
     * Validate engine output before restoring.
     *
     * Every placeholder must occur exactly once, no unknown placeholders may exist and placeholders
     * that stand for HTML tags must keep their relative order.
     *
     * @param string $text Engine output containing placeholders.
     * @param array $map
     * @return string|null Error description or null when valid.
     */
    public static function validate(string $text, array $map): ?string {
        preg_match_all('~<\s*ph\s+id\s*=\s*["\']?(\d+)["\']?\s*/?\s*>~i', $text, $matches);
        $found = array_map('intval', $matches[1]);
        $counts = array_count_values($found);
        foreach ($map as $id => $entry) {
            $count = $counts[$id] ?? 0;
            if ($count !== 1) {
                return "placeholder $id occurs $count times";
            }
        }
        foreach ($counts as $id => $count) {
            if (!isset($map[$id])) {
                return "unknown placeholder $id";
            }
        }
        $tagorder = array_values(array_filter($found, fn($id) => ($map[$id]['kind'] ?? '') === self::KIND_TAG));
        $expected = $tagorder;
        sort($expected);
        if ($tagorder !== $expected) {
            return 'tag placeholders reordered';
        }
        return null;
    }

    /**
     * Strip decoration LLMs like to add: code fences, surrounding quotes, "Translation:" prefixes.
     *
     * @param string $text
     * @return string
     */
    public static function clean_llm_output(string $text): string {
        $text = trim($text);
        $fence = str_repeat(chr(96), 3);
        if (preg_match('~^' . $fence . '[a-zA-Z]*\s*\n(.*)\n' . $fence . '$~s', $text, $m)) {
            $text = trim($m[1]);
        }
        $text = preg_replace('~^(?:Translation|Übersetzung|Traduction|Traducción|Traduzione)\s*:\s*~iu', '', $text);
        $quoted = ($text[0] === '"' && substr($text, -1) === '"') || ($text[0] === '„' && substr($text, -1) === '“');
        if (strlen($text) > 2 && $quoted) {
            $inner = substr($text, 1, -1);
            if (strpos($inner, '"') === false) {
                $text = $inner;
            }
        }
        return trim($text);
    }

    /**
     * Whether the text contains anything translatable once protected parts are removed.
     *
     * @param string $protected
     * @return bool
     */
    public static function has_translatable_text(string $protected): bool {
        $stripped = preg_replace('~<ph id="\d+"/>~', '', $protected);
        return (bool)preg_match('/\p{L}/u', $stripped);
    }
}
