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

namespace local_contenttranslator\source;

use local_contenttranslator\hook\register_sources;

/**
 * Registry of content sources.
 *
 * Built-in sources (course, sections, categories, every activity module, mapped sub-tables) plus
 * whatever other plugins register through the register_sources hook.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class registry {
    /** @var registry|null Singleton */
    private static ?registry $instance = null;

    /** @var content_source[] key => source */
    private array $sources = [];

    /** @var array table => key */
    private array $tables = [];

    /**
     * Get the registry.
     *
     * @return registry
     */
    public static function get(): registry {
        if (self::$instance === null) {
            self::$instance = new registry();
            self::$instance->build();
        }
        return self::$instance;
    }

    /**
     * Drop the singleton (tests, after settings changes).
     */
    public static function reset(): void {
        self::$instance = null;
        subtable_map::reset();
    }

    /**
     * Collect built-in and hook-registered sources.
     */
    private function build(): void {
        global $DB;
        $hook = new register_sources();
        $hook->add_source(new course_source());
        $hook->add_source(new section_source());
        $hook->add_source(new category_source());

        $modules = $DB->get_records('modules', ['visible' => 1], '', 'id, name');
        $installed = \core_component::get_plugin_list('mod');
        $byname = [];
        foreach ($modules as $module) {
            if (!isset($installed[$module->name]) || !$DB->get_manager()->table_exists($module->name)) {
                continue;
            }
            $byname[$module->name] = (int)$module->id;
            $hook->add_source(new module_source($module->name, (int)$module->id));
        }
        foreach (subtable_map::get() as $table => $def) {
            if (!isset($byname[$def['module']])) {
                continue;
            }
            $hook->add_source(new subtable_source($table, $def, $byname[$def['module']]));
        }

        \core\di::get(\core\hook\manager::class)->dispatch($hook);

        foreach ($hook->get_sources() as $key => $source) {
            if ($source->is_user_generated()) {
                // Learner generated content is out of scope in v1 (GDPR, cost).
                continue;
            }
            $this->sources[$key] = $source;
            foreach ($source->get_tables() as $table) {
                $this->tables[$table] = $key;
            }
        }
    }

    /**
     * All sources.
     *
     * @return content_source[]
     */
    public function get_sources(): array {
        return $this->sources;
    }

    /**
     * Source by component and item type.
     *
     * @param string $component
     * @param string $itemtype
     * @return content_source|null
     */
    public function get_source(string $component, string $itemtype): ?content_source {
        return $this->sources[$component . '/' . $itemtype] ?? null;
    }

    /**
     * Source for a table name (event objecttable).
     *
     * @param string $table
     * @return content_source|null
     */
    public function get_source_for_table(string $table): ?content_source {
        $key = $this->tables[$table] ?? null;
        return $key ? $this->sources[$key] : null;
    }

    /**
     * Whether a table is covered by any source.
     *
     * @param string $table
     * @return bool
     */
    public function has_table(string $table): bool {
        return isset($this->tables[$table]);
    }
}
