<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_adaptia_core\form;
defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

final class request_form extends \moodleform {
    public function definition(): void {
        $form = $this->_form;
        $component = 'local_adaptia_core';
        if (!empty($this->_customdata['courseid'])) {
            $form->addElement('hidden', 'targetcourse', $this->_customdata['courseid']);
            $form->setType('targetcourse', PARAM_INT);
        } else {
            $form->addElement('course', 'targetcourse', get_string('targetcourse', $component), [
                'requiredcapabilities' => ['local/adaptia_core:managerequests', 'moodle/course:manageactivities', 'mod/page:addinstance'],
                'includefrontpage' => false,
            ]);
            $form->addRule('targetcourse', get_string('required'), 'required', null, 'client');
        }
        $options = [];
        foreach ($this->_customdata['scenarios'] as $case) {
            $options[$case['id']] = $case['title'];
        }
        $form->addElement('select', 'scenario_id', get_string('scenario', $component), $options);
        $form->addElement('textarea', 'objective', get_string('objective', $component), ['rows' => 3, 'cols' => 60]);
        $form->setType('objective', PARAM_TEXT);
        $form->addRule('objective', get_string('required'), 'required', null, 'client');
        $form->setDefault('objective', get_string('defaultobjective', $component));
        $form->addElement('hidden', 'requestkey', $this->_customdata['requestkey']);
        $form->setType('requestkey', PARAM_ALPHANUMEXT);
        $form->addElement('static', 'notice', '', get_string('datanotice', $component));
        $this->add_action_buttons(true, get_string('createrequest', $component));
    }

    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        foreach (['objective' => 2000] as $field => $maximum) {
            $length = \core_text::strlen(trim($data[$field]));
            if ($length < 3 || $length > $maximum) {
                $errors[$field] = get_string('invalidlength', 'local_adaptia_core', $maximum);
            }
        }
        if (empty($data['targetcourse']) || $data['targetcourse'] == SITEID) {
            $errors['targetcourse'] = get_string('choosecourse', 'local_adaptia_core');
        }
        return $errors;
    }
}
