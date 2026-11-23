<?php
// SPDX-License-Identifier: GPL-3.0-or-later
defined('MOODLE_INTERNAL') || die();
$observers = [
    ['eventname' => '\\core\\event\\course_deleted', 'callback' => '\\local_adaptia_core\\observer::course_deleted'],
    ['eventname' => '\\core\\event\\user_deleted', 'callback' => '\\local_adaptia_core\\observer::user_deleted'],
];
