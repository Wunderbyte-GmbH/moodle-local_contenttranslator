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

use local_contenttranslator\engine\html_protector;

/**
 * Tests for the HTML protector.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_contenttranslator\engine\html_protector
 */
final class html_protector_test extends \basic_testcase {
    /**
     * Round trip.
     */
    public function test_protect_restore_roundtrip(): void {
        $html = '<p class="lead">Hello <a href="https://moodle.org">Moodle</a>, see {$a->name} and <code>x = 1</code> '
            . '$$E=mc^2$$ @@PLUGINFILE@@/a.png [[shortcode]] {mlang de}x{mlang}</p>';
        [$protected, $map] = html_protector::protect($html);
        $this->assertStringNotContainsString('<p class', $protected);
        $this->assertStringNotContainsString('moodle.org', $protected);
        $this->assertStringNotContainsString('{$a', $protected);
        $this->assertStringNotContainsString('E=mc', $protected);
        $this->assertStringNotContainsString('PLUGINFILE', $protected);
        $this->assertStringContainsString('Hello', $protected);
        $this->assertNull(html_protector::validate($protected, $map));
        $this->assertSame($html, html_protector::restore($protected, $map));
    }

    /**
     * Placeholders may be written slightly differently by an LLM.
     */
    public function test_restore_is_tolerant(): void {
        [, $map] = html_protector::protect('<b>x</b>');
        $this->assertSame('<b>y</b>', html_protector::restore('<ph id=1>y< ph id="2" />', $map));
        $this->assertSame('<b>y</b>', html_protector::restore('<ph id="1"></ph>y<ph id="2"/>', $map));
    }

    /**
     * Validation catches missing, duplicated, unknown and reordered tag placeholders.
     */
    public function test_validate(): void {
        [$protected, $map] = html_protector::protect('<b>one</b> <i>two</i>');
        $this->assertNull(html_protector::validate($protected, $map));
        $this->assertNotNull(html_protector::validate('<ph id="1"/>one<ph id="2"/> two', $map));
        $this->assertNotNull(html_protector::validate($protected . '<ph id="1"/>', $map));
        $this->assertNotNull(html_protector::validate($protected . '<ph id="9"/>', $map));
        // Swapped tags.
        $this->assertNotNull(html_protector::validate('<ph id="3"/>one<ph id="4"/> <ph id="1"/>two<ph id="2"/>', $map));
        // Inline placeholders (URLs, variables) may move.
        [$protected2, $map2] = html_protector::protect('See {$a} at https://x.org now', false);
        $this->assertNull(html_protector::validate('Siehe <ph id="2"/> jetzt <ph id="1"/>', $map2));
    }

    /**
     * Plain text mode leaves angle brackets alone but still protects syntax.
     */
    public function test_plain_text(): void {
        [$protected, $map] = html_protector::protect('Booking {title} for {$a->user}', false);
        $this->assertSame('Booking <ph id="2"/> for <ph id="1"/>', $protected);
        $this->assertTrue(html_protector::has_translatable_text($protected));
        $this->assertFalse(html_protector::has_translatable_text('<ph id="1"/> <ph id="2"/>'));
    }

    /**
     * LLM decoration is removed.
     */
    public function test_clean_llm_output(): void {
        $fence = str_repeat(chr(96), 3);
        $this->assertSame('Hallo', html_protector::clean_llm_output($fence . "html\nHallo\n" . $fence));
        $this->assertSame('Hallo', html_protector::clean_llm_output('"Hallo"'));
        $this->assertSame('Hallo Welt', html_protector::clean_llm_output('Translation: Hallo Welt'));
        $this->assertSame('Er sagte "Hi" zu mir', html_protector::clean_llm_output('Er sagte "Hi" zu mir'));
    }
}
