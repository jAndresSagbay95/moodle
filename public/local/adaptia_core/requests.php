<?php
// SPDX-License-Identifier: GPL-3.0-or-later
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_login();
$component = 'local_adaptia_core';
$base = new moodle_url('/local/adaptia_core/requests.php');
$id = optional_param('id', '', PARAM_ALPHANUMEXT);
$action = optional_param('action', '', PARAM_ALPHA);
$courseid = optional_param('courseid', 0, PARAM_INT);
$association = $id !== '' ? $DB->get_record('local_adaptia_requests', ['requestid' => $id, 'userid' => $USER->id]) : false;
if ($association) {
    if ($courseid && $courseid != $association->courseid) {
        throw new moodle_exception('invalidrequest', $component);
    }
    $courseid = (int)$association->courseid;
}
$course = null;
if ($courseid) {
    $course = get_course($courseid);
    require_login($course);
    $context = context_course::instance($courseid);
    require_capability('local/adaptia_core:managerequests', $context);
    if ($courseid == SITEID) {
        throw new moodle_exception('invalidcourseid');
    }
    $base->param('courseid', $courseid);
    $PAGE->set_url($base);
    $PAGE->set_context($context);
    $PAGE->set_pagelayout('incourse');
    $PAGE->set_title(get_string('requests', $component));
    $PAGE->set_heading(format_string($course->fullname));
    $PAGE->set_secondary_active_tab('local_adaptia_core_requests');
} else {
    admin_externalpage_setup('local_adaptia_core_requests');
    require_capability('moodle/site:config', context_system::instance());
}
$courseref = $courseid ? \local_adaptia_core\client::course_ref($courseid) : null;
$scopequery = $courseref ? '?course_ref=' . rawurlencode($courseref) : '';
$error = null;
$items = [];
$detail = null;
$form = null;
try {
    if ($action === 'new') {
        $cases = \local_adaptia_core\client::call('GET', '/api/v1/mock-scenarios');
        $form = new \local_adaptia_core\form\request_form(new moodle_url($base, ['action' => 'new']), [
            'requestkey' => \core\uuid::generate(), 'courseid' => $courseid, 'scenarios' => $cases,
        ]);
        if ($form->is_cancelled()) {
            redirect($base);
        }
        if ($data = $form->get_data()) {
            $target = get_course((int)$data->targetcourse);
            if ($target->id == SITEID || ($courseid && $target->id != $courseid)) {
                throw new moodle_exception('invalidrequest', $component);
            }
            require_capability('local/adaptia_core:managerequests', context_course::instance($target->id));
            $case = null;
            foreach ($cases as $candidate) {
                if ($candidate['id'] === $data->scenario_id) {
                    $case = $candidate;
                    break;
                }
            }
            if (!$case) {
                throw new moodle_exception('invalidrequest', $component);
            }
            $created = \local_adaptia_core\client::call('POST', '/api/v1/requests', [
                'workstream' => $case['workstream'], 'topic' => $case['topic'], 'objective' => $data->objective,
                'scenario_id' => $case['id'], 'course_ref' => \local_adaptia_core\client::course_ref($target->id),
            ], $data->requestkey);
            \local_adaptia_core\workflow::associate($created['id'], (int)$target->id);
            redirect(new moodle_url($base, ['id' => $created['id'], 'courseid' => $target->id]),
                get_string('requestcreated', $component), null, \core\output\notification::NOTIFY_SUCCESS);
        }
    } else if ($id !== '') {
        $path = '/api/v1/requests/' . rawurlencode($id);
        $detail = \local_adaptia_core\client::call('GET', $path . $scopequery);
        if (in_array($action, ['run', 'publish']) && data_submitted()) {
            require_sesskey();
            if ($action === 'run') {
                $endpoint = !empty($detail['scenario_id']) ? '/run-mock' : '/run-demo';
                \local_adaptia_core\client::call('POST', $path . $endpoint . $scopequery);
                redirect(new moodle_url($base, ['id' => $id]), get_string('democompleted', $component),
                    null, \core\output\notification::NOTIFY_SUCCESS);
            }
            if (!$course) {
                throw new moodle_exception('cannotpublish', $component);
            }
            $cmid = \local_adaptia_core\workflow::publish($detail, $course);
            redirect(new moodle_url('/mod/page/view.php', ['id' => $cmid]), get_string('published', $component),
                null, \core\output\notification::NOTIFY_SUCCESS);
        }
    } else {
        $items = \local_adaptia_core\client::call('GET', '/api/v1/requests' . $scopequery);
    }
} catch (\moodle_exception $exception) {
    $error = $exception->getMessage();
    if ($detail && $action === 'run') {
        try {
            $detail = \local_adaptia_core\client::call('GET', '/api/v1/requests/' . rawurlencode($id) . $scopequery);
        } catch (\moodle_exception $ignored) {
            // Keep the already retrieved request and show the service error.
        }
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('requests', $component));
echo $OUTPUT->notification(get_string('demomode', $component), 'info');
if ($course) {
    echo html_writer::tag('p', get_string('courserequests', $component, format_string($course->fullname)));
}
if ($error !== null) {
    echo $OUTPUT->notification(s($error), 'error');
}
if ($form) {
    $form->display();
    if (!$courseid && has_capability('moodle/course:create', context_system::instance())) {
        $category = $DB->get_records('course_categories', null, 'id ASC', 'id', 0, 1);
        $categoryid = $category ? reset($category)->id : 0;
        echo html_writer::tag('p', html_writer::link(new moodle_url('/course/edit.php', ['category' => $categoryid]),
            get_string('createcourse', $component)));
    }
} else if ($detail) {
    echo $OUTPUT->heading(s($detail['scenario_title'] ?? $detail['topic']), 3);
    $table = new html_table();
    $table->data = [
        [get_string('requestid', $component), s($detail['id'])],
        [get_string('workstream', $component), get_string('workstream_' . $detail['workstream'], $component)],
        [get_string('requeststatus', $component), get_string('state_' . $detail['status'], $component)],
        [get_string('objective', $component), nl2br(s($detail['objective']))],
        [get_string('created', $component), userdate(strtotime($detail['created_at']))],
    ];
    echo html_writer::table($table);
    if (!empty($detail['last_error'])) {
        echo $OUTPUT->notification(s($detail['last_error']['message']), 'error');
        echo html_writer::tag('details', html_writer::tag('summary', get_string('failedtrace', $component)) .
            html_writer::tag('pre', s(json_encode($detail['last_error']['trace'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))));
    }
    if ($detail['status'] === 'pending') {
        echo $OUTPUT->single_button(new moodle_url($base, ['action' => 'run', 'id' => $detail['id'], 'sesskey' => sesskey()]),
            get_string(!empty($detail['scenario_id']) ? 'runmock' : 'rundemo', $component), 'post');
    } else if ($detail['status'] === 'completed_mock') {
        $result = $detail['result'];
        echo $OUTPUT->heading(get_string('decision', $component), 3);
        echo html_writer::tag('p', s($result['decision']['reasoning']));
        echo html_writer::tag('p', get_string('difficulty', $component, (int)$result['decision']['parameters']['new_difficulty']));
        if (!empty($result['down'])) {
            echo $OUTPUT->heading('Misión Down', 4);
            echo html_writer::tag('p', s($result['down']['explanation']));
        }
        echo $OUTPUT->heading(get_string('preview', $component), 3);
        echo \local_adaptia_core\workflow::content($result);
        echo html_writer::tag('p', get_string('demousage', $component));
        echo $OUTPUT->heading(get_string('servicetrace', $component), 3);
        $trace = new html_table();
        $trace->head = [get_string('service', $component), 'HTTP', 'ms'];
        foreach ($result['trace'] as $step) {
            $trace->data[] = [s($step['service']), (int)$step['http_status'], (int)$step['elapsed_ms']];
        }
        echo html_writer::table($trace);
        echo html_writer::tag('details', html_writer::tag('summary', get_string('jsontrace', $component)) .
            html_writer::tag('pre', s(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)),
                ['style' => 'white-space: pre-wrap; overflow-wrap: anywhere;']));
        if ($publication = \local_adaptia_core\workflow::publication($detail['id'])) {
            echo $OUTPUT->single_button(new moodle_url('/mod/page/view.php', ['id' => $publication->cmid]),
                get_string('viewpublished', $component), 'get');
        } else if ($course && has_all_capabilities(['moodle/course:manageactivities', 'mod/page:addinstance'], context_course::instance($courseid))) {
            echo $OUTPUT->single_button(new moodle_url($base, ['action' => 'publish', 'id' => $detail['id'], 'sesskey' => sesskey()]),
                get_string('publishmock', $component), 'post');
        }
    } else {
        echo $OUTPUT->heading(get_string('result', $component), 3);
        echo html_writer::tag('pre', s($detail['result']['content']), ['style' => 'white-space: pre-wrap; overflow-wrap: anywhere;']);
        echo html_writer::tag('p', get_string('demousage', $component));
    }
    echo $OUTPUT->heading(get_string('history', $component), 3);
    $history = new html_table();
    $history->head = [get_string('created', $component), get_string('event', $component)];
    foreach ($detail['events'] as $event) {
        $history->data[] = [userdate(strtotime($event['created_at'])), get_string('event_' . $event['event'], $component)];
    }
    echo html_writer::table($history);
} else if (!$error) {
    echo $OUTPUT->single_button(new moodle_url($base, ['action' => 'new']), get_string('newrequest', $component), 'get');
    echo html_writer::tag('p', get_string($courseid ? 'courselistnotice' : 'listnotice', $component));
    if (!$items) {
        echo html_writer::tag('p', get_string('norequests', $component));
    } else {
        $table = new html_table();
        $table->head = [get_string('topic', $component), get_string('workstream', $component),
            get_string('requeststatus', $component), get_string('created', $component)];
        foreach ($items as $item) {
            $table->data[] = [html_writer::link(new moodle_url($base, ['id' => $item['id']]), s($item['scenario_title'] ?? $item['topic'])),
                get_string('workstream_' . $item['workstream'], $component),
                get_string('state_' . $item['status'], $component), userdate(strtotime($item['created_at']))];
        }
        echo html_writer::table($table);
    }
}
if ($form || $detail || $error) {
    echo html_writer::tag('p', html_writer::link($base, get_string('backtolist', $component)));
}
if ($course) {
    echo html_writer::tag('p', html_writer::link(new moodle_url('/course/view.php', ['id' => $courseid]), get_string('backtocourse', $component)));
}
echo $OUTPUT->footer();
