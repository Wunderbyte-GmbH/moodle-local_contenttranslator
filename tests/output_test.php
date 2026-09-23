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

use local_contenttranslator\engine\engine_manager;
use local_contenttranslator\source\registry;

/**
 * Smoke tests for output: templates, settings tree, admin check and external functions.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_contenttranslator\check\setup_check
 * @covers     \local_contenttranslator\external\translate_item
 * @covers     \local_contenttranslator\external\save_translation
 * @covers     \local_contenttranslator\external\set_status
 * @covers     \local_contenttranslator\external\get_translation
 */
final class output_test extends \advanced_testcase {
    /**
     * Setup.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('targetlangs', 'de', 'local_contenttranslator');
        set_config('engine', 'pseudo', 'local_contenttranslator');
        set_config('budgetchars', 1000000, 'local_contenttranslator');
        registry::reset();
        engine_manager::reset();
        cache_helper::purge();
    }

    /**
     * The stats template renders with and without languages.
     */
    public function test_stats_template(): void {
        global $OUTPUT;
        $html = $OUTPUT->render_from_template('local_contenttranslator/stats', [
            'langs' => [[
                'lang' => 'de', 'name' => 'Deutsch', 'total' => 10, 'done' => 6, 'reviewed' => 2, 'machine' => 4, 'stale' => 1,
                'missing' => 2, 'failed' => 1, 'queued' => 0, 'locked' => 0, 'percent' => 60, 'reviewedpercent' => 20,
                'machinepercent' => 40, 'stalepercent' => 10, 'filterurl' => '#',
            ]],
            'budget' => [
                'hasbudget' => true, 'limit' => '2,000,000', 'used' => '120,000',
                'limittokens' => '500,000', 'usedtokens' => '30,000', 'percent' => 6, 'warning' => false,
            ],
        ]);
        $this->assertStringContainsString('Deutsch', $html);
        $this->assertStringContainsString('2,000,000', $html);
        $this->assertStringContainsString('500,000', $html);
        $this->assertStringContainsString(get_string('tokens', 'local_contenttranslator'), $html);
        $html = $OUTPUT->render_from_template('local_contenttranslator/stats', ['langs' => [], 'budget' => ['hasbudget' => false]]);
        $this->assertStringContainsString(get_string('notargetlangs', 'local_contenttranslator'), $html);
    }

    /**
     * The AI credit tile only renders when the context provides it (viewreports capability), and shows the
     * percentage bar, expiry and buy link, or the unlimited note.
     */
    public function test_aicredit_tile(): void {
        global $OUTPUT;
        $withoutcredit = $OUTPUT->render_from_template('local_contenttranslator/stats', [
            'langs' => [], 'budget' => ['hasbudget' => false],
        ]);
        $this->assertStringNotContainsString(get_string('aicredit_heading', 'local_contenttranslator'), $withoutcredit);

        $html = $OUTPUT->render_from_template('local_contenttranslator/stats', [
            'langs' => [],
            'budget' => [
                'hasbudget' => false,
                'aicredit' => [
                    'unlimited' => false, 'percent' => 12, 'expires' => '23. Okt. 2026', 'daysleft' => 30,
                    'shopurl' => 'https://showroom.wunderbyte.at/course/shop',
                ],
            ],
        ]);
        $this->assertStringContainsString(get_string('aicredit_heading', 'local_contenttranslator'), $html);
        $this->assertStringContainsString(get_string('aicredit_used', 'local_contenttranslator', 12), $html);
        $this->assertStringContainsString(get_string('aicredit_expires', 'local_contenttranslator', '23. Okt. 2026'), $html);
        $this->assertStringContainsString(get_string('aicredit_daysleft', 'local_contenttranslator', 30), $html);
        $this->assertStringContainsString('https://showroom.wunderbyte.at/course/shop', $html);

        $unlimited = $OUTPUT->render_from_template('local_contenttranslator/stats', [
            'langs' => [],
            'budget' => ['hasbudget' => false, 'aicredit' => ['unlimited' => true]],
        ]);
        $this->assertStringContainsString(get_string('aicredit_unlimited', 'local_contenttranslator'), $unlimited);
        $this->assertStringNotContainsString(get_string('aicredit_used', 'local_contenttranslator', 0), $unlimited);
    }

    /**
     * The admin settings tree loads our pages without errors.
     */
    public function test_settings_tree(): void {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        $root = admin_get_root(true, true);
        $this->assertNotNull($root->locate('local_contenttranslator'));
        $this->assertNotNull($root->locate('local_contenttranslator_wizard'));
        $this->assertNotNull($root->locate('local_contenttranslator_dashboard'));
        $page = $root->locate('local_contenttranslator');
        $this->assertTrue(isset($page->settings->local_contenttranslatorlang_de_visibility));
    }

    /**
     * The status check reports the missing pieces.
     */
    public function test_setup_check(): void {
        $check = new check\setup_check();
        $result = $check->get_result();
        $this->assertContains(
            $result->get_status(),
            [\core\check\result::WARNING, \core\check\result::ERROR, \core\check\result::OK]
        );
        $this->assertNotEmpty(local_contenttranslator_status_checks());
    }

    /**
     * External functions: translate now, save, review, delete.
     */
    public function test_external_functions(): void {
        global $USER;
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module(
            'page',
            ['course' => $course->id, 'name' => 'External', 'content' => '<p>Via web service</p>']
        );
        $item = item_manager::find('mod_page', 'page', 'content', (int)$page->id);
        $this->assertNotNull($item);

        $result = external\translate_item::execute((int)$item->id, 'de');
        $result = \core_external\external_api::clean_returnvalue(external\translate_item::execute_returns(), $result);
        $this->assertSame(translation_manager::STATUS_MACHINE, $result['status']);
        $this->assertStringContainsString('[de]', $result['text']);

        $saved = external\save_translation::execute($result['translationid'], '<p>Per Webservice</p>', FORMAT_HTML, true);
        $saved = \core_external\external_api::clean_returnvalue(external\save_translation::execute_returns(), $saved);
        $this->assertSame(translation_manager::STATUS_REVIEWED, $saved['status']);

        $lookup = external\get_translation::execute('<p>Via web service</p>', 'de', \context_module::instance($page->cmid)->id);
        $lookup = \core_external\external_api::clean_returnvalue(external\get_translation::execute_returns(), $lookup);
        $this->assertTrue($lookup['found']);
        $this->assertSame('<p>Per Webservice</p>', $lookup['text']);

        $status = external\set_status::execute($result['translationid'], 'lock');
        $this->assertSame(1, (int)translation_manager::get($result['translationid'])->locked);
        $status = external\set_status::execute($result['translationid'], 'delete');
        $status = \core_external\external_api::clean_returnvalue(external\set_status::execute_returns(), $status);
        $this->assertSame('deleted', $status['status']);
        $this->assertNull(translation_manager::get_for_item((int)$item->id, 'de'));

        // Concurrency guard.
        $translation = translation_manager::ensure((int)$item->id, 'de');
        translation_manager::save_human($translation, 'x', FORMAT_HTML, (int)$USER->id, false);
        $this->expectException(\moodle_exception::class);
        external\save_translation::execute((int)$translation->id, 'y', FORMAT_HTML, false, $translation->timemodified - 10);
    }
}
