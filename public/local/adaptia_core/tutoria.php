<?php
// SPDX-License-Identifier: GPL-3.0-or-later

/**
 * Native TutorIA course workspace and POST/redirect/GET controller.
 *
 * @package local_adaptia_core
 * @copyright 2026 GIHP4C
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once(__DIR__ . '/../../config.php');

$courseid = required_param('courseid', PARAM_INT);
$course = get_course($courseid);
require_login($course);
\local_adaptia_core\local\tutoria_service::require_access($courseid);
$context = context_course::instance($courseid);
$component = 'local_adaptia_core';
$id = optional_param('id', '', PARAM_ALPHANUMEXT);
$base = new moodle_url('/local/adaptia_core/tutoria.php', ['courseid' => $courseid]);
$url = new moodle_url($base, $id ? ['id' => $id] : []);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_cacheable(false);
$PAGE->set_title(get_string('tutoriatitle', $component));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_secondary_active_tab('local_adaptia_core_tutoria');
$form = null;
$detail = null;
$error = null;
if ($id !== '') {
    $detail = \local_adaptia_core\local\tutoria_service::get_owned($id, $courseid);
} else {
    $form = new \local_adaptia_core\form\tutoria_form($base, ['requestid' => \core\uuid::generate()]);
    if ($form->is_cancelled()) {
        redirect(new moodle_url('/course/view.php', ['id' => $courseid]));
    }
    if ($data = $form->get_data()) {
        require_sesskey();
        // Avoid keeping the user's session locked during the bounded service call.
        \core\session\manager::write_close();
        try {
            $record = \local_adaptia_core\local\tutoria_service::submit($data, $courseid);
            redirect(new moodle_url($base, ['id' => $record->get('requestid')]));
        } catch (moodle_exception $exception) {
            $error = $exception->getMessage();
        }
    }
}
$records = \local_adaptia_core\local\tutoria_request::get_records(
    ['userid' => $USER->id, 'courseid' => $courseid], 'id', 'DESC', 0, 20);
$renderer = $PAGE->get_renderer('local_adaptia_core');
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('tutoriatitle', $component));
echo $OUTPUT->notification(get_string('tutorianotice', $component), 'info');
if ($error !== null) {
    echo $OUTPUT->notification(s($error), 'error');
}
if ($form) {
    $form->display();
}
echo $renderer->render(new \local_adaptia_core\output\tutoria_page($courseid, $records, $detail));
echo html_writer::tag('p', html_writer::link(
    new moodle_url('/course/view.php', ['id' => $courseid]), get_string('backtocourse', $component)));
echo $OUTPUT->footer();
