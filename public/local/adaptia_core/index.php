<?php
// SPDX-License-Identifier: GPL-3.0-or-later
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
admin_externalpage_setup('local_adaptia_core_status');
require_capability('moodle/site:config', context_system::instance());

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pluginname', 'local_adaptia_core'));
echo html_writer::tag('p', get_string('introduction', 'local_adaptia_core'));
echo $OUTPUT->single_button(new moodle_url('/local/adaptia_core/requests.php', ['action' => 'new']),
    get_string('newrequest', 'local_adaptia_core'), 'get');
echo $OUTPUT->single_button(new moodle_url('/local/adaptia_core/requests.php'),
    get_string('requests', 'local_adaptia_core'), 'get');
if (optional_param('check', 0, PARAM_BOOL) && data_submitted()) {
    require_sesskey();
    $ok = \local_adaptia_core\client::health();
    echo $OUTPUT->notification(
        get_string($ok ? 'connectionok' : 'connectionfailed', 'local_adaptia_core'),
        $ok ? 'success' : 'error'
    );
}
echo html_writer::tag('p', get_string('nocost', 'local_adaptia_core'));
echo $OUTPUT->single_button(
    new moodle_url('/local/adaptia_core/index.php', ['check' => 1, 'sesskey' => sesskey()]),
    get_string('checkconnection', 'local_adaptia_core'), 'post'
);
echo $OUTPUT->footer();
