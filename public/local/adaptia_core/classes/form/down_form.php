<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_adaptia_core\form;
defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

/** Formularios del piloto docente; no registran datos identificables de niños. */
final class down_form extends \moodleform {
    public function definition(): void {
        $form = $this->_form;
        $c = 'local_adaptia_core';
        $trial = !empty($this->_customdata['trial']);
        if ($trial) {
            $form->addElement('select', 'outcome', get_string('down_outcome', $c), [
                'correct' => 'Acierto al primer intento', 'incorrect' => 'Error al primer intento',
                'no_response' => 'Sin respuesta',
            ]);
            $form->addElement('text', 'latency_seconds', get_string('down_latency', $c));
            $form->setType('latency_seconds', PARAM_FLOAT);
            $form->setDefault('latency_seconds', 3);
            $form->addElement('select', 'help_requests', get_string('down_help', $c), range(0, 5));
            $form->addElement('text', 'paused_seconds', get_string('down_pause', $c));
            $form->setType('paused_seconds', PARAM_FLOAT);
            $form->setDefault('paused_seconds', 0);
            $form->addElement('text', 'backgrounded_seconds', get_string('down_background', $c));
            $form->setType('backgrounded_seconds', PARAM_FLOAT);
            $form->setDefault('backgrounded_seconds', 0);
            $form->addElement('select', 'instruction_repeats', get_string('down_repeats', $c), range(0, 5));
            foreach (['event_id' => PARAM_ALPHANUMEXT, 'seq' => PARAM_INT] as $key => $type) {
                $form->addElement('hidden', $key, $this->_customdata[$key]);
                $form->setType($key, $type);
            }
        } else {
            $form->addElement('select', 'game_type', get_string('down_game', $c), [
                'recognize_choose' => 'Reconoce y elige', 'relate_associate' => 'Relaciona y asocia',
                'order_sequence' => 'Ordena la secuencia',
            ]);
            $form->addElement('select', 'initial_level', get_string('down_initiallevel', $c), [
                1 => 'Básico', 2 => 'Intermedio', 3 => 'Avanzado',
            ]);
            $form->addElement('select', 'max_support_level', get_string('down_maxsupport', $c), [
                0 => 'Sin apoyo', 1 => 'Hasta apoyo visual', 2 => 'Hasta apoyo visual y auditivo',
                3 => 'Hasta modelado demostrativo',
            ]);
            $form->setDefault('max_support_level', 3);
            $form->addElement('text', 'threshold_seconds', get_string('down_threshold', $c));
            $form->setType('threshold_seconds', PARAM_FLOAT);
            $form->setDefault('threshold_seconds', 0);
            $form->addElement('hidden', 'requestkey', $this->_customdata['requestkey']);
            $form->setType('requestkey', PARAM_ALPHANUMEXT);
        }
        $form->addGroup([
            $form->createElement('submit', 'submitbutton', get_string($trial ? 'down_submittrial' : 'down_startsession', $c)),
            $form->createElement('cancel', 'cancel', 'Cancelar'),
        ], 'buttonar', '', [' '], false);
        $form->closeHeaderBefore('buttonar');
    }

    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        $fields = !empty($this->_customdata['trial'])
            ? ['latency_seconds', 'paused_seconds', 'backgrounded_seconds'] : ['threshold_seconds'];
        foreach ($fields as $field) {
            $n = $data[$field] ?? null;
            $max = $field === 'threshold_seconds' ? 120 : 600;
            if ($n === null || !is_numeric($n) || $n < 0 || $n > $max
                    || ($field === 'threshold_seconds' && $n > 0 && $n < 0.3)) {
                $errors[$field] = get_string('down_invalidtime', 'local_adaptia_core', $max);
            }
        }
        return $errors;
    }
}
