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

use local_contenttranslator\source\content_source;

/**
 * Hook dispatched when the content-source registry is built.
 *
 * Any plugin can listen to this hook and register a {@see content_source} for its own tables,
 * so that mod_booking and friends ship their sources in their own repositories.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\core\attribute\label('Allows plugins to register translatable content sources with the content translator.')]
#[\core\attribute\tags('local_contenttranslator')]
final class register_sources {
    /** @var content_source[] */
    private array $sources = [];

    /**
     * Register a content source.
     *
     * @param content_source $source
     */
    public function add_source(content_source $source): void {
        $this->sources[$source->get_key()] = $source;
    }

    /**
     * All registered sources, keyed by "component/itemtype".
     *
     * @return content_source[]
     */
    public function get_sources(): array {
        return $this->sources;
    }
}
