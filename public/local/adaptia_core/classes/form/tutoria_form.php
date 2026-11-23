<?php
// SPDX-License-Identifier: GPL-3.0-or-later

namespace local_adaptia_core\form;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Course-scoped TutorIA form using Moodle validation and sesskey protection.
 *
 * @package local_adaptia_core
 * @copyright 2026 GIHP4C
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tutoria_form extends \moodleform {
    /** Define native form elements. */
    public function definition(): void {
        $form = $this->_form;
        $component = 'local_adaptia_core';
        $form->addElement('text', 'topic', get_string('topic', $component), ['maxlength' => 160]);
        $form->setType('topic', PARAM_RAW_TRIMMED);
        $form->addRule('topic', null, 'required', null, 'client');
        $form->addElement('select', 'difficulty', get_string('tutoriadifficulty', $component),
            array_combine(range(1, 10), range(1, 10)));
        $form->setDefault('difficulty', 5);
        $form->addElement('select', 'preferred_modality', get_string('tutoriamodality', $component), [
            'textual' => get_string('tutoriatextual', $component),
            'visual' => get_string('tutoriavisual', $component),
            'auditory' => get_string('tutoriaauditory', $component),
        ]);
        $form->addElement('textarea', 'context', get_string('tutoriacontext', $component),
            ['rows' => 4, 'cols' => 50, 'maxlength' => 2000]);
        $form->setType('context', PARAM_RAW_TRIMMED);
        $form->addRule('context', null, 'required', null, 'client');
        $form->addElement('header', 'profile', get_string('tutoriaprofile', $component));
        foreach (['known_concepts', 'gaps'] as $field) {
            $form->addElement('textarea', $field, get_string('tutoria' . $field, $component),
                ['rows' => 3, 'cols' => 50, 'maxlength' => 3400]);
            $form->setType($field, PARAM_RAW_TRIMMED);
            $form->addHelpButton($field, 'tutoriaconcepts', $component);
        }
        $form->addElement('hidden', 'requestid', $this->_customdata['requestid']);
        $form->setType('requestid', PARAM_ALPHANUMEXT);
        $this->add_action_buttons(true, get_string('tutoriasubmit', $component));
    }

    /**
     * Apply the same validation used by the application service.
     *
     * @param array $data Submitted values.
     * @param array $files Uploaded files.
     * @return array
     */
    public function validation($data, $files): array {
        return array_merge(parent::validation($data, $files),
            \local_adaptia_core\local\tutoria_service::validation_errors($data));
    }
}
