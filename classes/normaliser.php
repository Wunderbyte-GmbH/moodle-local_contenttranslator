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
 * Text normalisation and hashing.
 *
 * The hash identifies content both at registration time (raw DB field) and at render time
 * (cleaned, pluginfile-rewritten HTML), so it only depends on the visible text: markup, attributes,
 * file URLs, entities and whitespace differences are removed before hashing.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class normaliser {
    /** Regex matching pluginfile style URLs and @@PLUGINFILE@@ references up to the file name. */
    public const FILEURL_REGEX = '~(?:https?://[^\s"\'<>]*?/(?:pluginfile|draftfile|tokenpluginfile|webservice/pluginfile)\.php/'
        . '|@@PLUGINFILE@@/)(?:[^\s"\'<>/]*/)*~i';

    /**
     * Normalise text for hashing.
     *
     * @param string $text Raw or rendered text.
     * @param int $format Text format of the input.
     * @return string
     */
    public static function normalise(string $text, int $format = FORMAT_HTML): string {
        if ($format == FORMAT_MARKDOWN) {
            $text = markdown_to_html($text);
        }
        // Neutralise file references so that raw @@PLUGINFILE@@ and rewritten URLs hash alike.
        $text = preg_replace(self::FILEURL_REGEX, '@@FILE@@/', $text);
        // Remove invisible content and our own markers.
        $text = preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', ' ', $text);
        $text = preg_replace('~<!--.*?-->~s', ' ', $text);
        // Block level tags become whitespace so words don't stick together after stripping.
        $text = preg_replace('~<br\s*/?>|</?(?:p|div|li|ul|ol|tr|td|th|table|h[1-6]|blockquote|pre|section|article|'
            . 'header|footer|figure|figcaption|dd|dt|dl|hr|nav|aside|address)\b[^>]*>~i', ' ', $text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Non breaking spaces, zero width characters and soft hyphens.
        $text = str_replace(["\xC2\xA0", "\xE2\x80\x8B", "\xE2\x80\x8C", "\xE2\x80\x8D", "\xEF\xBB\xBF", "\xC2\xAD"], ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim((string)$text);
    }

    /**
     * Hash of the normalised text.
     *
     * @param string $text
     * @param int $format
     * @return string sha1
     */
    public static function hash(string $text, int $format = FORMAT_HTML): string {
        return sha1(self::normalise($text, $format));
    }

    /**
     * Number of source characters that count towards the budget (visible text only).
     *
     * @param string $text
     * @param int $format
     * @return int
     */
    public static function count_chars(string $text, int $format = FORMAT_HTML): int {
        return \core_text::strlen(self::normalise($text, $format));
    }

    /**
     * Cheap pre-check: is the text worth looking up at all?
     *
     * @param string $text
     * @return bool
     */
    public static function is_translatable(string $text): bool {
        $trimmed = trim($text);
        if ($trimmed === '' || \core_text::strlen($trimmed) < 2) {
            return false;
        }
        if (is_numeric($trimmed)) {
            return false;
        }
        // Needs at least one letter.
        return (bool)preg_match('/\p{L}/u', $trimmed);
    }

    /**
     * Whether the text contains inline multilang markup that another filter handles.
     *
     * @param string $text
     * @return bool
     */
    public static function has_multilang(string $text): bool {
        return stripos($text, '{mlang') !== false || stripos($text, 'class="multilang"') !== false;
    }

    /**
     * Map of file name => full URL found in rendered HTML, used to restore file URLs in translations.
     *
     * @param string $renderedhtml
     * @return array
     */
    public static function extract_file_urls(string $renderedhtml): array {
        $map = [];
        if (
            preg_match_all('~(https?://[^\s"\'<>]*?/(?:pluginfile|draftfile|tokenpluginfile|webservice/pluginfile)\.php/'
            . '[^\s"\'<>]+)~i', $renderedhtml, $matches)
        ) {
            foreach ($matches[1] as $url) {
                $name = rawurldecode(basename(parse_url($url, PHP_URL_PATH) ?: $url));
                $map[$name] = $url;
            }
        }
        return $map;
    }

    /**
     * Replace @@PLUGINFILE@@ references in a translation with the URLs used by the rendered source.
     *
     * @param string $translation
     * @param string $renderedsource
     * @return string
     */
    public static function rewrite_file_urls(string $translation, string $renderedsource): string {
        if (strpos($translation, '@@PLUGINFILE@@') === false) {
            return $translation;
        }
        $map = self::extract_file_urls($renderedsource);
        return preg_replace_callback('~@@PLUGINFILE@@/([^\s"\'<>]+)~', function (array $m) use ($map): string {
            $name = rawurldecode(basename($m[1]));
            return $map[$name] ?? $m[0];
        }, $translation);
    }
}
