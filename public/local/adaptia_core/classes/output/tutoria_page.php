<?php
// SPDX-License-Identifier: GPL-3.0-or-later

namespace local_adaptia_core\output;

defined('MOODLE_INTERNAL') || die();

use local_adaptia_core\local\tutoria_request;

/**
 * Presentation data for the native TutorIA course workspace.
 *
 * @package local_adaptia_core
 * @copyright 2026 GIHP4C
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tutoria_page implements \renderable, \templatable {
    /**
     * Construct the view model.
     *
     * @param int $courseid Current course.
     * @param array $records Teacher-owned submissions.
     * @param tutoria_request|null $detail Selected submission.
     */
    public function __construct(
        private int $courseid,
        private array $records,
        private ?tutoria_request $detail = null,
    ) {
    }

    /**
     * Export plain text for autoescaped Mustache output.
     *
     * @param \renderer_base $output Moodle renderer.
     * @return array
     */
    public function export_for_template(\renderer_base $output): array {
        $component = 'local_adaptia_core';
        $base = new \moodle_url('/local/adaptia_core/tutoria.php', ['courseid' => $this->courseid]);
        $data = ['newurl' => $base->out(false), 'hasdetail' => $this->detail !== null, 'requests' => []];
        foreach ($this->records as $record) {
            $input = json_decode($record->get('inputjson'), true);
            $data['requests'][] = [
                'url' => (new \moodle_url($base, ['id' => $record->get('requestid')]))->out(false),
                'topic' => $input['topic'], 'status' => get_string('tutoriastatus' . $record->get('status'), $component),
                'created' => userdate($record->get('timecreated')),
            ];
        }
        $data['hasrequests'] = !empty($data['requests']);
        if (!$this->detail) {
            return $data;
        }
        $record = $this->detail;
        $input = json_decode($record->get('inputjson'), true);
        $reply = json_decode($record->get('resultjson') ?? 'null', true);
        $data['topic'] = $input['topic'];
        $data['context'] = $input['context'];
        $data['difficulty'] = $input['difficulty'];
        $data['requestid'] = $record->get('requestid');
        $data['status'] = get_string('tutoriastatus' . $record->get('status'), $component);
        $data['pending'] = $record->get('status') === 'pending';
        $data['failed'] = $record->get('status') === 'failed';
        $data['succeeded'] = $record->get('status') === 'succeeded';
        if ($data['failed']) {
            $codes = ['INVALID_REQUEST', 'INVALID_STUDENT_PROFILE', 'AI_SERVICE_UNAVAILABLE',
                'AUTHENTICATION_FAILED', 'RATE_LIMIT_EXCEEDED', 'INTERNAL_SERVER_ERROR', 'CONNECTION_ERROR'];
            $code = in_array($record->get('errorcode'), $codes, true) ? $record->get('errorcode') : 'CONNECTION_ERROR';
            $data['errormessage'] = get_string('tutoriaerror' . strtolower($code), $component);
        }
        if ($data['succeeded']) {
            $content = $reply['data'];
            $data['title'] = $content['structure']['title'];
            $data['sections'] = array_map(static function($section) {
                return ['title' => $section['title'],
                    'paragraphs' => array_map(static fn($text) => ['text' => $text], preg_split('/\R/u', $section['text']))];
            }, $content['structure']['sections']);
            $usage = $content['metadata_generation'];
            $data['model'] = $usage['model_used'];
            $data['tokens'] = $usage['tokens_used'] === null
                ? get_string('tutoriaunknownusage', $component) : format_float($usage['tokens_used'], 0);
        }
        return $data;
    }
}
