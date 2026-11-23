<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_adaptia_core\privacy;
defined('MOODLE_INTERNAL') || die();

final class provider implements \core_privacy\local\metadata\provider,
        \core_privacy\local\request\core_user_data_provider,
        \core_privacy\local\request\core_userlist_provider {
    public static function get_metadata(\core_privacy\local\metadata\collection $collection): \core_privacy\local\metadata\collection {
        $collection->add_database_table('local_adaptia_requests', [
            'requestid' => 'privacy:metadata:requestid',
            'userid' => 'privacy:metadata:userid',
            'courseid' => 'privacy:metadata:courseid',
            'cmid' => 'privacy:metadata:cmid',
            'timecreated' => 'privacy:metadata:timecreated',
        ], 'privacy:metadata:local');
        $collection->add_database_table('local_adaptia_tutoria', [
            'requestid' => 'privacy:metadata:requestid',
            'userid' => 'privacy:metadata:userid',
            'courseid' => 'privacy:metadata:courseid',
            'inputjson' => 'privacy:metadata:tutoriainput',
            'resultjson' => 'privacy:metadata:tutoriaresult',
            'status' => 'privacy:metadata:tutoriastatus',
            'errorcode' => 'privacy:metadata:tutoriastatus',
            'httpstatus' => 'privacy:metadata:tutoriastatus',
            'usermodified' => 'privacy:metadata:usermodified',
            'timecreated' => 'privacy:metadata:timecreated',
            'timemodified' => 'privacy:metadata:timemodified',
        ], 'privacy:metadata:tutoria');
        $collection->add_external_location_link('tutoria', [
            'request' => 'privacy:metadata:tutoriainput',
            'response' => 'privacy:metadata:tutoriaresult',
        ], 'privacy:metadata:tutoriaexternal');
        return $collection->add_external_location_link('adaptia_backend', [
            'actor' => 'privacy:metadata:actor',
            'request' => 'privacy:metadata:request',
            'course_ref' => 'privacy:metadata:course',
        ], 'privacy:metadata:backend');
    }

    public static function get_contexts_for_userid(int $userid): \core_privacy\local\request\contextlist {
        $contexts = new \core_privacy\local\request\contextlist();
        $contexts->add_from_sql('SELECT c.id FROM {context} c
            WHERE c.contextlevel = :level AND c.instanceid IN (
                SELECT courseid FROM {local_adaptia_requests} WHERE userid = :requestuser
                UNION SELECT courseid FROM {local_adaptia_tutoria} WHERE userid = :tutoriauser
            )', ['level' => CONTEXT_COURSE, 'requestuser' => $userid, 'tutoriauser' => $userid]);
        return $contexts;
    }

    public static function export_user_data(\core_privacy\local\request\approved_contextlist $contextlist) {
        global $DB;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_COURSE) {
                continue;
            }
            $records = $DB->get_records('local_adaptia_requests', [
                'courseid' => $context->instanceid, 'userid' => $contextlist->get_user()->id,
            ], 'id', 'requestid, courseid, cmid, timecreated');
            if ($records) {
                \core_privacy\local\request\writer::with_context($context)->export_data(
                    [get_string('pluginname', 'local_adaptia_core')], (object)['requests' => array_values($records)]);
            }
            $tutoria = $DB->get_records('local_adaptia_tutoria', [
                'courseid' => $context->instanceid, 'userid' => $contextlist->get_user()->id,
            ], 'id');
            if ($tutoria) {
                \core_privacy\local\request\writer::with_context($context)->export_data(
                    [get_string('pluginname', 'local_adaptia_core'), get_string('tutoriatitle', 'local_adaptia_core')],
                    (object)['requests' => array_values($tutoria)]);
            }
        }
    }

    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;
        if ($context->contextlevel == CONTEXT_COURSE) {
            $DB->delete_records('local_adaptia_requests', ['courseid' => $context->instanceid]);
            $DB->delete_records('local_adaptia_tutoria', ['courseid' => $context->instanceid]);
        }
    }

    public static function delete_data_for_user(\core_privacy\local\request\approved_contextlist $contextlist) {
        global $DB;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel == CONTEXT_COURSE) {
                $DB->delete_records('local_adaptia_requests', [
                    'courseid' => $context->instanceid, 'userid' => $contextlist->get_user()->id,
                ]);
                $DB->delete_records('local_adaptia_tutoria', [
                    'courseid' => $context->instanceid, 'userid' => $contextlist->get_user()->id,
                ]);
            }
        }
    }

    public static function get_users_in_context(\core_privacy\local\request\userlist $userlist) {
        $context = $userlist->get_context();
        if ($context->contextlevel == CONTEXT_COURSE) {
            $userlist->add_from_sql('userid', 'SELECT userid FROM {local_adaptia_requests} WHERE courseid = :requestcourse
                UNION SELECT userid FROM {local_adaptia_tutoria} WHERE courseid = :tutoriacourse',
                ['requestcourse' => $context->instanceid, 'tutoriacourse' => $context->instanceid]);
        }
    }

    public static function delete_data_for_users(\core_privacy\local\request\approved_userlist $userlist) {
        global $DB;
        $context = $userlist->get_context();
        if ($context->contextlevel == CONTEXT_COURSE && $userlist->get_userids()) {
            [$insql, $params] = $DB->get_in_or_equal($userlist->get_userids(), SQL_PARAMS_NAMED);
            $params['courseid'] = $context->instanceid;
            $DB->delete_records_select('local_adaptia_requests', "courseid = :courseid AND userid $insql", $params);
            $DB->delete_records_select('local_adaptia_tutoria', "courseid = :courseid AND userid $insql", $params);
        }
    }
}
