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
 * Word level diff rendered as HTML with <del> and <ins>.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class diff {
    /** Maximum tokens per side before we give up (O(n*m) memory) */
    private const MAX_TOKENS = 2500;

    /**
     * Diff two texts (markup is stripped first).
     *
     * @param string $old
     * @param string $new
     * @param int $format
     * @return string|null HTML or null when the texts are too long.
     */
    public static function render(string $old, string $new, int $format = FORMAT_HTML): ?string {
        $a = self::tokenise(normaliser::normalise($old, $format));
        $b = self::tokenise(normaliser::normalise($new, $format));
        if (count($a) > self::MAX_TOKENS || count($b) > self::MAX_TOKENS) {
            return null;
        }
        $ops = self::diff_tokens($a, $b);
        $html = '';
        foreach ($ops as [$op, $token]) {
            $escaped = s($token);
            $html .= match ($op) {
                '-' => '<del class="bg-danger-subtle text-decoration-line-through">' . $escaped . '</del> ',
                '+' => '<ins class="bg-success-subtle text-decoration-none">' . $escaped . '</ins> ',
                default => $escaped . ' ',
            };
        }
        return trim($html);
    }

    /**
     * Split on whitespace.
     *
     * @param string $text
     * @return string[]
     */
    private static function tokenise(string $text): array {
        return $text === '' ? [] : preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
    }

    /**
     * LCS based token diff.
     *
     * @param string[] $a
     * @param string[] $b
     * @return array [op, token] with op in '=', '-', '+'
     */
    private static function diff_tokens(array $a, array $b): array {
        $n = count($a);
        $m = count($b);
        // Trim common prefix and suffix to keep the table small.
        $start = 0;
        while ($start < $n && $start < $m && $a[$start] === $b[$start]) {
            $start++;
        }
        $enda = $n;
        $endb = $m;
        while ($enda > $start && $endb > $start && $a[$enda - 1] === $b[$endb - 1]) {
            $enda--;
            $endb--;
        }
        $ops = [];
        for ($i = 0; $i < $start; $i++) {
            $ops[] = ['=', $a[$i]];
        }
        $sa = array_slice($a, $start, $enda - $start);
        $sb = array_slice($b, $start, $endb - $start);
        $la = count($sa);
        $lb = count($sb);
        $table = array_fill(0, $la + 1, array_fill(0, $lb + 1, 0));
        for ($i = $la - 1; $i >= 0; $i--) {
            for ($j = $lb - 1; $j >= 0; $j--) {
                $table[$i][$j] = $sa[$i] === $sb[$j]
                    ? $table[$i + 1][$j + 1] + 1
                    : max($table[$i + 1][$j], $table[$i][$j + 1]);
            }
        }
        $i = 0;
        $j = 0;
        while ($i < $la && $j < $lb) {
            if ($sa[$i] === $sb[$j]) {
                $ops[] = ['=', $sa[$i]];
                $i++;
                $j++;
            } else if ($table[$i + 1][$j] >= $table[$i][$j + 1]) {
                $ops[] = ['-', $sa[$i]];
                $i++;
            } else {
                $ops[] = ['+', $sb[$j]];
                $j++;
            }
        }
        while ($i < $la) {
            $ops[] = ['-', $sa[$i++]];
        }
        while ($j < $lb) {
            $ops[] = ['+', $sb[$j++]];
        }
        for ($k = $enda; $k < $n; $k++) {
            $ops[] = ['=', $a[$k]];
        }
        return $ops;
    }
}
