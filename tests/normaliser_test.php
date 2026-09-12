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
 * Tests for the normaliser.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_contenttranslator\normaliser
 */
final class normaliser_test extends \basic_testcase {
    /**
     * Raw DB content and rendered HTML must hash alike.
     */
    public function test_hash_is_stable_across_rendering(): void {
        $raw = '<p>Hello <b>world</b><br>see <img src="@@PLUGINFILE@@/pic%20one.png" alt="x"></p>';
        $rendered = '<p>Hello <b>world</b><br />see <img src="https://example.com/moodle/pluginfile.php/12/mod_page/'
            . 'content/3/pic%20one.png" alt="x" class="img-fluid" /></p>';
        $this->assertSame(normaliser::hash($raw), normaliser::hash($rendered));
        $this->assertSame('Hello world see', normaliser::normalise($rendered));
    }

    /**
     * Entities, nbsp and whitespace do not matter.
     */
    public function test_entities_and_whitespace(): void {
        $this->assertSame(normaliser::hash("Fish &amp; Chips\n\n  today"), normaliser::hash('Fish & Chips&nbsp;today'));
        $this->assertSame(normaliser::hash('<h1>Title</h1><p>Body</p>'), normaliser::hash("Title\nBody"));
    }

    /**
     * Markdown is converted before hashing.
     */
    public function test_markdown(): void {
        $this->assertSame(
            normaliser::hash('**Bold** text', FORMAT_MARKDOWN),
            normaliser::hash('<p><strong>Bold</strong> text</p>')
        );
    }

    /**
     * Pre-checks.
     */
    public function test_is_translatable(): void {
        $this->assertFalse(normaliser::is_translatable(''));
        $this->assertFalse(normaliser::is_translatable('42'));
        $this->assertFalse(normaliser::is_translatable(' - '));
        $this->assertFalse(normaliser::is_translatable('a'));
        $this->assertTrue(normaliser::is_translatable('Hi'));
        $this->assertTrue(normaliser::has_multilang('{mlang de}Hallo{mlang}{mlang en}Hello{mlang}'));
    }

    /**
     * File URLs are restored from the rendered source.
     */
    public function test_rewrite_file_urls(): void {
        $rendered = '<img src="https://x.org/pluginfile.php/1/mod_page/content/2/a%20b.png">';
        $translation = '<p>Bild</p><img src="@@PLUGINFILE@@/a%20b.png">';
        $this->assertSame(
            '<p>Bild</p><img src="https://x.org/pluginfile.php/1/mod_page/content/2/a%20b.png">',
            normaliser::rewrite_file_urls($translation, $rendered)
        );
    }

    /**
     * Character counting uses visible text only.
     */
    public function test_count_chars(): void {
        $this->assertSame(11, normaliser::count_chars('<p>Hello <b>world</b></p>'));
    }
}
