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

namespace local_contenttranslator\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;
use local_contenttranslator\budget;
use local_contenttranslator\engine\engine_manager;
use local_contenttranslator\item_manager;
use local_contenttranslator\source\registry;
use local_contenttranslator\translation_manager;
use local_contenttranslator\translator;

/**
 * Privacy provider tests.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_contenttranslator\privacy\provider
 */
final class provider_test extends provider_testcase {
    /**
     * The call to the Wunderbyte trial service (site URL and IP) is declared next to the call to the AI subsystem.
     */
    public function test_metadata_declares_the_trial_call(): void {
        $collection = provider::get_metadata(new collection('local_contenttranslator'));
        $names = array_map(fn($item) => $item->get_name(), $collection->get_collection());
        $this->assertContains('core_ai', $names);
        $this->assertContains('llm.wunderbyte.at', $names);
    }

    /**
     * Export and delete.
     */
    public function test_export_and_delete(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('targetlangs', 'de', 'local_contenttranslator');
        set_config('engine', 'pseudo', 'local_contenttranslator');
        set_config('budgetchars', 1000000, 'local_contenttranslator');
        registry::reset();
        engine_manager::reset();

        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module(
            'page',
            ['course' => $course->id, 'name' => 'Privacy', 'content' => '<p>Personal</p>']
        );
        $context = \context_module::instance($page->cmid);
        $item = item_manager::find('mod_page', 'page', 'content', (int)$page->id);
        $translation = translator::translate_item($item, 'de', budget::TRIGGER_ONDEMAND, (int)$user->id);
        translation_manager::save_human($translation, '<p>Persönlich</p>', FORMAT_HTML, (int)$user->id, true);

        $contextlist = provider::get_contexts_for_userid((int)$user->id);
        $this->assertContainsEquals($context->id, $contextlist->get_contextids());

        $userlist = new userlist($context, 'local_contenttranslator');
        provider::get_users_in_context($userlist);
        $this->assertContainsEquals($user->id, $userlist->get_userids());

        $approved = new approved_contextlist($user, 'local_contenttranslator', [$context->id]);
        provider::export_user_data($approved);
        $data = writer::with_context($context)->get_data([get_string('pluginname', 'local_contenttranslator')]);
        $this->assertNotEmpty($data->translations);
        $this->assertNotEmpty($data->history);
        $this->assertNotEmpty($data->usage);

        provider::delete_data_for_user($approved);
        $translation = translation_manager::get((int)$translation->id);
        $this->assertEquals(0, $translation->usermodified);
        $this->assertEquals(0, $translation->reviewerid);
        $this->assertSame('<p>Persönlich</p>', $translation->text, 'Content stays, only the user reference is removed');
        $this->assertEquals(0, $DB->count_records('local_contenttranslator_use', ['userid' => $user->id]));

        translation_manager::mark_reviewed($translation, (int)$user->id);
        provider::delete_data_for_users(new approved_userlist($context, 'local_contenttranslator', [$user->id]));
        $this->assertEquals(0, translation_manager::get((int)$translation->id)->reviewerid);

        translation_manager::mark_reviewed($translation, (int)$user->id);
        provider::delete_data_for_all_users_in_context($context);
        $this->assertEquals(0, translation_manager::get((int)$translation->id)->reviewerid);
    }
}
