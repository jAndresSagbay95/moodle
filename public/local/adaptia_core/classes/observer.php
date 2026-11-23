<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_adaptia_core;
defined('MOODLE_INTERNAL') || die();

final class observer {
    public static function course_deleted(\core\event\course_deleted $event): void {
        global $DB;
        $DB->delete_records('local_adaptia_requests', ['courseid' => $event->objectid]);
        $DB->delete_records('local_adaptia_tutoria', ['courseid' => $event->objectid]);
    }

    public static function user_deleted(\core\event\user_deleted $event): void {
        global $DB;
        $DB->delete_records('local_adaptia_requests', ['userid' => $event->objectid]);
        $DB->delete_records('local_adaptia_tutoria', ['userid' => $event->objectid]);
    }
}
