<?php
// SPDX-License-Identifier: GPL-3.0-or-later

namespace local_adaptia_core\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Coordinates access, immutable submissions and the existing TutorIA client.
 *
 * @package local_adaptia_core
 * @copyright 2026 GIHP4C
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tutoria_service {
    /**
     * Split the optional concepts, one per line.
     *
     * @param string $text Plain text.
     * @return array
     */
    public static function concepts(string $text): array {
        return array_values(array_filter(array_map('trim', preg_split('/\R/u', $text)), 'strlen'));
    }

    /**
     * Validate teacher input independently of the browser form.
     *
     * @param array $data Form values.
     * @return array Field errors, as required by the Form API.
     */
    public static function validation_errors(array $data): array {
        $errors = [];
        foreach (['topic' => 160, 'context' => 2000] as $field => $maximum) {
            $value = trim((string)($data[$field] ?? ''));
            if (\core_text::strlen($value) < 3 || \core_text::strlen($value) > $maximum
                    || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value)) {
                $errors[$field] = get_string('invalidlength', 'local_adaptia_core', $maximum);
            }
        }
        if (!preg_match('/^(?:[1-9]|10)$/D', (string)($data['difficulty'] ?? ''))) {
            $errors['difficulty'] = get_string('tutoriainvaliddifficulty', 'local_adaptia_core');
        }
        if (!in_array($data['preferred_modality'] ?? '', ['visual', 'textual', 'auditory'], true)) {
            $errors['preferred_modality'] = get_string('invaliddata', 'error');
        }
        foreach (['known_concepts', 'gaps'] as $field) {
            $values = self::concepts((string)($data[$field] ?? ''));
            if (count($values) > 20 || array_filter($values, static function($value) {
                return \core_text::strlen($value) > 160
                    || preg_match('/[\x00-\x1F\x7F]/u', $value);
            })) {
                $errors[$field] = get_string('tutoriainvalidconcepts', 'local_adaptia_core');
            }
        }
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',
                (string)($data['requestid'] ?? ''))) {
            $errors['requestid'] = get_string('invalidrequest', 'local_adaptia_core');
        }
        return $errors;
    }

    /**
     * Check the course and the dedicated generation capability.
     *
     * @param int $courseid Course identifier.
     */
    public static function require_access(int $courseid): void {
        if ($courseid == SITEID) {
            throw new \moodle_exception('invalidcourseid');
        }
        require_capability('local/adaptia_core:generatetutoria', \context_course::instance($courseid));
    }

    /**
     * Retrieve a submission in the current user's course scope.
     *
     * @param string $requestid Opaque submission identifier.
     * @param int $courseid Course identifier.
     * @return tutoria_request
     */
    public static function get_owned(string $requestid, int $courseid): tutoria_request {
        global $USER;
        self::require_access($courseid);
        $record = tutoria_request::get_record([
            'requestid' => $requestid, 'userid' => $USER->id, 'courseid' => $courseid,
        ]);
        if (!$record) {
            throw new \moodle_exception('invalidrequest', 'local_adaptia_core');
        }
        return $record;
    }

    /**
     * Submit once. Repeated POSTs recover the same record without another HTTP call.
     *
     * @param \stdClass $data Validated form data.
     * @param int $courseid Course identifier.
     * @return tutoria_request
     */
    public static function submit(\stdClass $data, int $courseid): tutoria_request {
        global $USER;
        self::require_access($courseid);
        if (self::validation_errors((array)$data)) {
            throw new \moodle_exception('invalidrequest', 'local_adaptia_core');
        }
        $payload = [
            'student_id' => 'stud_' . substr(hash('sha256', $data->requestid), 0, 24),
            'topic' => trim($data->topic), 'difficulty' => (int)$data->difficulty,
            'preferred_modality' => $data->preferred_modality, 'context' => trim($data->context),
            'performance_history' => [], 'known_concepts' => self::concepts($data->known_concepts ?? ''),
            'gaps' => self::concepts($data->gaps ?? ''),
        ];
        $snapshot = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($snapshot) > 16384) {
            throw new \moodle_exception('tutoriainputtoolarge', 'local_adaptia_core');
        }
        $lock = \core\lock\lock_config::get_lock_factory('local_adaptia_core')->get_lock(
            'tutoria:' . $data->requestid, 0, 120);
        if (!$lock) {
            throw new \moodle_exception('requestbusy', 'local_adaptia_core');
        }
        try {
            $existing = tutoria_request::get_record(['requestid' => $data->requestid]);
            if ($existing) {
                if ($existing->get('userid') != $USER->id || $existing->get('courseid') != $courseid
                        || $existing->get('inputjson') !== $snapshot) {
                    throw new \moodle_exception('invalidrequest', 'local_adaptia_core');
                }
                return $existing;
            }
            $record = new tutoria_request(0, (object)[
                'requestid' => $data->requestid, 'userid' => $USER->id,
                'courseid' => $courseid, 'inputjson' => $snapshot,
            ]);
            $record->create(); // Durable before the HTTP call; no transaction spans the provider.
            try {
                $reply = \local_adaptia_core\client::tutoria($payload, $data->requestid);
                $body = $reply['body'];
                $record->set('httpstatus', $reply['http_status']);
                $record->set('resultjson', json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                $record->set('status', $body['success'] === true ? 'succeeded' : 'failed');
                $record->set('errorcode', $body['success'] === true ? '' : $body['error']['code']);
            } catch (\moodle_exception $exception) {
                $record->set('status', 'failed');
                $record->set('errorcode', 'CONNECTION_ERROR');
            }
            $record->update();
            return $record;
        } finally {
            $lock->release();
        }
    }
}
