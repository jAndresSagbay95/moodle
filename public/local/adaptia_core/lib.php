<?php
// SPDX-License-Identifier: GPL-3.0-or-later
defined('MOODLE_INTERNAL') || die();

/** Add the integration workspace to courses for authorised teachers and managers. */
function local_adaptia_core_extend_navigation_course($navigation, $course, $context): void {
    if ($course->id == SITEID) {
        return;
    }
    if (has_capability('local/adaptia_core:generatetutoria', $context)) {
        $navigation->add(
            get_string('tutoriatitle', 'local_adaptia_core'),
            new moodle_url('/local/adaptia_core/tutoria.php', ['courseid' => $course->id]),
            navigation_node::TYPE_SETTING, null, 'local_adaptia_core_tutoria', new pix_icon('i/settings', '')
        );
    }
    if (!has_capability('local/adaptia_core:managerequests', $context)) {
        return;
    }
    $navigation->add(
        get_string('requests', 'local_adaptia_core'),
        new moodle_url('/local/adaptia_core/requests.php', ['courseid' => $course->id]),
        navigation_node::TYPE_SETTING, null, 'local_adaptia_core_requests', new pix_icon('i/settings', '')
    );
    $navigation->add(
        get_string('down_title', 'local_adaptia_core'),
        new moodle_url('/local/adaptia_core/down.php', ['courseid' => $course->id]),
        navigation_node::TYPE_SETTING, null, 'local_adaptia_core_down', new pix_icon('i/settings', '')
    );
}
