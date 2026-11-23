<?php
// SPDX-License-Identifier: GPL-3.0-or-later
$string['pluginname'] = 'Integración ADAPTIA';
$string['down_title'] = 'Misión Down · Integración';
$string['down_notice'] = 'Piloto docente con el motor real de Misión Down y datos ficticios. Registra ensayos de prueba y comprueba los ajustes de la siguiente actividad. No se utilizan APIs de IA de pago; esta pantalla no sustituye los microjuegos ni la validación pedagógica.';
$string['down_outcome'] = 'Resultado observado';
$string['down_latency'] = 'Tiempo hasta la primera respuesta (segundos)';
$string['down_help'] = 'Ayudas solicitadas';
$string['down_pause'] = 'Tiempo en pausa (segundos)';
$string['down_background'] = 'Tiempo en segundo plano (segundos)';
$string['down_repeats'] = 'Repeticiones de la instrucción';
$string['down_game'] = 'Juego';
$string['down_initiallevel'] = 'Nivel inicial definido por el profesional';
$string['down_maxsupport'] = 'Apoyo máximo permitido';
$string['down_threshold'] = 'Umbral de respuesta lenta (segundos; 0 = valor del juego)';
$string['down_submittrial'] = 'Registrar ensayo y consultar adaptación';
$string['down_startsession'] = 'Iniciar sesión de prueba';
$string['down_invalidtime'] = 'Escribe un tiempo entre 0 y {$a} segundos. El umbral personalizado debe ser de al menos 0,3 segundos.';
$string['down_progress'] = 'Ensayos registrados: {$a->done} de {$a->total}.';
$string['down_retrynotice'] = 'No se completó la evaluación. Se conservan la configuración y el ensayo pendiente. Reinténtalo antes de registrar otra respuesta.';
$string['down_retry'] = 'Reintentar evaluación pendiente';
$string['down_next'] = 'Configuración de la siguiente actividad';
$string['down_completed'] = 'Sesión de prueba completada. Puedes revisar su historial o iniciar otra.';
$string['down_recordtrial'] = 'Registrar el siguiente ensayo';
$string['down_history'] = 'Historial de decisiones';
$string['down_trace'] = 'Ver eventos, evidencia y versiones de las decisiones';
$string['down_back'] = 'Volver a las sesiones de prueba';
$string['down_sessions'] = 'Mis sesiones de prueba en este curso';
$string['down_status_starting'] = 'Pendiente de inicio';
$string['down_status_active'] = 'Activa';
$string['down_status_completed'] = 'Completada';
$string['backendurl'] = 'Dirección del backend de integración';
$string['backendurl_desc'] = 'Dirección accesible desde el servidor Moodle. Las claves de los proveedores de IA se administran en el backend.';
$string['status'] = 'Estado de conexión';
$string['checkconnection'] = 'Comprobar conexión';
$string['connectionok'] = 'El backend de ADAPTIA está respondiendo.';
$string['connectionfailed'] = 'El backend no devolvió una respuesta de estado válida. Revisa la dirección y su disponibilidad.';
$string['introduction'] = 'Elige un curso y un caso JSON, ejecuta los servicios simulados de las tesis, revisa sus respuestas y publica una Página MOCK en Moodle.';
$string['nocost'] = 'Esta comprobación no realiza llamadas de pago a las APIs de IA.';
$string['requests'] = 'Solicitudes ADAPTIA';
$string['demomode'] = 'Modo MOCK: Motor, TutorIA y Misión Down devuelven JSON de prueba por HTTP. No se utilizan APIs de IA de pago.';
$string['datanotice'] = 'Usa datos ficticios en el objetivo. Los servicios reciben el perfil sintético del caso seleccionado; la solicitud queda vinculada a tu curso de Moodle.';
$string['adaptia_core:managerequests'] = 'Gestionar solicitudes propias de ADAPTIA en el curso';
$string['courserequests'] = 'Curso: {$a}';
$string['courselistnotice'] = 'Tus 50 solicitudes más recientes en este curso. Los demás docentes no ven tus solicitudes.';
$string['backtocourse'] = 'Volver al curso';
$string['localenvironmentonly'] = 'Este comando solo se puede ejecutar en el entorno local de desarrollo.';
$string['privacy:metadata:course'] = 'Identificador seudónimo del curso asociado a la solicitud, calculado en el servidor Moodle.';
$string['workstream'] = 'Línea de trabajo';
$string['workstream_integracion'] = 'Pruebas de integración';
$string['workstream_doctorado'] = 'Investigación doctoral';
$string['workstream_tutoria'] = 'TutorIA';
$string['workstream_mision_down'] = 'Misión Down';
$string['topic'] = 'Tema';
$string['objective'] = 'Objetivo';
$string['createrequest'] = 'Crear solicitud';
$string['newrequest'] = 'Crear contenido mock';
$string['invalidlength'] = 'Escribe entre 3 y {$a} caracteres.';
$string['requestcreated'] = 'Solicitud guardada en ADAPTIA.';
$string['requestid'] = 'Identificador de solicitud';
$string['requeststatus'] = 'Estado';
$string['state_pending'] = 'Pendiente de ejecución';
$string['state_completed_demo'] = 'Prueba completada';
$string['created'] = 'Fecha';
$string['rundemo'] = 'Ejecutar prueba local';
$string['democompleted'] = 'Flujo completado. Revisa el resultado guardado.';
$string['result'] = 'Resultado de la prueba';
$string['demousage'] = 'Esta prueba: 0 tokens de proveedores · USD 0,00 · sin proveedor de IA.';
$string['history'] = 'Historial de la solicitud';
$string['event'] = 'Evento';
$string['event_created'] = 'Solicitud guardada';
$string['event_demo_completed'] = 'Resultado de la prueba local guardado';
$string['listnotice'] = 'Tus 50 solicitudes más recientes.';
$string['norequests'] = 'Todavía no tienes solicitudes.';
$string['backtolist'] = 'Volver a las solicitudes';
$string['configurationmissing'] = 'Configura la dirección del backend y las credenciales internas del servicio en el servidor.';
$string['requestfailed'] = 'ADAPTIA no pudo procesar la solicitud (HTTP {$a}). Reintenta desde este formulario; la misma solicitud no se creará dos veces.';
$string['privacy:metadata:backend'] = 'El backend de ADAPTIA guarda las solicitudes de integración, sus resultados e historial. Ningún proveedor de IA recibe estos datos de demostración.';
$string['privacy:metadata:actor'] = 'Identificador seudónimo del solicitante, calculado en el servidor Moodle.';
$string['privacy:metadata:request'] = 'Línea de trabajo, tema y objetivo escritos en el formulario de solicitud.';

$string['scenario'] = 'Caso JSON de prueba';

$string['targetcourse'] = 'Curso de destino';

$string['choosecourse'] = 'Selecciona un curso distinto de la portada.';

$string['defaultobjective'] = 'Revisar y publicar un recurso educativo de prueba devuelto por los servicios simulados de las tesis.';

$string['createcourse'] = '¿Todavía no tienes un curso? Créalo en Moodle.';

$string['state_completed_mock'] = 'Contenido mock recibido';

$string['runmock'] = 'Ejecutar servicios mock';

$string['decision'] = 'Decisión de adaptación';

$string['difficulty'] = 'Dificultad devuelta por el motor: {$a}';

$string['preview'] = 'Vista previa del contenido';

$string['servicetrace'] = 'Llamadas a web services';

$string['service'] = 'Servicio';

$string['jsontrace'] = 'Ver JSON enviados y recibidos';

$string['failedtrace'] = 'Ver traza del servicio fallido';

$string['publishmock'] = 'Publicar recurso MOCK en el curso';

$string['viewpublished'] = 'Abrir recurso publicado';

$string['published'] = 'La Página MOCK ya está disponible en el curso.';

$string['publishedmocknotice'] = 'Recurso MOCK para pruebas de integración. El contenido es un caso de prueba fijo, no ha sido generado por un modelo de IA en vivo.';

$string['cannotpublish'] = 'Esta solicitud no puede publicarse en este curso.';

$string['invalidrequest'] = 'La solicitud no corresponde a este usuario y curso.';

$string['requestbusy'] = 'Otra operación está procesando esta solicitud. Vuelve a intentarlo.';

$string['event_mock_completed'] = 'Respuestas de web services mock guardadas';

$string['event_mock_failed'] = 'Falló un servicio mock; puedes reintentar';

$string['privacy:metadata:local'] = 'Vincula una solicitud del docente con su curso y recurso publicado.';

$string['privacy:metadata:userid'] = 'Usuario de Moodle que creó la solicitud.';

$string['privacy:metadata:requestid'] = 'Identificador de la solicitud en el backend.';

$string['privacy:metadata:courseid'] = 'Curso de Moodle asociado a la solicitud.';

$string['privacy:metadata:cmid'] = 'Recurso Página publicado, cuando corresponde.';

$string['privacy:metadata:timecreated'] = 'Fecha de creación de la asociación.';

$string['adaptia_core:generatetutoria'] = 'Solicitar contenido a TutorIA en este curso';
$string['privacy:metadata:timemodified'] = 'Fecha de la última actualización de la solicitud.';
$string['privacy:metadata:tutoria'] = 'Solicitudes propias del docente a TutorIA, entrada inmutable, resultado del servicio y estado.';
$string['privacy:metadata:tutoriaexternal'] = 'La pasarela ADAPTIA envía la solicitud educativa y el perfil ficticio a TutorIA, que puede utilizar el proveedor de IA configurado.';
$string['privacy:metadata:tutoriainput'] = 'Tema, objetivo, dificultad solicitada y perfil ficticio introducidos por el docente.';
$string['privacy:metadata:tutoriaresult'] = 'Respuesta del servicio, contenido e identificadores de correlación, sin credenciales.';
$string['privacy:metadata:tutoriastatus'] = 'Estado de la solicitud, estado HTTP del servicio y código de error controlado.';
$string['privacy:metadata:usermodified'] = 'Usuario que actualizó la solicitud por última vez.';
$string['tutoriaauditory'] = 'Guion para leer en voz alta';
$string['tutoriaconcepts'] = 'Conceptos';
$string['tutoriaconcepts_help'] = 'Escribe un concepto por línea, hasta 20 conceptos de 160 caracteres cada uno.';
$string['tutoriacontext'] = 'Objetivo de aprendizaje y contexto';
$string['tutoriadifficulty'] = 'Dificultad solicitada (1–10)';
$string['tutoriaerrorai_service_unavailable'] = 'La generación está deshabilitada o no está disponible. Tu solicitud se guardó; no se reintentó automáticamente.';
$string['tutoriaerrorauthentication_failed'] = 'La conexión del servicio requiere revisión del administrador. Tu solicitud se guardó.';
$string['tutoriaerrorconnection_error'] = 'No se pudo confirmar la respuesta del servicio. Tu solicitud se guardó y no se reintentó automáticamente.';
$string['tutoriaerrorinternal_server_error'] = 'El servicio no pudo completar esta solicitud. Tu solicitud se guardó.';
$string['tutoriaerrorinvalid_request'] = 'El servicio rechazó la solicitud. Revisa sus campos antes de crear otra.';
$string['tutoriaerrorinvalid_student_profile'] = 'Revisa el perfil ficticio antes de crear otra solicitud.';
$string['tutoriaerrorrate_limit_exceeded'] = 'Se alcanzó el cupo o el tiempo de espera entre generaciones. Tu solicitud se guardó.';
$string['tutoriagaps'] = 'Conceptos que se deben reforzar';
$string['tutoriahistory'] = 'Mis últimas 20 solicitudes en este curso';
$string['tutoriainputtoolarge'] = 'La solicitud supera el tamaño permitido. Reduce el contexto o los conceptos.';
$string['tutoriainvalidconcepts'] = 'Escribe hasta 20 conceptos, uno por línea y de hasta 160 caracteres cada uno.';
$string['tutoriainvaliddifficulty'] = 'Selecciona un número entero entre 1 y 10.';
$string['tutoriaknown_concepts'] = 'Conceptos conocidos';
$string['tutoriamodality'] = 'Presentación del texto';
$string['tutoriamodel'] = 'Modelo';
$string['tutorianew'] = 'Crear otra solicitud';
$string['tutorianoitems'] = 'Todavía no hay solicitudes de TutorIA en este curso.';
$string['tutorianotice'] = 'Solicita texto educativo con un perfil ficticio. Usa datos sin información personal. La generación utiliza el cupo habilitado por el administrador; el contenido no se publica automáticamente.';
$string['tutoriapending'] = 'La solicitud no ha terminado. Recargar esta página no la envía otra vez. Si el proceso se interrumpió, consulta al administrador con el identificador de solicitud.';
$string['tutoriaprofile'] = 'Detalles del perfil ficticio (opcionales)';
$string['tutoriarequeststate'] = 'Estado de la solicitud:';
$string['tutoriareviewnotice'] = 'Revisa el contenido antes de utilizarlo. Esta solicitud genera texto sin recuperar documentos del curso.';
$string['tutoriastatusfailed'] = 'Generación no completada';
$string['tutoriastatuspending'] = 'En proceso';
$string['tutoriastatussucceeded'] = 'Contenido recibido';
$string['tutoriasubmit'] = 'Solicitar contenido';
$string['tutoriatextual'] = 'Texto explicativo';
$string['tutoriatitle'] = 'TutorIA · Contenido';
$string['tutoriatokens'] = 'Tokens informados';
$string['tutoriaunknownusage'] = 'No informado';
$string['tutoriavisual'] = 'Esquemas de texto y descripciones visuales';
