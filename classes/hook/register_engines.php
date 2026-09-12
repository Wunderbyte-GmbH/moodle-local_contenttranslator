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

namespace local_contenttranslator\hook;

use local_contenttranslator\engine\engine;

/**
 * Hook dispatched when the engine registry is built.
 *
 * Plugins can add further translation engines (DeepL, Azure, a future core translate action).
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\core\attribute\label('Allows plugins to register additional translation engines with the content translator.')]
#[\core\attribute\tags('local_contenttranslator')]
final class register_engines {
    /** @var engine[] */
    private array $engines = [];

    /**
     * Register an engine.
     *
     * @param engine $engine
     */
    public function add_engine(engine $engine): void {
        $this->engines[$engine->get_name()] = $engine;
    }

    /**
     * All registered engines keyed by name.
     *
     * @return engine[]
     */
    public function get_engines(): array {
        return $this->engines;
    }
}
