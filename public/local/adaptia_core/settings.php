<?php
// SPDX-License-Identifier: GPL-3.0-or-later
defined('MOODLE_INTERNAL') || die();
if ($hassiteconfig) {
    $settings = new admin_settingpage('local_adaptia_core', get_string('pluginname', 'local_adaptia_core'));
    $settings->add(new admin_setting_configtext(
        'local_adaptia_core/backendurl',
        get_string('backendurl', 'local_adaptia_core'),
        get_string('backendurl_desc', 'local_adaptia_core'),
        'http://backend:5000', PARAM_URL
    ));
    $ADMIN->add('localplugins', $settings);
    $ADMIN->add('localplugins', new admin_externalpage(
        'local_adaptia_core_status', get_string('status', 'local_adaptia_core'),
        new moodle_url('/local/adaptia_core/index.php')
    ));
    $ADMIN->add('localplugins', new admin_externalpage(
        'local_adaptia_core_requests', get_string('requests', 'local_adaptia_core'),
        new moodle_url('/local/adaptia_core/requests.php')
    ));
}
