<?php
// Configuración exclusiva del entorno local de ADAPTIA.
unset($CFG);
global $CFG;
$CFG = new stdClass();
$CFG->dbtype = 'pgsql';
$CFG->dblibrary = 'native';
$CFG->dbhost = 'postgres';
$CFG->dbname = 'moodle';
$CFG->dbuser = 'moodle';
$CFG->dbpass = getenv('MOODLE_DB_PASSWORD');
$CFG->prefix = 'mdl_';
$CFG->dboptions = ['dbpersist' => false, 'dbport' => 5432, 'dbsocket' => ''];
$CFG->wwwroot = getenv('MOODLE_WWWROOT') ?: 'http://localhost:8086';
$CFG->dataroot = '/var/www/moodledata';
$CFG->admin = 'admin';
$CFG->directorypermissions = 02770;
$CFG->passwordpolicy = 1;
$CFG->disableupdateautodeploy = true;
$CFG->noemailever = true;
// La conexión entre servicios usa la red privada de Docker en este entorno.
$CFG->curlsecurityblockedhosts = '';
$CFG->curlsecurityallowedport = "80\n443\n5000";
require_once(__DIR__ . '/lib/setup.php');
