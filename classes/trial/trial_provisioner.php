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

namespace local_contenttranslator\trial;

use cache;
use core\http_client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;

/**
 * Provisions a working AI provider from a Wunderbyte trial key.
 *
 * There is exactly one trial per site, shared by all Wunderbyte plugins (booking agent, local_wizard, content
 * translator), and the trial credit is shared too. So before anything is requested the site is searched for an
 * existing Wunderbyte provider, which is reused. Only when there is none the full chain runs:
 *
 *   nonce -> POST {base}/api/moodle-trial {wwwroot, nonce} -> {apikey} -> core_ai provider instance
 *
 * The trial service verifies the request origin by calling back to trial_challenge.php?token={nonce} (its
 * path is on the allowlist of the service, #2386), so the nonce is cached before the POST. The endpoint is
 * always the LiteLLM proxy, hard-coded to https://llm.wunderbyte.at (intentionally not an admin setting).
 * Ported from bookingextension_agent.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class trial_provisioner {
    /** @var string Hard-coded LiteLLM/trial service base URL (intentionally not an admin setting). */
    public const BASE_URL = 'https://llm.wunderbyte.at';

    /** @var string The one generate_text action the translator needs. */
    private const ACTION_GENERATE_TEXT = 'core_ai\\aiactions\\generate_text';

    /** @var string Display name of a freshly created provider instance. */
    private const INSTANCE_NAME = 'Wunderbyte';

    /** @var string Host suffix that marks an endpoint as the Wunderbyte LLM gateway. */
    private const HOST_SUFFIX = 'wunderbyte.at';

    /** @var int Seconds to wait for the trial service (its own back-channel check and LiteLLM call take a moment). */
    private const HTTP_TIMEOUT = 25;

    /** @var int Seconds to wait for the usage percentage lookup (a quick, unauthenticated call). */
    private const USAGE_TIMEOUT = 10;

    /**
     * Run the trial provisioning.
     *
     * Reuses an existing Wunderbyte provider if there is one (no key is requested then, the credit is shared).
     * Callers must have checked the capability and the data-protection consent before calling this.
     *
     * @param string|null $strategy 'wunderbyte' | 'openai'; null = auto-detect from installed providers.
     * @param bool $confirmoverwrite The admin confirmed that an existing provider config may be replaced (4.5).
     * @return array{success: bool, message: string, code: string} User-facing result; code is machine readable.
     */
    public function provision(?string $strategy = null, bool $confirmoverwrite = false): array {
        if (!class_exists('\\core_ai\\manager')) {
            return $this->fail('coreai', get_string('trial_coreai_unavailable', 'local_contenttranslator'));
        }

        // One trial per site: a Wunderbyte provider started by another plugin is reused, no new key.
        $existing = $this->find_wunderbyte_instances();
        if ($existing) {
            return $this->reuse($this->pick_instance($existing));
        }

        $strategy = $strategy ?? $this->detect_strategy();
        if ($strategy === null) {
            return $this->fail('noprovider', get_string(
                'trial_provider_required',
                'local_contenttranslator',
                get_string('trial_provider_install_url', 'local_contenttranslator')
            ));
        }

        // Moodle 4.5 has one config slot per provider plugin: never replace someone's config unasked.
        if ($this->would_overwrite($strategy) && !$confirmoverwrite) {
            return $this->fail('needsconfirm', get_string('trial_overwrite_required', 'local_contenttranslator'));
        }

        // Mint a nonce and cache it so the trial service's origin check (trial_challenge.php) succeeds.
        $nonce = random_string(32);
        cache::make('local_contenttranslator', 'trialnonce')->set('nonce_' . $nonce, $nonce);

        $exchange = $this->exchange_nonce($nonce);
        if (!$exchange['success']) {
            return $this->fail($exchange['code'], $exchange['message'], $exchange['debug'] ?? '');
        }

        try {
            provider_compat::configure_provider(
                $this->provider_class($strategy),
                ['apikey' => (string)$exchange['apikey']],
                $this->build_actionconfig($strategy),
                self::INSTANCE_NAME,
            );
        } catch (\Throwable $e) {
            return $this->fail(
                'failed',
                get_string('trial_provision_failed', 'local_contenttranslator'),
                'provider instance creation failed: ' . $e->getMessage()
            );
        }

        return $this->ok('created', 'trial_provider_created');
    }

    /**
     * Whether this site already has a Wunderbyte provider that a trial request would reuse.
     *
     * @return bool
     */
    public function has_wunderbyte_provider(): bool {
        return class_exists('\\core_ai\\manager') && $this->find_wunderbyte_instances() !== [];
    }

    /**
     * What the setup wizard should show about the trial.
     *
     * @return array{state: string, strategy: ?string, overwrite: bool}
     *         state is one of coreai_unavailable, connected (a usable Wunderbyte provider exists), reusable
     *         (one exists but is disabled or lacks generate_text), noprovider (no provider plugin installed),
     *         available (the trial can be started).
     */
    public function get_status(): array {
        if (!class_exists('\\core_ai\\manager')) {
            return ['state' => 'coreai_unavailable', 'strategy' => null, 'overwrite' => false];
        }
        $existing = $this->find_wunderbyte_instances();
        if ($existing) {
            $usable = array_filter($existing, fn($instance) => $this->is_usable($instance));
            return [
                'state' => $usable ? 'connected' : 'reusable',
                'strategy' => null,
                'overwrite' => false,
            ];
        }
        $strategy = $this->detect_strategy();
        if ($strategy === null) {
            return ['state' => 'noprovider', 'strategy' => null, 'overwrite' => false];
        }
        return ['state' => 'available', 'strategy' => $strategy, 'overwrite' => $this->would_overwrite($strategy)];
    }

    /**
     * Template context of the trial box in the setup wizard, or null when the box should not be shown.
     *
     * The box is shown when a Wunderbyte provider is (or could be) in use, and when the site has no working
     * AI provider at all. A site that already works with another provider is not offered a trial.
     *
     * @param bool $engineavailable Whether the Moodle AI engine can already generate text.
     * @return array|null
     */
    public function get_ui_context(bool $engineavailable): ?array {
        $status = $this->get_status();
        $state = $status['state'];
        $inuse = in_array($state, ['connected', 'reusable'], true);
        if (!$inuse && $engineavailable) {
            return null;
        }
        return [
            'connected' => $state === 'connected',
            'reusable' => $state === 'reusable',
            'available' => $state === 'available',
            'noprovider' => $state === 'noprovider',
            'coreaiunavailable' => $state === 'coreai_unavailable',
            'sharedcredit' => in_array($state, ['connected', 'reusable', 'available'], true),
            'overwrite' => $status['overwrite'],
            'installurl' => get_string('trial_provider_install_url', 'local_contenttranslator'),
        ];
    }

    /**
     * Whether a provider view targets the Wunderbyte LLM gateway.
     *
     * Reads the instance's own actionconfig, so it works for disabled instances too. A provider of another
     * type (e.g. OpenAI) whose endpoint points at llm.wunderbyte.at counts: that is what the agent creates
     * when only the standard provider is installed.
     *
     * @param object $instance A provider instance (5.x) or synthesised view (4.5).
     * @return bool
     */
    public static function targets_wunderbyte_llm(object $instance): bool {
        foreach ((array)($instance->actionconfig ?? []) as $cfg) {
            $settings = (array)(((array)$cfg)['settings'] ?? []);
            $endpoint = trim((string)($settings['endpoint'] ?? $settings['apiendpoint'] ?? ''));
            if ($endpoint !== '' && self::is_wunderbyte_host($endpoint)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether an endpoint URL belongs to a wunderbyte.at host.
     *
     * @param string $endpoint
     * @return bool
     */
    private static function is_wunderbyte_host(string $endpoint): bool {
        $host = \core_text::strtolower(trim((string)parse_url($endpoint, PHP_URL_HOST)));
        return $host === self::HOST_SUFFIX || str_ends_with($host, '.' . self::HOST_SUFFIX);
    }

    /**
     * Whether configuring the provider would replace an existing site configuration.
     *
     * Only Moodle 4.5: a provider plugin has a single config slot there, so a configured aiprovider_openai
     * (that does not point at the Wunderbyte LLM, those are reused) would lose its key and endpoints.
     *
     * @param string $strategy 'wunderbyte' | 'openai'
     * @return bool
     */
    public function would_overwrite(string $strategy): bool {
        if (provider_compat::supports_provider_instances()) {
            return false;
        }
        $class = $this->provider_class($strategy);
        foreach (provider_compat::get_provider_views() as $instance) {
            if ((string)($instance->provider ?? '') === $class) {
                return true;
            }
        }
        return false;
    }

    /**
     * Provider instances of this site that already talk to the Wunderbyte LLM and have a key.
     *
     * @return object[]
     */
    protected function find_wunderbyte_instances(): array {
        $found = [];
        foreach (provider_compat::get_provider_views() as $instance) {
            if (!self::targets_wunderbyte_llm($instance)) {
                continue;
            }
            // Without a key there is nothing to reuse; the trial service then answers "already used" (409).
            if (trim((string)(((array)($instance->config ?? []))['apikey'] ?? '')) === '') {
                continue;
            }
            $found[] = $instance;
        }
        return $found;
    }

    /**
     * Decide which provider plugin to provision against.
     *
     * Wunderbyte is preferred; the OpenAI-compatible provider pointed at llm.wunderbyte.at is the fallback.
     *
     * @return string|null 'wunderbyte', 'openai', or null when neither is installed.
     */
    protected function detect_strategy(): ?string {
        if (\core_component::get_plugin_directory('aiprovider', 'wunderbyte')) {
            return 'wunderbyte';
        }
        if (\core_component::get_plugin_directory('aiprovider', 'openai')) {
            return 'openai';
        }
        return null;
    }

    /**
     * Prefer a usable instance, otherwise the first one.
     *
     * @param object[] $instances
     * @return object
     */
    private function pick_instance(array $instances): object {
        foreach ($instances as $instance) {
            if ($this->is_usable($instance)) {
                return $instance;
            }
        }
        return reset($instances);
    }

    /**
     * Whether an instance is enabled and serves generate_text.
     *
     * @param object $instance
     * @return bool
     */
    private function is_usable(object $instance): bool {
        if (empty($instance->enabled)) {
            return false;
        }
        $action = (array)(((array)($instance->actionconfig ?? []))[self::ACTION_GENERATE_TEXT] ?? []);
        $settings = (array)($action['settings'] ?? []);
        return !empty($action['enabled']) && !empty($settings['endpoint']);
    }

    /**
     * Reuse an existing Wunderbyte provider: enable it and make sure it serves generate_text.
     *
     * @param object $instance
     * @return array{success: bool, message: string, code: string}
     */
    private function reuse(object $instance): array {
        if (!$this->is_usable($instance)) {
            try {
                $actionconfig = (array)($instance->actionconfig ?? []);
                $generatetext = (array)($actionconfig[self::ACTION_GENERATE_TEXT] ?? []);
                $settings = (array)($generatetext['settings'] ?? []);
                if (empty($generatetext['enabled']) || empty($settings['endpoint'])) {
                    $actionconfig[self::ACTION_GENERATE_TEXT] = $this->generate_text_config();
                }
                provider_compat::configure_provider(
                    (string)$instance->provider,
                    (array)$instance->config,
                    $actionconfig,
                    self::INSTANCE_NAME,
                    $instance,
                );
            } catch (\Throwable $e) {
                return $this->fail(
                    'failed',
                    get_string('trial_provision_failed', 'local_contenttranslator'),
                    'reusing the provider failed: ' . $e->getMessage()
                );
            }
        }
        return $this->ok('reused', 'trial_provider_reused');
    }

    /**
     * Provider class of a strategy.
     *
     * @param string $strategy 'wunderbyte' | 'openai'
     * @return string
     */
    private function provider_class(string $strategy): string {
        return $strategy === 'wunderbyte' ? 'aiprovider_wunderbyte\\provider' : 'aiprovider_openai\\provider';
    }

    /**
     * POST the nonce to the trial endpoint and normalise the answer.
     *
     * The answer is mapped by the `code` field of the service; a service without it (older version) is mapped
     * by its HTTP status.
     *
     * @param string $nonce
     * @return array{success: bool, message: string, code: string, apikey?: string, debug?: string}
     */
    private function exchange_nonce(string $nonce): array {
        global $CFG;

        $url = rtrim(self::BASE_URL, '/') . '/api/moodle-trial';
        $request = new Request(
            'POST',
            $url,
            ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            json_encode(['wwwroot' => $CFG->wwwroot, 'nonce' => $nonce]),
        );

        $client = \core\di::get(http_client::class);
        try {
            $response = $client->send($request, [
                RequestOptions::HTTP_ERRORS => false,
                RequestOptions::TIMEOUT => self::HTTP_TIMEOUT,
            ]);
        } catch (GuzzleException $e) {
            // Moodle cannot reach the service at all: an outgoing firewall or proxy on this server.
            return [
                'success' => false,
                'code' => 'noconnection',
                'message' => get_string('trial_error_noconnection', 'local_contenttranslator'),
                'debug' => 'trial endpoint unreachable: ' . $e->getMessage(),
            ];
        }

        $status = $response->getStatusCode();
        $body = json_decode((string)$response->getBody(), true);
        $detail = (is_array($body) && !empty($body['detail']) && is_string($body['detail'])) ? $body['detail'] : '';

        if ($status === 200 && is_array($body) && !empty($body['apikey'])) {
            // The endpoint the service echoes back is its internal LiteLLM URL (e.g. http://litellm:4000),
            // which Moodle cannot reach: it is ignored on purpose and BASE_URL is always used.
            return ['success' => true, 'message' => '', 'code' => 'ok', 'apikey' => (string)$body['apikey']];
        }

        $code = (is_array($body) && isset($body['code']) && is_string($body['code'])) ? $body['code'] : '';
        if ($code === '') {
            $code = match ($status) {
                403 => 'origin_unverified',
                409 => 'already_issued',
                503 => 'unavailable',
                default => '',
            };
        }

        switch ($code) {
            case 'origin_unverified':
                // The service could not verify this site: not reachable from outside, or the call is blocked.
                return $this->error('unreachable', get_string('trial_error_unreachable', 'local_contenttranslator', $CFG->wwwroot));
            case 'already_issued':
                // The one trial of this site was already issued (by any Wunderbyte plugin).
                return $this->error('alreadyused', get_string(
                    'trial_already_used',
                    'local_contenttranslator',
                    get_string('trial_buy_url', 'local_contenttranslator')
                ));
            case 'ip_limit':
                return $this->error('iplimit', get_string('trial_error_iplimit', 'local_contenttranslator'));
            case 'global_limit':
                return $this->error('globallimit', get_string('trial_error_globallimit', 'local_contenttranslator'));
            case 'unavailable':
                return $this->error('unavailable', get_string('trial_error_unavailable', 'local_contenttranslator'));
        }

        // An abuse cap of an older service (no code): its detail is the user-facing reason.
        if ($status === 429) {
            return $this->error(
                'ratelimited',
                $detail !== '' ? $detail : get_string('trial_provision_failed', 'local_contenttranslator')
            );
        }

        return [
            'success' => false,
            'code' => 'failed',
            'message' => get_string('trial_provision_failed', 'local_contenttranslator'),
            'debug' => 'trial endpoint HTTP ' . $status . ($detail !== '' ? ': ' . $detail : ''),
        ];
    }

    /**
     * A failed exchange with a known cause.
     *
     * @param string $code
     * @param string $message
     * @return array{success: bool, message: string, code: string}
     */
    private function error(string $code, string $message): array {
        return ['success' => false, 'message' => $message, 'code' => $code];
    }

    /**
     * Action config of the generate_text action, pointed at the Wunderbyte LLM.
     *
     * The system instruction is the text core_ai itself stores for a new provider: it is sent to the model as
     * it is, so it must never be a placeholder. The temperature is low on purpose: translation stays faithful.
     *
     * @return array
     */
    private function generate_text_config(): array {
        return [
            'enabled' => true,
            'modelsettings' => [],
            'settings' => [
                'endpoint' => rtrim(self::BASE_URL, '/') . '/v1/chat/completions',
                'model' => 'wunderbyte-privat',
                'systeminstruction' => get_string('action_generate_text_instruction', 'core_ai'),
                'temperature' => 0.3,
            ],
        ];
    }

    /**
     * Build the action config for the chosen strategy.
     *
     * The translator itself only needs generate_text. With the Wunderbyte provider the full action set of the
     * booking agent is written as well: the trial is shared, and the agent (or local_wizard) may find this
     * provider instance later and expects embeddings and its planner actions on it. The key grants the model
     * aliases wunderbyte-privat (chat), wunderbyte-privat-mini (compact planner) and wunderbyte-embeddings.
     *
     * @param string $strategy 'wunderbyte' | 'openai'
     * @return array
     */
    private function build_actionconfig(string $strategy): array {
        $generatetext = [self::ACTION_GENERATE_TEXT => $this->generate_text_config()];
        if ($strategy === 'openai') {
            // The OpenAI provider has no embeddings, planner or agent-reply actions.
            return $generatetext;
        }

        $base = rtrim(self::BASE_URL, '/');
        $chat = $base . '/v1/chat/completions';
        return [
            'aiprovider_wunderbyte\\aiactions\\generate_embeddings' => [
                'enabled' => true,
                'settings' => [
                    'endpoint' => $base . '/v1/embeddings',
                    'model' => 'wunderbyte-embeddings',
                    // The alias serves 3584-dim vectors; a mismatching declaration makes stored vectors unusable.
                    'dimensions' => 3584,
                ],
            ],
            'aiprovider_wunderbyte\\aiactions\\planner_decide' => [
                'enabled' => true,
                'modelsettings' => [],
                'settings' => [
                    'endpoint' => $chat,
                    'model' => 'wunderbyte-privat-mini',
                    'systeminstruction' => 'Act as a compact planner and return a structured routing decision as plain JSON.',
                    'temperature' => 0.0,
                ],
            ],
            'aiprovider_wunderbyte\\aiactions\\generate_agent_reply' => [
                'enabled' => true,
                'modelsettings' => [],
                'settings' => [
                    'endpoint' => $chat,
                    'model' => 'wunderbyte-privat',
                    'systeminstruction' => 'Compose the final user-facing response in the requested language.',
                    'temperature' => 0.3,
                ],
            ],
        ] + $generatetext;
    }

    /**
     * Shorthand for a successful result.
     *
     * @param string $code Machine readable outcome.
     * @param string $stringkey Language string key of the user-facing message.
     * @return array{success: bool, message: string, code: string}
     */
    private function ok(string $code, string $stringkey): array {
        return ['success' => true, 'message' => get_string($stringkey, 'local_contenttranslator'), 'code' => $code];
    }

    /**
     * Shorthand for a failed result.
     *
     * In developer debug mode the technical detail (HTTP status or exception message) is appended, so a failure
     * is self-diagnosing; otherwise the generic message hides the real cause.
     *
     * @param string $code Machine readable reason.
     * @param string $message User-facing message.
     * @param string $debugdetail Technical detail, only shown when developer debugging is on.
     * @return array{success: bool, message: string, code: string}
     */
    private function fail(string $code, string $message, string $debugdetail = ''): array {
        if ($debugdetail !== '' && debugging('', DEBUG_DEVELOPER)) {
            $message .= ' [' . $debugdetail . ']';
        }
        return ['success' => false, 'message' => $message, 'code' => $code];
    }

    /**
     * Usage percentage of this site's Wunderbyte key (trial or bought), cached for 5 minutes.
     *
     * Returns null when no usable Wunderbyte provider is configured, the lookup failed, or the service answered
     * "unavailable". Never exposes euro amounts: /api/shop/usage deliberately answers with a percentage only.
     *
     * @return array{unlimited: bool, percent: float, percentremaining: float, expiresat: ?int, shopurl: ?string}|null
     */
    public function get_usage(): ?array {
        $apikey = $this->active_wunderbyte_apikey();
        if ($apikey === null) {
            return null;
        }
        $cache = cache::make('local_contenttranslator', 'aiusage');
        $cachekey = 'usage_' . sha1($apikey);
        $cached = $cache->get($cachekey);
        if ($cached !== false) {
            return $cached;
        }
        $result = $this->fetch_usage($apikey);
        $cache->set($cachekey, $result);
        return $result;
    }

    /**
     * The apikey of the site's active Wunderbyte provider, or null when there is none.
     *
     * @return string|null
     */
    private function active_wunderbyte_apikey(): ?string {
        $instances = $this->find_wunderbyte_instances();
        if (!$instances) {
            return null;
        }
        $instance = $this->pick_instance($instances);
        $apikey = trim((string)(((array)($instance->config ?? []))['apikey'] ?? ''));
        return $apikey !== '' ? $apikey : null;
    }

    /**
     * POST the key to the usage endpoint and normalise the answer.
     *
     * @param string $apikey
     * @return array{unlimited: bool, percent: float, percentremaining: float, expiresat: ?int, shopurl: ?string}|null
     */
    private function fetch_usage(string $apikey): ?array {
        $url = rtrim(self::BASE_URL, '/') . '/api/shop/usage';
        $request = new Request(
            'POST',
            $url,
            ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            json_encode(['apikey' => $apikey]),
        );

        $client = \core\di::get(http_client::class);
        try {
            $response = $client->send($request, [
                RequestOptions::HTTP_ERRORS => false,
                RequestOptions::TIMEOUT => self::USAGE_TIMEOUT,
            ]);
        } catch (GuzzleException $e) {
            return null;
        }

        if ($response->getStatusCode() !== 200) {
            return null;
        }
        $body = json_decode((string)$response->getBody(), true);
        $state = (is_array($body) && is_string($body['state'] ?? null)) ? $body['state'] : 'unavailable';
        if ($state === 'unavailable') {
            return null;
        }

        return [
            'unlimited' => $state === 'unlimited',
            'percent' => (float)($body['percent'] ?? 0),
            'percentremaining' => (float)($body['percent_remaining'] ?? 100),
            'expiresat' => !empty($body['expiresat']) ? strtotime((string)$body['expiresat']) : null,
            'shopurl' => !empty($body['shopurl']) ? (string)$body['shopurl'] : null,
        ];
    }
}
