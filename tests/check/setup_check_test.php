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

namespace local_contenttranslator\check;

use core\check\result;
use local_contenttranslator\engine\engine_manager;
use local_contenttranslator\fake_core_ai_engine;
use local_contenttranslator\hook\register_engines;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/contenttranslator/tests/fixtures/fake_core_ai_engine.php');

/**
 * Reports > System status: every setup problem the admin must fix is reported (REN-07, AUTO-16, ENG-05).
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_contenttranslator\check\setup_check
 */
final class setup_check_test extends \advanced_testcase {
    /**
     * Common setup.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        engine_manager::reset();
    }

    /**
     * A complete, healthy setup with the pseudo engine.
     */
    private function healthy_setup(): void {
        global $CFG;
        set_config('targetlangs', 'de', 'local_contenttranslator');
        set_config('budgetchars', 2000000, 'local_contenttranslator');
        set_config('engine', 'pseudo', 'local_contenttranslator');
        filter_set_global_state('multilang', TEXTFILTER_ON);
        filter_set_global_state('contenttranslator', TEXTFILTER_ON);
        filter_set_applies_to_strings('contenttranslator', true);
        $this->move_filter_to_top('contenttranslator');
        set_config('filterall', 1);
        $CFG->filterall = 1;
    }

    /**
     * Move a filter to the first position.
     *
     * @param string $filter
     */
    private function move_filter_to_top(string $filter): void {
        for ($i = 0; $i < 50; $i++) {
            $states = filter_get_global_states();
            if (array_key_first($states) === $filter) {
                return;
            }
            filter_set_global_state($filter, TEXTFILTER_ON, -1);
        }
    }

    /**
     * Details of the check result.
     *
     * @return result
     */
    private function run_check(): result {
        return (new setup_check())->get_result();
    }

    /**
     * Healthy setup reports OK.
     */
    public function test_ok(): void {
        $this->healthy_setup();
        $result = $this->run_check();
        $this->assertSame(result::OK, $result->get_status(), strip_tags((string)$result->get_details()));
    }

    /**
     * Missing languages and budget are warnings.
     */
    public function test_languages_and_budget(): void {
        $this->healthy_setup();
        set_config('targetlangs', '', 'local_contenttranslator');
        set_config('budgetchars', 0, 'local_contenttranslator');
        $result = $this->run_check();
        $this->assertSame(result::WARNING, $result->get_status());
        $this->assertStringContainsString(get_string('check:notargetlangs', 'local_contenttranslator'), $result->get_details());
        $this->assertStringContainsString(get_string('check:nobudget', 'local_contenttranslator'), $result->get_details());
    }

    /**
     * Filter disabled is an error; filter not first and filterall off are warnings.
     */
    public function test_filter_state_order_and_filterall(): void {
        global $CFG;
        $this->healthy_setup();

        filter_set_global_state('contenttranslator', TEXTFILTER_DISABLED);
        $result = $this->run_check();
        $this->assertSame(result::ERROR, $result->get_status());
        $this->assertStringContainsString(get_string('check:filteroff', 'local_contenttranslator'), $result->get_details());

        filter_set_global_state('contenttranslator', TEXTFILTER_ON);
        $this->move_filter_to_top('multilang');
        $result = $this->run_check();
        $this->assertSame(result::WARNING, $result->get_status());
        $this->assertStringContainsString(get_string('check:filternotfirst', 'local_contenttranslator'), $result->get_details());

        $this->move_filter_to_top('contenttranslator');
        $CFG->filterall = 0;
        $result = $this->run_check();
        $this->assertSame(result::WARNING, $result->get_status());
        $this->assertStringContainsString(get_string('check:filterall', 'local_contenttranslator'), $result->get_details());
    }

    /**
     * "Filter all strings" alone is not enough: format_string() only runs filters set to apply to
     * "Content and headings" ($CFG->stringfilters). When another filter (multilang) applies to headings but
     * ours only to content, filterall stays on, yet course and activity names stay untranslated, so the check
     * must not report OK.
     */
    public function test_filter_must_apply_to_headings(): void {
        global $CFG;
        $this->healthy_setup();
        filter_set_applies_to_strings('multilang', true);
        filter_set_applies_to_strings('contenttranslator', false);
        $this->assertNotEmpty($CFG->filterall, 'Core keeps filterall on while any filter applies to headings');
        $this->assertNotSame(
            result::OK,
            $this->run_check()->get_status(),
            'Names and headings are not translated, the setup is incomplete'
        );
    }

    /**
     * With the AI engine and automation on, a missing service user or policy is reported.
     */
    public function test_service_user(): void {
        $this->healthy_setup();
        $engine = new fake_core_ai_engine();
        $this->redirectHook(register_engines::class, fn(register_engines $hook) => $hook->add_engine($engine));
        engine_manager::reset();
        set_config('engine', 'core_ai', 'local_contenttranslator');

        $result = $this->run_check();
        $this->assertStringContainsString(get_string('check:noserviceuser', 'local_contenttranslator'), $result->get_details());

        $serviceuser = $this->getDataGenerator()->create_user();
        set_config('serviceuserid', $serviceuser->id, 'local_contenttranslator');
        $result = $this->run_check();
        $this->assertStringContainsString(get_string('check:serviceuserpolicy', 'local_contenttranslator'), $result->get_details());

        \core_ai\manager::user_policy_accepted((int)$serviceuser->id, \context_system::instance()->id);
        $this->assertSame(result::OK, $this->run_check()->get_status());
    }

    /**
     * A site without a working AI provider is told that the free Wunderbyte trial is in the setup wizard.
     * A site whose engine works gets no such hint.
     */
    public function test_trial_hint(): void {
        $this->healthy_setup();
        set_config('engine', 'core_ai', 'local_contenttranslator');
        $result = $this->run_check();
        $this->assertStringContainsString(get_string('check:trialhint', 'local_contenttranslator'), $result->get_details());

        set_config('engine', 'pseudo', 'local_contenttranslator');
        engine_manager::reset();
        $result = $this->run_check();
        $this->assertSame(result::OK, $result->get_status());
        $this->assertStringNotContainsString(
            get_string('check:trialhint', 'local_contenttranslator'),
            (string)$result->get_details()
        );
    }

    /**
     * With the real core_ai engine and no enabled AI provider, the check reports the engine as unavailable
     * instead of failing itself.
     */
    public function test_ai_engine_without_provider(): void {
        $this->healthy_setup();
        set_config('engine', 'core_ai', 'local_contenttranslator');
        // A service user without the policy: not the problem while the provider is off, so it is not reported.
        set_config('serviceuserid', $this->getDataGenerator()->create_user()->id, 'local_contenttranslator');
        $result = $this->run_check();
        $enginename = get_string('engine:core_ai', 'local_contenttranslator');
        $this->assertStringContainsString(
            get_string('check:engineunavailable', 'local_contenttranslator', $enginename),
            $result->get_details()
        );
        $this->assertStringNotContainsString(
            get_string('check:serviceuserpolicy', 'local_contenttranslator'),
            $result->get_details()
        );
    }
}
