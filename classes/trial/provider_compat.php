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

use core\di;
use core_ai\manager as ai_manager;

/**
 * Moodle-version-agnostic view over core_ai provider configuration.
 *
 * Moodle 5.0 introduced multi-instance AI providers (the `ai_providers` table plus manager methods
 * `get_provider_instances()` / `create_/update_/enable_provider_instance()`). Moodle 4.5 has the older
 * single-instance model: one config block per aiprovider plugin in `config_plugins`, with no instance table
 * and no instance methods on the manager.
 *
 * This class hides that difference behind one shape. On 5.x it returns the real provider instances; on 4.5
 * it synthesises one instance-shaped object per configured aiprovider plugin from the plugin's flat config
 * keys, so every read site can keep iterating `->provider`, `->enabled`, `->config[...]` and
 * `->actionconfig[...]` unchanged.
 *
 * Ported from bookingextension_agent, which provisions the same trial key.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider_compat {
    /**
     * Whether the running Moodle exposes the 5.0+ multi-instance provider API.
     *
     * @return bool True on Moodle 5.0+, false on the 4.5 single-instance model.
     */
    public static function supports_provider_instances(): bool {
        try {
            $manager = di::get(ai_manager::class);
        } catch (\Throwable $e) {
            return false;
        }
        return method_exists($manager, 'get_provider_instances');
    }

    /**
     * Version-agnostic list of provider "views".
     *
     * Each returned object exposes the fields of a 5.x core_ai provider instance: `->provider` (provider
     * class name), `->enabled`, `->name`, `->id`, `->config` (incl. `apikey`) and `->actionconfig` keyed by
     * action class name with `['enabled' => bool, 'settings' => ['endpoint', 'model', ...]]`.
     *
     * @return object[] Provider instances (5.x) or synthesised views (4.5).
     */
    public static function get_provider_views(): array {
        try {
            $manager = di::get(ai_manager::class);
        } catch (\Throwable $e) {
            return [];
        }

        if (method_exists($manager, 'get_provider_instances')) {
            return array_values((array)$manager->get_provider_instances());
        }

        return self::synthesise_legacy_views();
    }

    /**
     * Create or update a provider's configuration, version-agnostically.
     *
     * On Moodle 5.x this updates the supplied existing instance (or creates a new one) via the multi-instance
     * manager API. On Moodle 4.5 there are no instances: the configuration is written as flat plugin config
     * (apikey + per-action endpoint/model/systeminstruction keys), the actions are enabled through
     * manager::set_action_state and the provider plugin is enabled.
     *
     * NOTE on 4.5: a provider plugin has a single config slot, so configuring e.g. aiprovider_openai here
     * overwrites that plugin's existing site configuration. Callers must warn before doing that.
     *
     * @param string $providerclass The provider class, e.g. 'aiprovider_openai\\provider'.
     * @param array $config Top-level provider config (e.g. ['apikey' => '...']). 'name' is display-only.
     * @param array $actionconfig 5.x-shaped action map: [ACTIONCLASS => ['enabled' => bool, 'settings' => [...]]].
     * @param string $displayname Instance display name (5.x only; 4.5 has no per-instance name).
     * @param object|null $existing The existing instance to update (5.x); ignored on 4.5.
     */
    public static function configure_provider(
        string $providerclass,
        array $config,
        array $actionconfig,
        string $displayname,
        ?object $existing = null
    ): void {
        $manager = di::get(ai_manager::class);

        if (method_exists($manager, 'get_provider_instances')) {
            if ($existing !== null) {
                $instance = $manager->update_provider_instance(
                    provider: $existing,
                    config: $config,
                    actionconfig: $actionconfig,
                );
                if (empty($instance->enabled)) {
                    $manager->enable_provider_instance($instance);
                }
                return;
            }
            $manager->create_provider_instance(
                classname: $providerclass,
                name: $displayname,
                enabled: true,
                config: $config,
                actionconfig: $actionconfig,
            );
            return;
        }

        self::configure_legacy_provider($providerclass, $config, $actionconfig);
    }

    /**
     * Write a 5.x-shaped provider config to 4.5 flat plugin config.
     *
     * @param string $providerclass
     * @param array $config
     * @param array $actionconfig
     */
    private static function configure_legacy_provider(string $providerclass, array $config, array $actionconfig): void {
        $component = self::component_from_providerclass($providerclass);
        $shortname = self::short_name_from_component($component);
        if ($shortname === '' || !\core_component::get_plugin_directory('aiprovider', $shortname)) {
            throw new \coding_exception('provider_compat: aiprovider plugin not installed: ' . $component);
        }

        // Top-level provider config (apikey, etc.). 'name' is a 5.x display label with no 4.5 equivalent.
        foreach ($config as $key => $value) {
            if ($key === 'name') {
                continue;
            }
            set_config($key, is_scalar($value) ? (string)$value : json_encode($value), $component);
        }

        // Per-action settings -> flat config keys, plus per-action enabled state.
        foreach ($actionconfig as $actionclass => $cfg) {
            if (!is_string($actionclass) || !class_exists($actionclass)) {
                // An action that does not exist on 4.5 (e.g. a Wunderbyte custom action) has no home here.
                continue;
            }
            $basename = $actionclass::get_basename();
            $settings = (array)($cfg['settings'] ?? []);
            foreach (['endpoint', 'model', 'systeminstruction'] as $settingkey) {
                if (isset($settings[$settingkey]) && $settings[$settingkey] !== '') {
                    set_config("action_{$basename}_{$settingkey}", (string)$settings[$settingkey], $component);
                }
            }
            if (method_exists(ai_manager::class, 'set_action_state')) {
                ai_manager::set_action_state($component, $basename, !empty($cfg['enabled']) ? 1 : 0);
            }
        }

        // Finally enable the provider plugin itself.
        \core\plugininfo\aiprovider::enable_plugin($shortname, 1);
    }

    /**
     * Derive the component name from a provider class name.
     *
     * @param string $providerclass e.g. 'aiprovider_openai\\provider'
     * @return string e.g. 'aiprovider_openai'
     */
    private static function component_from_providerclass(string $providerclass): string {
        $providerclass = ltrim($providerclass, '\\');
        $pos = strpos($providerclass, '\\');
        return $pos === false ? $providerclass : substr($providerclass, 0, $pos);
    }

    /**
     * Derive the aiprovider short name (without the 'aiprovider_' prefix) from a component.
     *
     * @param string $component e.g. 'aiprovider_openai'
     * @return string e.g. 'openai' ('' if not an aiprovider component)
     */
    private static function short_name_from_component(string $component): string {
        $prefix = 'aiprovider_';
        if (strpos($component, $prefix) !== 0) {
            return '';
        }
        return substr($component, strlen($prefix));
    }

    /**
     * Build instance-shaped views from 4.5 flat plugin config.
     *
     * Mirrors 5.x semantics where only *created* instances are returned: a plugin is only surfaced when it is
     * actually configured, regardless of whether it is currently enabled - disabled-but-configured must stay
     * visible so the trial can find and re-enable it.
     *
     * @return object[]
     */
    private static function synthesise_legacy_views(): array {
        $views = [];
        $plugins = \core_plugin_manager::instance()->get_plugins_of_type('aiprovider');
        foreach ($plugins as $plugin) {
            $component = (string)$plugin->component;
            $providerclass = $component . '\\provider';
            if (!class_exists($providerclass)) {
                continue;
            }

            try {
                // 4.5 providers have a no-arg constructor (see core_ai\manager::get_providers_for_actions).
                $provider = new $providerclass();
                if (!$provider->is_provider_configured()) {
                    continue;
                }
                $actionlist = (array)$provider->get_action_list();
            } catch (\Throwable $e) {
                continue;
            }

            $views[] = (object)[
                'id' => null,
                'provider' => $providerclass,
                'name' => (string)($plugin->displayname ?? $component),
                'enabled' => (bool)$plugin->is_enabled(),
                'config' => [
                    'apikey' => (string)get_config($component, 'apikey'),
                    'name' => (string)($plugin->displayname ?? $component),
                ],
                'actionconfig' => self::legacy_actionconfig($component, $actionlist),
            ];
        }

        return $views;
    }

    /**
     * Assemble a 5.x-shaped actionconfig map from 4.5 per-action flat config keys.
     *
     * @param string $component The aiprovider component (e.g. 'aiprovider_openai').
     * @param string[] $actionlist Action class names the provider supports.
     * @return array
     */
    private static function legacy_actionconfig(string $component, array $actionlist): array {
        $actionconfig = [];
        foreach ($actionlist as $actionclass) {
            if (!is_string($actionclass) || !class_exists($actionclass)) {
                continue;
            }
            $basename = $actionclass::get_basename();

            $settings = [];
            foreach (['endpoint', 'model', 'systeminstruction'] as $settingkey) {
                $value = (string)get_config($component, "action_{$basename}_{$settingkey}");
                if ($value !== '') {
                    $settings[$settingkey] = $value;
                }
            }

            $enabled = true;
            if (method_exists(ai_manager::class, 'is_action_enabled')) {
                $enabled = (bool)ai_manager::is_action_enabled($component, $actionclass);
            }

            $actionconfig[$actionclass] = [
                'enabled' => $enabled,
                'settings' => $settings,
            ];
        }

        return $actionconfig;
    }
}
