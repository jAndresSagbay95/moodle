<?php
// SPDX-License-Identifier: GPL-3.0-or-later

namespace local_adaptia_core\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Teacher-owned TutorIA submission, stored through the Moodle Persistent API.
 *
 * @package local_adaptia_core
 * @copyright 2026 GIHP4C
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tutoria_request extends \core\persistent {
    /** @var string Moodle table name without prefix. */
    const TABLE = 'local_adaptia_tutoria';

    /**
     * Define the persisted properties.
     *
     * @return array
     */
    protected static function define_properties(): array {
        return [
            'requestid' => ['type' => PARAM_ALPHANUMEXT],
            'userid' => ['type' => PARAM_INT],
            'courseid' => ['type' => PARAM_INT],
            'status' => ['type' => PARAM_ALPHA, 'default' => 'pending',
                'choices' => ['pending', 'succeeded', 'failed']],
            'inputjson' => ['type' => PARAM_RAW],
            'resultjson' => ['type' => PARAM_RAW, 'default' => null, 'null' => NULL_ALLOWED],
            'errorcode' => ['type' => PARAM_ALPHANUMEXT, 'default' => ''],
            'httpstatus' => ['type' => PARAM_INT, 'default' => 0],
        ];
    }
}
