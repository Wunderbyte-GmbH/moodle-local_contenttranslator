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

/**
 * Admin settings for local_contenttranslator.
 *
 * @package    local_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_contenttranslator\config;
use local_contenttranslator\engine\core_ai_engine;
use local_contenttranslator\engine\engine_manager;

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $ADMIN->add(
        'localplugins',
        new admin_category('local_contenttranslator_cat', get_string('pluginname', 'local_contenttranslator'))
    );
    $ADMIN->add('local_contenttranslator_cat', new admin_externalpage(
        'local_contenttranslator_dashboard',
        get_string('dashboard', 'local_contenttranslator'),
        new moodle_url('/local/contenttranslator/index.php'),
        'local/contenttranslator:viewreports'
    ));
    $ADMIN->add('local_contenttranslator_cat', new admin_externalpage(
        'local_contenttranslator_wizard',
        get_string('wizard', 'local_contenttranslator'),
        new moodle_url('/local/contenttranslator/wizard.php'),
        'local/contenttranslator:manage'
    ));

    $settings = new admin_settingpage(
        'local_contenttranslator',
        get_string('settings', 'local_contenttranslator'),
        'local/contenttranslator:manage'
    );
    $ADMIN->add('local_contenttranslator_cat', $settings);

    if ($ADMIN->fulltree) {
        $langs = get_string_manager()->get_list_of_translations(true);
        $enginemenu = engine_manager::get_menu();

        $settings->add(new admin_setting_heading(
            'local_contenttranslator/intro',
            '',
            get_string('settings_intro', 'local_contenttranslator', (object)[
                'wizard' => (new moodle_url('/local/contenttranslator/wizard.php'))->out(),
                'dashboard' => (new moodle_url('/local/contenttranslator/index.php'))->out(),
            ])
        ));

        // Languages.
        $settings->add(new admin_setting_heading(
            'local_contenttranslator/langhdr',
            get_string('wizard:languages', 'local_contenttranslator'),
            ''
        ));
        $settings->add(new admin_setting_configmultiselect(
            'local_contenttranslator/targetlangs',
            get_string('targetlangs', 'local_contenttranslator'),
            get_string('targetlangs_help', 'local_contenttranslator'),
            [],
            $langs
        ));
        $settings->add(new admin_setting_configcheckbox(
            'local_contenttranslator/defaultcourseenabled',
            get_string('defaultcourseenabled', 'local_contenttranslator'),
            get_string('defaultcourseenabled_desc', 'local_contenttranslator'),
            0
        ));

        // Engines.
        $settings->add(new admin_setting_heading(
            'local_contenttranslator/enginehdr',
            get_string('wizard:engine', 'local_contenttranslator'),
            ''
        ));
        $settings->add(new admin_setting_configselect(
            'local_contenttranslator/engine',
            get_string('engine', 'local_contenttranslator'),
            get_string('engine_help', 'local_contenttranslator'),
            'core_ai',
            $enginemenu
        ));
        $settings->add(new admin_setting_configselect(
            'local_contenttranslator/fallbackengine',
            get_string('fallbackengine', 'local_contenttranslator'),
            get_string('fallbackengine_desc', 'local_contenttranslator'),
            '',
            ['' => get_string('none')] + $enginemenu
        ));
        foreach ($enginemenu as $name => $label) {
            $settings->add(new admin_setting_configtext(
                'local_contenttranslator/price_' . $name,
                get_string('priceengine', 'local_contenttranslator', $label),
                get_string('price_help', 'local_contenttranslator'),
                0,
                PARAM_FLOAT,
                8
            ));
        }
        $settings->add(new admin_setting_configtextarea(
            'local_contenttranslator/prompttemplate',
            get_string('prompttemplate', 'local_contenttranslator'),
            get_string('prompttemplate_desc', 'local_contenttranslator'),
            core_ai_engine::default_prompt(),
            PARAM_RAW,
            80,
            14
        ));

        // Service user.
        $serviceuserid = (int)config::get('serviceuserid', 0);
        $status = get_string('serviceuser:none', 'local_contenttranslator');
        if ($serviceuserid && ($serviceuser = core_user::get_user($serviceuserid))) {
            $accepted = class_exists(\core_ai\manager::class) && \core_ai\manager::get_user_policy_status($serviceuserid);
            $status = fullname($serviceuser) . ' — ' . ($accepted
                ? get_string('serviceuser:policyaccepted', 'local_contenttranslator')
                : get_string('serviceuser:policymissing', 'local_contenttranslator'));
        }
        $settings->add(new admin_setting_configtext(
            'local_contenttranslator/serviceuserid',
            get_string('serviceuser', 'local_contenttranslator'),
            get_string('serviceuser_desc', 'local_contenttranslator') . '<br>'
            . get_string('currently', 'local_contenttranslator', $status) . ' '
            . html_writer::link(
                new moodle_url('/local/contenttranslator/wizard.php'),
                get_string('wizard', 'local_contenttranslator')
            ),
            0,
            PARAM_INT,
            6
        ));

        // Budget and automation.
        $settings->add(new admin_setting_heading(
            'local_contenttranslator/budgethdr',
            get_string('wizard:budget', 'local_contenttranslator'),
            get_string('budget_desc', 'local_contenttranslator')
        ));
        $settings->add(new admin_setting_configtext(
            'local_contenttranslator/budgetchars',
            get_string('budgetchars', 'local_contenttranslator'),
            get_string('budgetchars_desc', 'local_contenttranslator'),
            0,
            PARAM_INT,
            12
        ));
        $settings->add(new admin_setting_configcheckbox(
            'local_contenttranslator/enableauto',
            get_string('enableauto', 'local_contenttranslator'),
            get_string('enableauto_desc', 'local_contenttranslator'),
            1
        ));
        $settings->add(new admin_setting_configtext(
            'local_contenttranslator/debounce',
            get_string('debounce', 'local_contenttranslator'),
            get_string('debounce_desc', 'local_contenttranslator'),
            120,
            PARAM_INT,
            6
        ));
        $hours = [];
        for ($h = 0; $h < 24; $h++) {
            $hours[$h] = sprintf('%02d:00', $h);
        }
        $settings->add(new admin_setting_configselect(
            'local_contenttranslator/backlogstart',
            get_string('backlogstart', 'local_contenttranslator'),
            get_string('backlogwindow_desc', 'local_contenttranslator'),
            0,
            $hours
        ));
        $settings->add(new admin_setting_configselect(
            'local_contenttranslator/backlogend',
            get_string('backlogend', 'local_contenttranslator'),
            '',
            0,
            $hours
        ));
        $settings->add(new admin_setting_configtext(
            'local_contenttranslator/backloglimit',
            get_string('backloglimit', 'local_contenttranslator'),
            get_string('backloglimit_desc', 'local_contenttranslator'),
            200,
            PARAM_INT,
            6
        ));
        $settings->add(new admin_setting_configtext(
            'local_contenttranslator/scanbatch',
            get_string('scanbatch', 'local_contenttranslator'),
            get_string('scanbatch_desc', 'local_contenttranslator'),
            20,
            PARAM_INT,
            6
        ));

        // Rendering.
        $settings->add(new admin_setting_heading(
            'local_contenttranslator/renderhdr',
            get_string('rendering', 'local_contenttranslator'),
            ''
        ));
        $settings->add(new admin_setting_configselect(
            'local_contenttranslator/tenantboundary',
            get_string('tenantboundary', 'local_contenttranslator'),
            get_string('tenantboundary_desc', 'local_contenttranslator'),
            'none',
            [
                'none' => get_string('tenant:none', 'local_contenttranslator'),
                'course' => get_string('tenant:course', 'local_contenttranslator'),
                'category' => get_string('tenant:category', 'local_contenttranslator'),
            ]
        ));
        $settings->add(new admin_setting_configcheckbox(
            'local_contenttranslator/skipmultilang',
            get_string('skipmultilang', 'local_contenttranslator'),
            get_string('skipmultilang_desc', 'local_contenttranslator'),
            1
        ));
        $settings->add(new admin_setting_configcheckbox(
            'local_contenttranslator/langattributes',
            get_string('langattributes', 'local_contenttranslator'),
            get_string('langattributes_desc', 'local_contenttranslator'),
            1
        ));
        $settings->add(new admin_setting_configcheckbox(
            'local_contenttranslator/showoriginaltoggle',
            get_string('showoriginaltoggle', 'local_contenttranslator'),
            get_string('showoriginaltoggle_desc', 'local_contenttranslator'),
            1
        ));
        $settings->add(new admin_setting_configtext(
            'local_contenttranslator/historyretention',
            get_string('historyretention', 'local_contenttranslator'),
            get_string('historyretention_desc', 'local_contenttranslator'),
            365,
            PARAM_INT,
            6
        ));

        // Coverage.
        $settings->add(new admin_setting_heading(
            'local_contenttranslator/coveragehdr',
            get_string('coverage', 'local_contenttranslator'),
            ''
        ));
        $settings->add(new admin_setting_configcheckbox(
            'local_contenttranslator/discovercolumns',
            get_string('discovercolumns', 'local_contenttranslator'),
            get_string('discovercolumns_desc', 'local_contenttranslator'),
            1
        ));
        $settings->add(new admin_setting_configtextarea(
            'local_contenttranslator/skipcolumns',
            get_string('skipcolumns', 'local_contenttranslator'),
            get_string('skipcolumns_desc', 'local_contenttranslator'),
            '',
            PARAM_RAW,
            60,
            3
        ));
        $settings->add(new admin_setting_configtextarea(
            'local_contenttranslator/excludedfields',
            get_string('excludedfields', 'local_contenttranslator'),
            get_string('excludedfields_desc', 'local_contenttranslator'),
            "course.shortname",
            PARAM_RAW,
            60,
            3
        ));
        $settings->add(new admin_setting_configtextarea(
            'local_contenttranslator/subtablemap',
            get_string('subtablemap', 'local_contenttranslator'),
            get_string('subtablemap_desc', 'local_contenttranslator'),
            '',
            PARAM_RAW,
            80,
            8
        ));

        // Per language settings.
        foreach (config::get_site_target_langs() as $lang) {
            $key = 'local_contenttranslator/lang_' . str_replace('-', '_', $lang) . '_';
            $settings->add(new admin_setting_heading(
                $key . 'hdr',
                get_string('langsettings', 'local_contenttranslator', $langs[$lang] ?? $lang),
                ''
            ));
            $settings->add(new admin_setting_configselect(
                $key . 'visibility',
                get_string('visibility', 'local_contenttranslator'),
                get_string('visibility_help', 'local_contenttranslator'),
                config::VISIBILITY_IMMEDIATE,
                [
                    config::VISIBILITY_IMMEDIATE => get_string('visibility:immediate', 'local_contenttranslator'),
                    config::VISIBILITY_REVIEWED => get_string('visibility:reviewed', 'local_contenttranslator'),
                ]
            ));
            $settings->add(new admin_setting_configcheckbox(
                $key . 'showstale',
                get_string('showstale', 'local_contenttranslator'),
                get_string('showstale_desc', 'local_contenttranslator'),
                1
            ));
            $settings->add(new admin_setting_configselect(
                $key . 'badge',
                get_string('badge', 'local_contenttranslator'),
                get_string('badge_desc', 'local_contenttranslator'),
                'banner',
                [
                    'off' => get_string('badge:off', 'local_contenttranslator'),
                    'block' => get_string('badge:block', 'local_contenttranslator'),
                    'banner' => get_string('badge:banner', 'local_contenttranslator'),
                ]
            ));
            $settings->add(new admin_setting_configselect(
                $key . 'engine',
                get_string('engine', 'local_contenttranslator'),
                '',
                '',
                ['' => get_string('inherit', 'local_contenttranslator')] + $enginemenu
            ));
            $settings->add(new admin_setting_configselect(
                $key . 'formality',
                get_string('formality', 'local_contenttranslator'),
                get_string('formality_desc', 'local_contenttranslator'),
                'default',
                [
                    'default' => get_string('formality:default', 'local_contenttranslator'),
                    'more' => get_string('formality:more', 'local_contenttranslator'),
                    'less' => get_string('formality:less', 'local_contenttranslator'),
                ]
            ));
            $settings->add(new admin_setting_configtextarea(
                $key . 'styleguide',
                get_string('styleguide', 'local_contenttranslator'),
                get_string('styleguide_desc', 'local_contenttranslator'),
                '',
                PARAM_TEXT,
                60,
                3
            ));
        }
    }
}
