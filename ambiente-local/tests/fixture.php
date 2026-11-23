<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Local, fictional accounts used by the browser integration check. Never served as a web page.
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/format/lib.php');
require_once($CFG->dirroot . '/user/lib.php');
if ($CFG->wwwroot !== 'http://localhost:8086') {
    throw new moodle_exception('localenvironmentonly', 'local_adaptia_core');
}
\core\session\manager::set_user(get_admin());
$action = $argv[1] ?? 'report';
$course = $DB->get_record('course', ['idnumber' => 'adaptia-mock-e2e']);
$names = ['teacher' => 'adaptia.qa.teacher', 'otherteacher' => 'adaptia.qa.other', 'student' => 'adaptia.qa.student'];
if ($action === 'setup') {
    $password = getenv('ADAPTIA_QA_PASSWORD');
    if (strlen($password) < 16) {
        throw new invalid_parameter_exception('Set a temporary ADAPTIA_QA_PASSWORD.');
    }
    if (!$course) {
        $category = $DB->get_record('course_categories', [], '*', IGNORE_MULTIPLE);
        $course = create_course((object)[
            'category' => $category->id, 'fullname' => '[MOCK] ADAPTIA · Integración E2E',
            'shortname' => 'ADAPTIA-MOCK-E2E', 'idnumber' => 'adaptia-mock-e2e',
            'summary' => 'Curso de prueba con perfiles ficticios y servicios HTTP mock. No utiliza APIs de pago.',
            'summaryformat' => FORMAT_HTML, 'format' => 'topics', 'visible' => 1,
        ]);
    }
    $manual = enrol_get_plugin('manual');
    $instances = enrol_get_instances($course->id, false);
    $instance = null;
    foreach ($instances as $candidate) {
        if ($candidate->enrol === 'manual') {
            $instance = $candidate;
            break;
        }
    }
    if (!$instance) {
        $instanceid = $manual->add_instance($course);
        $instance = $DB->get_record('enrol', ['id' => $instanceid], '*', MUST_EXIST);
    }
    foreach ($names as $kind => $name) {
        $user = $DB->get_record('user', ['username' => $name, 'deleted' => 0]);
        if (!$user) {
            $userid = \core_user::create_user((object)[
                'username' => $name, 'password' => $password, 'auth' => 'manual', 'confirmed' => 1,
                'mnethostid' => $CFG->mnet_localhost_id, 'firstname' => 'Prueba', 'lastname' => $kind,
                'email' => $name . '@example.invalid', 'lang' => 'en',
            ]);
            $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
        } else {
            update_internal_user_password($user, $password);
        }
        $role = $DB->get_record('role', ['shortname' => $kind === 'student' ? 'student' : 'editingteacher'], '*', MUST_EXIST);
        $manual->enrol_user($instance, $user->id, $role->id);
    }
} else if ($action === 'cleanup') {
    foreach ($names as $name) {
        if ($user = $DB->get_record('user', ['username' => $name, 'deleted' => 0])) {
            foreach ($DB->get_records('local_adaptia_requests', ['userid' => $user->id]) as $request) {
                if ($request->cmid && $DB->record_exists('course_modules', ['id' => $request->cmid])) {
                    course_delete_module($request->cmid);
                }
            }
            delete_user($user);
        }
    }
}
$result = ['courseid' => $course ? (int)$course->id : null, 'users' => [], 'publications' => [], 'views' => 0];
foreach ($names as $kind => $name) {
    if ($user = $DB->get_record('user', ['username' => $name, 'deleted' => 0])) {
        $result['users'][$kind] = (int)$user->id;
    }
}
if ($course) {
    $result['publications'] = array_values($DB->get_records('local_adaptia_requests', ['courseid' => $course->id], 'id'));
    $result['views'] = $DB->count_records_select('logstore_standard_log',
        'courseid = :course AND userid = :userid AND component = :component AND action = :action', [
            'course' => $course->id, 'userid' => $result['users']['student'] ?? 0,
            'component' => 'mod_page', 'action' => 'viewed',
        ]);
}
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
