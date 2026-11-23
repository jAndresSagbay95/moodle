<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_adaptia_core;
defined('MOODLE_INTERNAL') || die();

final class workflow {
    public static function associate(string $requestid, int $courseid): void {
        global $DB, $USER;
        $lock = \core\lock\lock_config::get_lock_factory('local_adaptia_core')->get_lock('associate:' . $requestid, 10);
        if (!$lock) {
            throw new \moodle_exception('requestbusy', 'local_adaptia_core');
        }
        try {
            $existing = $DB->get_record('local_adaptia_requests', ['requestid' => $requestid]);
            if (!$existing) {
                $DB->insert_record('local_adaptia_requests', (object)[
                    'requestid' => $requestid, 'userid' => $USER->id, 'courseid' => $courseid,
                    'cmid' => 0, 'timecreated' => time(),
                ]);
            } else if ($existing->userid != $USER->id || $existing->courseid != $courseid) {
                throw new \moodle_exception('invalidrequest', 'local_adaptia_core');
            }
        } finally {
            $lock->release();
        }
    }

    public static function publication(string $requestid): ?\stdClass {
        global $DB, $USER;
        $record = $DB->get_record('local_adaptia_requests', ['requestid' => $requestid, 'userid' => $USER->id]);
        if (!$record || !$record->cmid || !$DB->record_exists('course_modules', ['id' => $record->cmid, 'course' => $record->courseid])) {
            return null;
        }
        return $record;
    }

    /** Build safe Moodle HTML from plain-text response fields; no remote HTML is trusted. */
    public static function content(array $result): string {
        $html = \html_writer::tag('p', get_string('publishedmocknotice', 'local_adaptia_core'), ['class' => 'alert alert-info']);
        $html .= \html_writer::tag('h2', s($result['structure']['title']));
        foreach ($result['structure']['sections'] as $section) {
            $html .= \html_writer::tag('h3', s($section['title']));
            $html .= \html_writer::tag('p', nl2br(s($section['text'])));
        }
        return $html;
    }

    public static function publish(array $request, \stdClass $course): int {
        global $DB, $CFG, $USER;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/course/format/lib.php');
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->libdir . '/resourcelib.php');
        $context = \context_course::instance($course->id);
        require_capability('local/adaptia_core:managerequests', $context);
        require_capability('moodle/course:manageactivities', $context);
        require_capability('mod/page:addinstance', $context);
        if ($course->id == SITEID || $request['status'] !== 'completed_mock'
                || ($request['result']['mode'] ?? '') !== 'mock'
                || $request['course_ref'] !== client::course_ref($course->id)) {
            throw new \moodle_exception('cannotpublish', 'local_adaptia_core');
        }
        $record = $DB->get_record('local_adaptia_requests', [
            'requestid' => $request['id'], 'userid' => $USER->id, 'courseid' => $course->id,
        ], '*', MUST_EXIST);
        $lock = \core\lock\lock_config::get_lock_factory('local_adaptia_core')->get_lock('publish:' . $request['id'], 10);
        if (!$lock) {
            throw new \moodle_exception('requestbusy', 'local_adaptia_core');
        }
        try {
            if ($existing = self::publication($request['id'])) {
                return (int)$existing->cmid;
            }
            can_add_moduleinfo($course, 'page', 0);
            $transaction = $DB->start_delegated_transaction();
            $info = (object)[
                'modulename' => 'page', 'module' => $DB->get_field('modules', 'id', ['name' => 'page'], MUST_EXIST),
                'name' => '[MOCK] ' . $request['result']['structure']['title'],
                'intro' => get_string('publishedmocknotice', 'local_adaptia_core'), 'introformat' => FORMAT_HTML,
                'content' => self::content($request['result']), 'contentformat' => FORMAT_HTML,
                'section' => 0, 'visible' => 1, 'visibleoncoursepage' => 1,
                'display' => RESOURCELIB_DISPLAY_AUTO, 'printintro' => 0, 'printlastmodified' => 1,
                'completion' => COMPLETION_TRACKING_NONE, 'groupmode' => 0,
                'cmidnumber' => 'adaptia-mock-' . $request['id'],
            ];
            $module = add_moduleinfo($info, $course);
            $DB->set_field('local_adaptia_requests', 'cmid', $module->coursemodule, ['id' => $record->id]);
            $transaction->allow_commit();
            return (int)$module->coursemodule;
        } finally {
            $lock->release();
        }
    }
}
