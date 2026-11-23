<?php
// SPDX-License-Identifier: GPL-3.0-or-later
defined('MOODLE_INTERNAL') || die();

function xmldb_local_adaptia_core_upgrade($oldversion): bool {
    global $DB;
    if ($oldversion < 2026111300) {
        $file = new xmldb_file(__DIR__ . '/install.xml');
        $file->loadXMLStructure();
        $table = $file->getStructure()->getTable('local_adaptia_requests');
        if (!$DB->get_manager()->table_exists($table)) {
            $DB->get_manager()->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026111300, 'local', 'adaptia_core');
    }
    if ($oldversion < 2026111901) {
        $file = new xmldb_file(__DIR__ . '/install.xml');
        $file->loadXMLStructure();
        $table = $file->getStructure()->getTable('local_adaptia_tutoria');
        if (!$DB->get_manager()->table_exists($table)) {
            $DB->get_manager()->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026111901, 'local', 'adaptia_core');
    }
    return true;
}
