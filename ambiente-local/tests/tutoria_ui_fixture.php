<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Dedicated fictional users and courses for TutorIA UI checks; CLI only.
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/format/lib.php');
require_once($CFG->dirroot . '/user/lib.php');
if ($CFG->wwwroot !== 'http://localhost:8086') {
    throw new RuntimeException('Local development only.');
}
\core\session\manager::set_user(get_admin());
$action = $argv[1] ?? 'report';
$names = ['teacher' => 'adaptia.qa.tutoria.teacher', 'other' => 'adaptia.qa.tutoria.other',
    'student' => 'adaptia.qa.tutoria.student'];
$courses = [];
foreach (['main' => 'adaptia-tutoria-e2e', 'other' => 'adaptia-tutoria-other-e2e'] as $kind => $idnumber) {
    $courses[$kind] = $DB->get_record('course', ['idnumber' => $idnumber]);
    if (!$courses[$kind] && $action === 'setup') {
        $category = $DB->get_record('course_categories', [], '*', IGNORE_MULTIPLE);
        $courses[$kind] = create_course((object)[
            'category' => $category->id, 'fullname' => '[QA] TutorIA · ' . $kind,
            'shortname' => 'QA-TUTORIA-' . strtoupper($kind), 'idnumber' => $idnumber,
            'summary' => 'Prueba de interfaz con perfiles ficticios y proveedor apagado.',
            'summaryformat' => FORMAT_HTML, 'format' => 'topics', 'visible' => 1,
        ]);
    }
}
if ($action === 'setup') {
    $password = getenv('ADAPTIA_QA_PASSWORD');
    if (strlen($password) < 24) {
        throw new RuntimeException('Provide a temporary test password.');
    }
    foreach ($names as $kind => $username) {
        $user = $DB->get_record('user', ['username' => $username, 'deleted' => 0]);
        if (!$user) {
            $userid = \core_user::create_user((object)[
                'username' => $username, 'password' => $password, 'auth' => 'manual', 'confirmed' => 1,
                'mnethostid' => $CFG->mnet_localhost_id, 'firstname' => 'QA TutorIA', 'lastname' => $kind,
                'email' => $username . '@example.invalid', 'lang' => 'en',
            ]);
            $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
        } else {
            update_internal_user_password($user, $password);
        }
        foreach ($courses as $course) {
            $manual = enrol_get_plugin('manual');
            $instance = null;
            foreach (enrol_get_instances($course->id, false) as $candidate) {
                if ($candidate->enrol === 'manual') {
                    $instance = $candidate;
                    break;
                }
            }
            if (!$instance) {
                $instanceid = $manual->add_instance($course);
                $instance = $DB->get_record('enrol', ['id' => $instanceid], '*', MUST_EXIST);
            }
            $role = $DB->get_record('role', ['shortname' => $kind === 'student' ? 'student' : 'editingteacher'],
                '*', MUST_EXIST);
            $manual->enrol_user($instance, $user->id, $role->id);
        }
    }
}
$users = [];
foreach ($names as $kind => $name) {
    $users[$kind] = $DB->get_record('user', ['username' => $name, 'deleted' => 0]);
}
if ($action === 'render-success') {
    $requestid = \core\uuid::generate();
    $sections = [
        ['type' => 'explanation', 'title' => '[QA] Idea', 'text' => '<script>window.tutoriaInjected=true</script>'],
        ['type' => 'example', 'title' => '[QA] Ejemplo', 'text' => 'La derivada de x² es 2x.'],
        ['type' => 'exercise', 'title' => '[QA] Práctica', 'text' => 'Calcula la derivada de x³.'],
    ];
    $input = ['topic' => '[QA] Respuesta controlada', 'context' => 'Fixture de interfaz; no procede de un LLM.',
        'difficulty' => 5];
    $reply = ['data' => ['structure' => ['title' => '[QA] Salida controlada para revisión', 'sections' => $sections],
        'metadata_generation' => ['model_used' => 'controlled-ui-fixture', 'tokens_used' => null]]];
    $record = new \local_adaptia_core\local\tutoria_request(0, (object)[
        'requestid' => $requestid, 'userid' => $users['teacher']->id, 'courseid' => $courses['main']->id,
        'status' => 'succeeded', 'inputjson' => json_encode($input), 'resultjson' => json_encode($reply),
        'httpstatus' => 200,
    ]);
    $record->create();
    echo json_encode(['requestid' => $requestid]) . PHP_EOL;
    exit;
}
if ($action === 'privacy') {
    $context = \context_course::instance($courses['main']->id);
    $teacherid = (int)$users['teacher']->id;
    $contexts = \local_adaptia_core\privacy\provider::get_contexts_for_userid($teacherid)->get_contextids();
    if (!in_array($context->id, $contexts)) {
        throw new RuntimeException('Privacy API did not find the TutorIA course context.');
    }
    $teacherbefore = $DB->count_records('local_adaptia_tutoria', ['userid' => $teacherid]);
    $otherrecord = new \local_adaptia_core\local\tutoria_request(0, (object)[
        'requestid' => \core\uuid::generate(), 'userid' => $users['other']->id,
        'courseid' => $courses['main']->id, 'inputjson' => '{"topic":"QA privacy"}',
    ]);
    $otherrecord->create();
    $approved = new \core_privacy\local\request\approved_contextlist(
        $users['other'], 'local_adaptia_core', [$context->id]);
    \local_adaptia_core\privacy\provider::delete_data_for_user($approved);
    if ($DB->record_exists('local_adaptia_tutoria', ['id' => $otherrecord->get('id')])
            || $DB->count_records('local_adaptia_tutoria', ['userid' => $teacherid]) !== $teacherbefore) {
        throw new RuntimeException('Privacy deletion did not respect the user scope.');
    }
    echo json_encode(['context_found' => true, 'scoped_deletion' => true]) . PHP_EOL;
    exit;
}
if ($action === 'cleanup') {
    foreach ($users as $user) {
        if ($user) {
            delete_user($user); // Native observer deletes only these users' TutorIA records.
        }
    }
}
$result = ['courseid' => $courses['main'] ? (int)$courses['main']->id : null,
    'othercourseid' => $courses['other'] ? (int)$courses['other']->id : null,
    'requests' => []];
if ($users['teacher']) {
    foreach ($DB->get_records('local_adaptia_tutoria', ['userid' => $users['teacher']->id], 'id') as $row) {
        $result['requests'][] = ['requestid' => $row->requestid, 'courseid' => (int)$row->courseid,
            'status' => $row->status, 'httpstatus' => (int)$row->httpstatus, 'errorcode' => $row->errorcode];
    }
}
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
