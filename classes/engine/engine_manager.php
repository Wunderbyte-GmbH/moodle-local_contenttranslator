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

use local_contenttranslator\config;
use local_contenttranslator\hook\register_engines;

/**
 * Engine registry and routing.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class engine_manager {
    /** @var engine[]|null */
    private static ?array $engines = null;

    /**
     * All engines keyed by name.
     *
     * @return engine[]
     */
    public static function get_engines(): array {
        if (self::$engines === null) {
            $hook = new register_engines();
            $hook->add_engine(new core_ai_engine());
            $hook->add_engine(new pseudo_engine());
            \core\di::get(\core\hook\manager::class)->dispatch($hook);
            self::$engines = $hook->get_engines();
        }
        return self::$engines;
    }

    /**
     * Reset (tests).
     */
    public static function reset(): void {
        self::$engines = null;
    }

    /**
     * Engine by name.
     *
     * @param string $name
     * @return engine|null
     */
    public static function get_engine(string $name): ?engine {
        return self::get_engines()[$name] ?? null;
    }

    /**
     * Ordered list of engines to try for a target language: primary, then fallback.
     *
     * @param string $sourcelang
     * @param string $targetlang
     * @param bool $externalallowed Whether external engines may be used (course opt-out).
     * @return engine[]
     */
    public static function get_engines_for_lang(string $sourcelang, string $targetlang, bool $externalallowed = true): array {
        $names = array_unique(array_filter([
            config::get_engine_for_lang($targetlang),
            config::get_fallback_engine_for_lang($targetlang),
        ]));
        $engines = [];
        foreach ($names as $name) {
            $engine = self::get_engine($name);
            if (!$engine || !$engine->supports($sourcelang, $targetlang)) {
                continue;
            }
            if (!$externalallowed && $engine->is_external()) {
                continue;
            }
            $engines[] = $engine;
        }
        return $engines;
    }

    /**
     * name => display name for settings menus.
     *
     * @return array
     */
    public static function get_menu(): array {
        $menu = [];
        foreach (self::get_engines() as $name => $engine) {
            $menu[$name] = $engine->get_display_name();
        }
        return $menu;
    }
}
