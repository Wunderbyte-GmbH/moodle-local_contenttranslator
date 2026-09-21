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

use core\di;
use core\http_client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use local_contenttranslator\event\trial_consent_given;
use local_contenttranslator\external\request_trial_key;
use local_contenttranslator\form\wizard_form;
use local_contenttranslator\trial\provider_compat;
use local_contenttranslator\trial\trial_provisioner;

/**
 * Wunderbyte free trial: key request, reuse of an existing provider, consent gate, capability (GH-2387).
 *
 * The trial service is never contacted; its answers are faked with a Guzzle mock handler. Provider setup runs
 * through the real core AI manager, so the tests work on the single-instance model of Moodle 4.5 and on the
 * multi-instance model of 5.x alike.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_contenttranslator\trial\trial_provisioner
 * @covers     \local_contenttranslator\trial\provider_compat
 * @covers     \local_contenttranslator\external\request_trial_key
 */
final class trial_test extends \advanced_testcase {
    /** @var string Key the fake trial service hands out */
    private const APIKEY = 'sk-test-0123456789abcdefghij';

    /** @var MockHandler Queue of the answers of the fake trial service */
    private MockHandler $mock;

    /** @var array Requests that were sent to the fake trial service */
    private array $history = [];

    /**
     * Common setup: an administrator and a Guzzle client that answers from a queue.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->history = [];
        $this->mock = new MockHandler([]);
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));
        di::set(http_client::class, new http_client(['handler' => $stack]));
    }

    /**
     * Queue an answer of the trial service.
     *
     * @param int $status
     * @param array $body
     */
    private function respond(int $status, array $body = []): void {
        $this->mock->append(new Response($status, ['Content-Type' => 'application/json'], json_encode($body)));
    }

    /**
     * Queue a successful answer with a key. The endpoint is the internal one of the service, as in reality.
     */
    private function respond_with_key(): void {
        $this->respond(200, ['apikey' => self::APIKEY, 'endpoint' => 'http://litellm:4000', 'model' => 'wunderbyte-privat']);
    }

    /**
     * Provider views that talk to the Wunderbyte LLM.
     *
     * @return object[]
     */
    private function wunderbyte_views(): array {
        return array_values(array_filter(
            provider_compat::get_provider_views(),
            fn($view) => trial_provisioner::targets_wunderbyte_llm($view)
        ));
    }

    /**
     * A provider that another Wunderbyte plugin (agent, local_wizard) has set up before.
     *
     * @param bool $enabled
     */
    private function create_wunderbyte_provider(bool $enabled = true): void {
        $actionconfig = ['core_ai\\aiactions\\generate_text' => [
            'enabled' => true,
            'settings' => [
                'endpoint' => 'https://llm.wunderbyte.at/v1/chat/completions',
                'model' => 'wunderbyte-privat',
                'systeminstruction' => 'Translate.',
            ],
        ]];
        provider_compat::configure_provider(
            'aiprovider_openai\\provider',
            ['apikey' => 'sk-existing-0123456789abcdefghij'],
            $actionconfig,
            'Wunderbyte'
        );
        if ($enabled) {
            return;
        }
        if (provider_compat::supports_provider_instances()) {
            $manager = di::get(\core_ai\manager::class);
            foreach ($manager->get_provider_instances() as $instance) {
                $manager->disable_provider_instance($instance);
            }
        } else {
            \core\plugininfo\aiprovider::enable_plugin('openai', 0);
        }
    }

    /**
     * Make the user a manager and log in as that user; the sesskey of the web service call matches.
     *
     * @return \stdClass
     */
    private function login_as_manager(): \stdClass {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $role = $DB->get_record('role', ['shortname' => 'manager'], '*', MUST_EXIST);
        role_assign($role->id, $user->id, \context_system::instance()->id);
        $this->setUser($user);
        $_POST['sesskey'] = sesskey();
        return $user;
    }

    /**
     * The key is requested with exactly {wwwroot, nonce}, the nonce is already cached when the service
     * calls back, and the provider gets the public endpoint, not the internal one the service reports.
     */
    public function test_key_request_and_provider(): void {
        global $CFG;
        $seen = [];
        $this->mock->append(function (Request $request) use (&$seen) {
            $seen = json_decode((string)$request->getBody(), true);
            $cache = \cache::make('local_contenttranslator', 'trialnonce');
            $seen['cached'] = $cache->get('nonce_' . ($seen['nonce'] ?? ''));
            return new Response(200, [], json_encode(['apikey' => self::APIKEY, 'endpoint' => 'http://litellm:4000']));
        });

        $result = (new trial_provisioner())->provision('openai');

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame('created', $result['code']);
        $this->assertCount(1, $this->history);
        $this->assertSame('https://llm.wunderbyte.at/api/moodle-trial', (string)$this->history[0]['request']->getUri());
        $cached = $seen['cached'];
        unset($seen['cached']);
        $this->assertEqualsCanonicalizing(['wwwroot', 'nonce'], array_keys($seen), 'The payload of released plugins stays');
        $this->assertSame($CFG->wwwroot, $seen['wwwroot']);
        $this->assertSame($seen['nonce'], $cached, 'Without the cached nonce the origin check of the service fails');

        $views = $this->wunderbyte_views();
        $this->assertCount(1, $views);
        $this->assertSame(self::APIKEY, $views[0]->config['apikey']);
        $this->assertNotEmpty($views[0]->enabled);
        $settings = $views[0]->actionconfig['core_ai\\aiactions\\generate_text']['settings'];
        $this->assertSame('https://llm.wunderbyte.at/v1/chat/completions', $settings['endpoint'], 'Never the internal URL');
        $this->assertSame('wunderbyte-privat', $settings['model']);
        // The AI subsystem sends this text as system message as it is: a placeholder would reach the model.
        $this->assertSame(get_string('action_generate_text_instruction', 'core_ai'), $settings['systeminstruction']);
    }

    /**
     * Answers of the trial service and their user-facing outcome. Without a key no provider is created.
     *
     * @return array
     */
    public static function response_provider(): array {
        return [
            'origin not verified, service without code' => [403, ['detail' => 'Could not verify'], 'unreachable'],
            'origin not verified' => [403, ['detail' => 'x', 'code' => 'origin_unverified'], 'unreachable'],
            'trial used, service without code' => [409, ['detail' => 'x'], 'alreadyused'],
            'trial used' => [409, ['detail' => 'x', 'code' => 'already_issued'], 'alreadyused'],
            'address limit' => [429, ['detail' => 'x', 'code' => 'ip_limit'], 'iplimit'],
            'global limit' => [429, ['detail' => 'x', 'code' => 'global_limit'], 'globallimit'],
            'limit, service without code' => [429, ['detail' => 'Try again tomorrow.'], 'ratelimited'],
            'service unavailable' => [503, ['detail' => 'x', 'code' => 'unavailable'], 'unavailable'],
            'service unavailable, without code' => [503, ['detail' => 'x'], 'unavailable'],
            'upstream failure' => [502, ['detail' => 'LiteLLM key creation failed'], 'failed'],
            'answer without a key' => [200, ['endpoint' => 'http://litellm:4000'], 'failed'],
        ];
    }

    /**
     * Every answer of the service ends in its own outcome, never in a generic error where the cause is known.
     *
     * @dataProvider response_provider
     * @param int $status
     * @param array $body
     * @param string $expectedcode
     */
    public function test_response_mapping(int $status, array $body, string $expectedcode): void {
        $this->respond($status, $body);

        $result = (new trial_provisioner())->provision('openai');

        $this->assertFalse($result['success']);
        $this->assertSame($expectedcode, $result['code']);
        $this->assertNotSame('', $result['message']);
        $this->assertSame([], $this->wunderbyte_views(), 'No key, no provider');
    }

    /**
     * A used-up trial points to the buy page; the site cannot get a second key.
     */
    public function test_trial_already_used_shows_the_buy_link(): void {
        $this->respond(409, ['detail' => 'A trial key has already been issued for this site.']);
        $result = (new trial_provisioner())->provision('openai');
        $this->assertSame('alreadyused', $result['code']);
        $this->assertStringContainsString(get_string('trial_buy_url', 'local_contenttranslator'), $result['message']);
    }

    /**
     * An old service without a code: the limit text of the service is shown as it is.
     */
    public function test_limit_without_code_shows_the_text_of_the_service(): void {
        $this->respond(429, ['detail' => 'Try again tomorrow.']);
        $result = (new trial_provisioner())->provision('openai');
        $this->assertStringContainsString('Try again tomorrow.', $result['message']);
    }

    /**
     * Two different messages: this server cannot reach the service, or the service cannot reach this site.
     */
    public function test_unreachable_in_both_directions_is_told_apart(): void {
        $this->mock->append(new ConnectException(
            'cURL error 6: Could not resolve host',
            new Request('POST', 'https://llm.wunderbyte.at')
        ));
        $outgoing = (new trial_provisioner())->provision('openai');
        $this->assertSame('noconnection', $outgoing['code']);
        $this->assertStringContainsString(get_string('trial_error_noconnection', 'local_contenttranslator'), $outgoing['message']);

        $this->respond(403, ['detail' => 'Could not verify that this request originates from the site.']);
        $incoming = (new trial_provisioner())->provision('openai');
        $this->assertSame('unreachable', $incoming['code']);
        $this->assertStringContainsString('trial_challenge.php', $incoming['message']);
        $this->assertNotSame($outgoing['message'], $incoming['message']);
    }

    /**
     * Without an installable provider plugin the admin is pointed to the Wunderbyte provider; nothing is requested.
     */
    public function test_no_provider_plugin(): void {
        $provisioner = new class extends trial_provisioner {
            #[\Override]
            protected function detect_strategy(): ?string {
                return null;
            }
        };

        $result = $provisioner->provision();

        $this->assertSame('noprovider', $result['code']);
        $this->assertStringContainsString(get_string('trial_provider_install_url', 'local_contenttranslator'), $result['message']);
        $this->assertCount(0, $this->history);
    }

    /**
     * One trial per site: a Wunderbyte provider that is already there is used, no key is requested.
     */
    public function test_existing_wunderbyte_provider_is_reused(): void {
        $this->create_wunderbyte_provider();

        $result = (new trial_provisioner())->provision();

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame('reused', $result['code']);
        $this->assertCount(0, $this->history, 'No second key: the trial and its credit are shared');
        $this->assertCount(1, $this->wunderbyte_views(), 'Nothing is created next to it');
    }

    /**
     * A Wunderbyte provider that was switched off is switched on again instead of requesting a key.
     */
    public function test_disabled_wunderbyte_provider_is_enabled_again(): void {
        $this->create_wunderbyte_provider(false);
        $this->assertEmpty($this->wunderbyte_views()[0]->enabled);

        $result = (new trial_provisioner())->provision();

        $this->assertSame('reused', $result['code']);
        $this->assertCount(0, $this->history);
        $this->assertNotEmpty($this->wunderbyte_views()[0]->enabled);
    }

    /**
     * Which endpoints count as the Wunderbyte LLM.
     *
     * @return array
     */
    public static function endpoint_provider(): array {
        return [
            'gateway' => ['https://llm.wunderbyte.at/v1/chat/completions', true],
            'another host of the domain' => ['https://ai.wunderbyte.at/v1', true],
            'upper case' => ['https://LLM.Wunderbyte.AT/v1', true],
            'the domain itself' => ['https://wunderbyte.at/v1', true],
            'openai' => ['https://api.openai.com/v1/chat/completions', false],
            'look-alike without the dot' => ['https://evilwunderbyte.at/v1', false],
            'domain in a subdomain' => ['https://llm.wunderbyte.at.evil.example/v1', false],
            'domain in the path' => ['https://evil.example/llm.wunderbyte.at', false],
            'no endpoint' => ['', false],
        ];
    }

    /**
     * Only a real wunderbyte.at host is taken for the Wunderbyte LLM, so a foreign provider is never reused.
     *
     * @dataProvider endpoint_provider
     * @param string $endpoint
     * @param bool $expected
     */
    public function test_wunderbyte_endpoint_detection(string $endpoint, bool $expected): void {
        $view = (object)['actionconfig' => ['core_ai\\aiactions\\generate_text' => [
            'enabled' => true, 'settings' => ['endpoint' => $endpoint],
        ]]];
        $this->assertSame($expected, trial_provisioner::targets_wunderbyte_llm($view));
    }

    /**
     * A foreign provider (someone's own OpenAI key) is not a Wunderbyte provider: the trial is requested.
     */
    public function test_foreign_provider_is_not_reused(): void {
        provider_compat::configure_provider(
            'aiprovider_openai\\provider',
            ['apikey' => 'sk-someone-elses-0123456789'],
            ['core_ai\\aiactions\\generate_text' => [
                'enabled' => true,
                'settings' => [
                    'endpoint' => 'https://api.openai.com/v1/chat/completions',
                    'model' => 'gpt-4o',
                    'systeminstruction' => 'x',
                ],
            ]],
            'My OpenAI'
        );
        $this->respond(409, ['detail' => 'x', 'code' => 'already_issued']);

        // Confirmed, so that Moodle 4.5 (one config per provider plugin) does not stop before the request.
        $result = (new trial_provisioner())->provision('openai', true);

        $this->assertSame('alreadyused', $result['code'], 'The request went out; the service decides');
        $this->assertCount(1, $this->history);
    }

    /**
     * Moodle 4.5 has one config per provider plugin: an existing OpenAI configuration is only replaced after
     * the admin confirmed it. Moodle 5.x creates a separate instance and needs no confirmation.
     */
    public function test_existing_config_is_not_replaced_unasked(): void {
        set_config('apikey', 'sk-someone-elses-0123456789', 'aiprovider_openai');
        $provisioner = new trial_provisioner();

        if (provider_compat::supports_provider_instances()) {
            $this->assertFalse($provisioner->would_overwrite('openai'));
            $this->respond_with_key();
            $this->assertSame('created', $provisioner->provision('openai')['code']);
            return;
        }

        $this->assertTrue($provisioner->would_overwrite('openai'));
        $result = $provisioner->provision('openai');
        $this->assertSame('needsconfirm', $result['code']);
        $this->assertCount(0, $this->history, 'Nothing is requested, nothing is replaced');
        $this->assertSame('sk-someone-elses-0123456789', get_config('aiprovider_openai', 'apikey'));

        $this->respond_with_key();
        $this->assertSame('created', $provisioner->provision('openai', true)['code']);
        $this->assertSame(self::APIKEY, get_config('aiprovider_openai', 'apikey'));
    }

    /**
     * The trial credit is shared with other Wunderbyte features: automatic translation still needs a budget
     * that the admin sets on purpose.
     */
    public function test_trial_does_not_start_automatic_translation(): void {
        $this->respond_with_key();
        (new trial_provisioner())->provision('openai');
        $this->assertSame(0, budget::get_limit());
        $this->assertFalse(budget::is_automation_enabled());
    }

    /**
     * A Wunderbyte provider means shared credit: the wizard does not suggest a budget and keeps automatic
     * translation off until the admin sets one on purpose. A budget that was set is kept.
     */
    public function test_wizard_budget_defaults(): void {
        $defaults = wizard_form::get_budget_defaults();
        $this->assertSame(2000000, $defaults['budgetchars'], 'Without a Wunderbyte provider the suggestion stays');
        $this->assertSame(1, $defaults['enableauto']);
        $this->assertFalse($defaults['sharedcredit']);

        $this->create_wunderbyte_provider();
        $defaults = wizard_form::get_budget_defaults();
        $this->assertSame(0, $defaults['budgetchars'], 'Nothing is pre-filled on shared credit');
        $this->assertSame(0, $defaults['enableauto'], 'Saving the wizard must not switch bulk translation on');
        $this->assertTrue($defaults['sharedcredit']);

        set_config('budgetchars', 500000, 'local_contenttranslator');
        set_config('enableauto', 1, 'local_contenttranslator');
        $defaults = wizard_form::get_budget_defaults();
        $this->assertSame(500000, $defaults['budgetchars'], 'A budget the admin chose is kept');
        $this->assertSame(1, $defaults['enableauto']);
    }

    /**
     * What the setup wizard shows: the trial box only where it helps.
     */
    public function test_wizard_box_states(): void {
        $provisioner = new trial_provisioner();

        $context = $provisioner->get_ui_context(false);
        $this->assertTrue($context['available']);
        $this->assertTrue($context['sharedcredit']);
        $this->assertFalse($context['connected']);
        $this->assertNull($provisioner->get_ui_context(true), 'A site with a working provider needs no trial');

        $this->create_wunderbyte_provider();
        $context = $provisioner->get_ui_context(true);
        $this->assertTrue($context['connected'], 'A Wunderbyte provider is shown even when text generation works');
        $this->assertTrue($context['sharedcredit']);
    }

    /**
     * Without a provider plugin the box says so instead of offering a button.
     */
    public function test_wizard_box_without_provider_plugin(): void {
        $none = new class extends trial_provisioner {
            #[\Override]
            protected function detect_strategy(): ?string {
                return null;
            }
        };
        $context = $none->get_ui_context(false);
        $this->assertTrue($context['noprovider']);
        $this->assertFalse($context['available']);
        $this->assertFalse($context['sharedcredit']);
    }

    /**
     * Consent gate: without the consent no key is requested and no event is written, even when the web service is
     * called directly.
     */
    public function test_consent_is_required(): void {
        $_POST['sesskey'] = sesskey();
        $sink = $this->redirectEvents();

        $result = request_trial_key::execute(false, 'openai');

        $this->assertFalse($result['success']);
        $this->assertSame('noconsent', $result['code']);
        $this->assertSame(get_string('trial_consent_required', 'local_contenttranslator'), $result['message']);
        $this->assertCount(0, $this->history, 'No request to Wunderbyte without consent');
        $this->assertSame([], $sink->get_events());
        $this->assertSame([], $this->wunderbyte_views());
    }

    /**
     * With the consent the event is recorded before the key is requested, and the provider is created.
     */
    public function test_consent_is_recorded_and_provider_created(): void {
        global $USER;
        $_POST['sesskey'] = sesskey();
        $sink = $this->redirectEvents();
        $this->respond_with_key();

        $result = request_trial_key::execute(true, 'openai');

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame('created', $result['code']);
        $events = array_values(array_filter($sink->get_events(), fn($event) => $event instanceof trial_consent_given));
        $this->assertCount(1, $events);
        $this->assertEquals($USER->id, $events[0]->userid);
        $this->assertCount(1, $this->wunderbyte_views());
    }

    /**
     * Reusing a provider that exists sends nothing to Wunderbyte, so it needs neither consent nor event.
     */
    public function test_reuse_needs_no_consent(): void {
        $_POST['sesskey'] = sesskey();
        // Set the provider up first: creating it logs core config events that are not ours.
        $this->create_wunderbyte_provider();
        $sink = $this->redirectEvents();

        $result = request_trial_key::execute(false);

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame('reused', $result['code']);
        $this->assertCount(0, $this->history);
        $this->assertSame([], $sink->get_events());
    }

    /**
     * On Moodle 4.5 the web service refuses to replace an existing configuration without the confirmation.
     */
    public function test_web_service_passes_the_overwrite_confirmation(): void {
        if (provider_compat::supports_provider_instances()) {
            $this->markTestSkipped('Only Moodle 4.5 has one configuration per provider plugin.');
        }
        $_POST['sesskey'] = sesskey();
        set_config('apikey', 'sk-someone-elses-0123456789', 'aiprovider_openai');

        $result = request_trial_key::execute(true, 'openai');
        $this->assertSame('needsconfirm', $result['code']);
        $this->assertCount(0, $this->history);

        $this->respond_with_key();
        $this->assertSame('created', request_trial_key::execute(true, 'openai', true)['code']);
    }

    /**
     * The key of a site is only for administrators and managers.
     */
    public function test_manager_may_start_the_trial(): void {
        $this->login_as_manager();
        $this->respond_with_key();

        $result = request_trial_key::execute(true, 'openai');

        $this->assertTrue($result['success'], $result['message']);
    }

    /**
     * Anyone else, teachers included, is refused before anything happens.
     */
    public function test_other_users_are_refused(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $_POST['sesskey'] = sesskey();

        try {
            request_trial_key::execute(true, 'openai');
            $this->fail('required_capability_exception expected');
        } catch (\required_capability_exception $e) {
            $this->assertCount(0, $this->history);
            $this->assertSame([], $this->wunderbyte_views());
        }
    }

    /**
     * A call without the sesskey is refused (CSRF protection).
     */
    public function test_sesskey_is_required(): void {
        unset($_POST['sesskey'], $_GET['sesskey']);
        $this->expectException(\moodle_exception::class);
        request_trial_key::execute(true, 'openai');
    }
}
