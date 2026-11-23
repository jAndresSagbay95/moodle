<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Piloto de integración para docentes: los ensayos se ingresan con datos ficticios.
require_once(__DIR__ . '/../../config.php');
$c = 'local_adaptia_core';
$courseid = required_param('courseid', PARAM_INT);
$course = get_course($courseid);
require_login($course);
$context = context_course::instance($courseid);
require_capability('local/adaptia_core:managerequests', $context);
if ($courseid == SITEID) {
    throw new moodle_exception('invalidcourseid');
}
$id = optional_param('id', '', PARAM_ALPHANUMEXT);
$action = optional_param('action', '', PARAM_ALPHA);
$base = new moodle_url('/local/adaptia_core/down.php', ['courseid' => $courseid]);
$url = new moodle_url($base, $id ? ['id' => $id] : []);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('down_title', $c));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_secondary_active_tab('local_adaptia_core_down');
$scope = '?course_ref=' . \local_adaptia_core\client::course_ref($courseid);
$api = '/api/v1/down/sessions';
$detail = null;
$items = [];
$form = null;
$error = null;
try {
    if ($id !== '') {
        $path = $api . '/' . rawurlencode($id);
        $detail = \local_adaptia_core\client::call('GET', $path . $scope);
        if ($action === 'retry' && data_submitted()) {
            require_sesskey();
            \local_adaptia_core\client::call('POST', $path . '/retry' . $scope);
            redirect($url);
        }
        if ($detail['status'] === 'active' && empty($detail['pending_event'])) {
            $form = new \local_adaptia_core\form\down_form($url, [
                'trial' => true, 'event_id' => \core\uuid::generate(), 'seq' => $detail['last_seq'] + 1,
            ]);
            if ($form->is_cancelled()) {
                redirect($base);
            }
            if ($data = $form->get_data()) {
                \local_adaptia_core\client::call('POST', $path . '/trials' . $scope, [
                    'event_id' => $data->event_id, 'seq' => (int)$data->seq, 'outcome' => $data->outcome,
                    'first_action_latency_ms' => $data->outcome === 'no_response' ? null : (int)round($data->latency_seconds * 1000),
                    'paused_ms' => (int)round($data->paused_seconds * 1000),
                    'backgrounded_ms' => (int)round($data->backgrounded_seconds * 1000),
                    'help_requests' => (int)$data->help_requests, 'instruction_repeats' => (int)$data->instruction_repeats,
                ]);
                redirect($url);
            }
        }
    } else {
        $form = new \local_adaptia_core\form\down_form($base, ['requestkey' => \core\uuid::generate()]);
        if ($form->is_cancelled()) {
            redirect(new moodle_url('/course/view.php', ['id' => $courseid]));
        }
        if ($data = $form->get_data()) {
            $prefs = ['initial_level' => (int)$data->initial_level, 'max_support_level' => (int)$data->max_support_level];
            if ($data->threshold_seconds > 0) {
                $prefs['latency_threshold_ms'] = (int)round($data->threshold_seconds * 1000);
            }
            // El seudónimo del piloto se genera por sesión, sin nombre, cédula ni diagnóstico.
            $created = \local_adaptia_core\client::call('POST', $api . $scope, [
                'student_id' => 'stud_' . substr(hash('sha256', $data->requestkey), 0, 24),
                'game_type' => $data->game_type, 'preferences' => $prefs,
            ], $data->requestkey);
            redirect(new moodle_url($base, ['id' => $created['id']]));
        }
        $items = \local_adaptia_core\client::call('GET', $api . $scope);
    }
} catch (moodle_exception $exception) {
    $error = $exception->getMessage();
    try {
        if ($id !== '') {
            $detail = \local_adaptia_core\client::call('GET', $api . '/' . rawurlencode($id) . $scope);
            if (!empty($detail['pending_event'])) {
                $form = null;
            }
        } else {
            $items = \local_adaptia_core\client::call('GET', $api . $scope);
        }
    } catch (moodle_exception $ignored) {
        // Se muestra el error sin borrar el estado que ya se había consultado.
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('down_title', $c));
echo $OUTPUT->notification(get_string('down_notice', $c), 'info');
if ($error) {
    echo $OUTPUT->notification(s($error), 'error');
}
$games = ['recognize_choose' => 'Reconoce y elige', 'relate_associate' => 'Relaciona y asocia',
    'order_sequence' => 'Ordena la secuencia'];
if ($detail) {
    echo $OUTPUT->heading(s($games[$detail['game_type']]), 3);
    echo html_writer::tag('p', get_string('down_progress', $c, (object)[
        'done' => $detail['last_seq'], 'total' => $detail['trial_limit'],
    ]));
    $prefs = $detail['preferences'];
    $threshold = isset($prefs['latency_threshold_ms']) ? ($prefs['latency_threshold_ms'] / 1000) . ' s' : 'Valor del juego';
    echo html_writer::tag('p', 'Umbral de respuesta lenta: ' . s($threshold)
        . '. Una pausa o el segundo plano excluyen esa latencia de las reglas de ritmo.');
    if (!empty($detail['last_error'])) {
        echo $OUTPUT->notification(get_string('down_retrynotice', $c), 'warning');
        echo $OUTPUT->single_button(new moodle_url($url, ['action' => 'retry', 'sesskey' => sesskey()]),
            get_string('down_retry', $c), 'post');
    }
    if (!empty($detail['last_decision'])) {
        $envelope = $detail['last_decision'];
        $decision = $envelope['data'];
        echo $OUTPUT->heading(get_string('down_next', $c), 3);
        echo html_writer::tag('p', s($decision['explanation']), ['class' => 'alert alert-success', 'role' => 'status']);
        $table = new html_table();
        $table->head = ['Parámetro', 'Antes', 'Siguiente actividad'];
        foreach (['level' => 'Nivel', 'support_level' => 'Apoyo', 'distractors' => 'Distractores',
                'max_instruction_words' => 'Máximo de palabras', 'offer_instruction_repeat' => 'Ofrecer repetir instrucción'] as $key => $label) {
            $before = $decision['config_before'][$key];
            $after = $decision['config_after'][$key];
            $table->data[] = [$label, is_bool($before) ? ($before ? 'Sí' : 'No') : (int)$before,
                is_bool($after) ? ($after ? 'Sí' : 'No') : (int)$after];
        }
        echo html_writer::div(html_writer::table($table), 'table-responsive');
        echo html_writer::tag('p', 'Reglas aplicadas: ' . s(implode(', ', $decision['rules_applied']) ?: 'Sin cambio'));
        echo html_writer::tag('p', 'Versiones: reglas ' . s($envelope['metadata']['rules_version'])
            . ', parámetros ' . s($envelope['metadata']['params_version']) . ', modelo ' . s($envelope['metadata']['model_version']));
    }
    if ($detail['status'] === 'completed') {
        echo $OUTPUT->notification(get_string('down_completed', $c), 'success');
    }
    if ($form) {
        echo $OUTPUT->heading(get_string('down_recordtrial', $c), 3);
        $form->display();
    }
    if (!empty($detail['history'])) {
        echo $OUTPUT->heading(get_string('down_history', $c), 3);
        $table = new html_table();
        $table->head = ['Ensayo', 'Resultado', 'Decisión'];
        $outcomes = ['correct' => 'Acierto', 'incorrect' => 'Error', 'no_response' => 'Sin respuesta'];
        foreach ($detail['history'] as $event) {
            $table->data[] = [(int)$event['seq'], s($outcomes[$event['event']['outcome'] ?? ''] ?? 'Inicio'),
                s($event['decision']['data']['explanation'])];
        }
        echo html_writer::div(html_writer::table($table), 'table-responsive');
        echo html_writer::tag('details', html_writer::tag('summary', get_string('down_trace', $c))
            . html_writer::tag('pre', s(json_encode($detail['history'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)),
                ['style' => 'white-space:pre-wrap;overflow-wrap:anywhere']));
    }
    echo html_writer::tag('p', html_writer::link($base, get_string('down_back', $c)));
} else {
    if ($form) {
        $form->display();
    }
    if ($items) {
        echo $OUTPUT->heading(get_string('down_sessions', $c), 3);
        $table = new html_table();
        $table->head = ['Juego', 'Ensayos', 'Estado', 'Fecha'];
        foreach ($items as $item) {
            $table->data[] = [html_writer::link(new moodle_url($base, ['id' => $item['id']]), s($games[$item['game_type']])),
                (int)$item['last_seq'], get_string('down_status_' . $item['status'], $c),
                userdate(strtotime($item['created_at']))];
        }
        echo html_writer::table($table);
    }
}
echo html_writer::tag('p', html_writer::link(new moodle_url('/course/view.php', ['id' => $courseid]), 'Volver al curso'));
echo $OUTPUT->footer();
