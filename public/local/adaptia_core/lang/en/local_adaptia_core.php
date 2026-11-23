<?php
// SPDX-License-Identifier: GPL-3.0-or-later
$string['pluginname'] = 'ADAPTIA integration';
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
$string['backendurl'] = 'Integration backend URL';
$string['backendurl_desc'] = 'Server address reachable from Moodle. Provider API keys belong in the backend.';
$string['status'] = 'Connection status';
$string['checkconnection'] = 'Check connection';
$string['connectionok'] = 'The ADAPTIA backend is responding.';
$string['connectionfailed'] = 'The backend did not return a valid health response. Check its address and availability.';
$string['introduction'] = 'Choose a course and a JSON test case, run the mock thesis services, review their responses and publish a MOCK Page resource in Moodle.';
$string['nocost'] = 'This check does not call paid AI APIs.';
$string['requests'] = 'ADAPTIA requests';
$string['demomode'] = 'MOCK mode: Motor, TutorIA and Misión Down return recorded test JSON over HTTP. No paid AI APIs are called.';
$string['datanotice'] = 'Use fictional data in the objective. The services receive the selected synthetic test profile; requests are associated with your Moodle course.';
$string['adaptia_core:managerequests'] = 'Manage own ADAPTIA requests in the course';
$string['courserequests'] = 'Course: {$a}';
$string['courselistnotice'] = 'Your 50 most recent requests in this course. Other teachers cannot see your requests.';
$string['backtocourse'] = 'Back to course';
$string['localenvironmentonly'] = 'This command is only available in the local development environment.';
$string['privacy:metadata:course'] = 'A pseudonymous identifier of the course associated with the request, derived on the Moodle server.';
$string['workstream'] = 'Workstream';
$string['workstream_integracion'] = 'Integration tests';
$string['workstream_doctorado'] = 'Doctoral research';
$string['workstream_tutoria'] = 'TutorIA';
$string['workstream_mision_down'] = 'Misión Down';
$string['topic'] = 'Topic';
$string['objective'] = 'Objective';
$string['createrequest'] = 'Create request';
$string['newrequest'] = 'Create mock content';
$string['invalidlength'] = 'Enter between 3 and {$a} characters.';
$string['requestcreated'] = 'Request saved in ADAPTIA.';
$string['requestid'] = 'Request ID';
$string['requeststatus'] = 'Status';
$string['state_pending'] = 'Ready to run';
$string['state_completed_demo'] = 'Demo completed';
$string['created'] = 'Date';
$string['rundemo'] = 'Run local demo';
$string['democompleted'] = 'Workflow completed. Review the saved result.';
$string['result'] = 'Demo result';
$string['demousage'] = 'This test: 0 provider tokens · USD 0.00 · no AI provider used.';
$string['history'] = 'Request history';
$string['event'] = 'Event';
$string['event_created'] = 'Request saved';
$string['event_demo_completed'] = 'Local demo result saved';
$string['listnotice'] = 'Your 50 most recent requests.';
$string['norequests'] = 'You have no requests yet.';
$string['backtolist'] = 'Back to requests';
$string['configurationmissing'] = 'Configure the backend address and internal service credentials on the server.';
$string['requestfailed'] = 'ADAPTIA could not process the request (HTTP {$a}). Retry using this form; the same request will not be created twice.';
$string['privacy:metadata:backend'] = 'The ADAPTIA backend stores integration requests, results and history. No AI provider receives this demo data.';
$string['privacy:metadata:actor'] = 'A pseudonymous requester identifier derived on the Moodle server.';
$string['privacy:metadata:request'] = 'The selected workstream, topic and objective entered in the request form.';

$string['scenario'] = 'JSON test case';

$string['targetcourse'] = 'Target course';

$string['choosecourse'] = 'Select a course other than the site home.';

$string['defaultobjective'] = 'Review and publish educational test content returned by the mock thesis services.';

$string['createcourse'] = 'No course yet? Create one using Moodle.';

$string['state_completed_mock'] = 'Mock content received';

$string['runmock'] = 'Run mock services';

$string['decision'] = 'Adaptation decision';

$string['difficulty'] = 'Difficulty returned by the motor: {$a}';

$string['preview'] = 'Content preview';

$string['servicetrace'] = 'Web service calls';

$string['service'] = 'Service';

$string['jsontrace'] = 'View JSON requests and responses';

$string['failedtrace'] = 'View failed service trace';

$string['publishmock'] = 'Publish MOCK resource in course';

$string['viewpublished'] = 'Open published resource';

$string['published'] = 'The MOCK Page resource is now available in the course.';

$string['publishedmocknotice'] = 'MOCK resource for integration testing. Content is a fixed test fixture, not generated by a live AI model.';

$string['cannotpublish'] = 'This request cannot be published in this course.';

$string['invalidrequest'] = 'The request is not valid for this user and course.';

$string['requestbusy'] = 'Another operation is processing this request. Please retry.';

$string['event_mock_completed'] = 'Mock web service responses saved';

$string['event_mock_failed'] = 'Mock service failed; retry is available';

$string['privacy:metadata:local'] = 'Associates a teacher request with its course and published resource.';

$string['privacy:metadata:userid'] = 'The Moodle user who created the request.';

$string['privacy:metadata:requestid'] = 'The backend request identifier.';

$string['privacy:metadata:courseid'] = 'The Moodle course associated with the request.';

$string['privacy:metadata:cmid'] = 'The published Page resource, if any.';

$string['privacy:metadata:timecreated'] = 'When the association was created.';

$string['adaptia_core:generatetutoria'] = 'Request content from TutorIA in this course';
$string['privacy:metadata:timemodified'] = 'Time the submission was last updated.';
$string['privacy:metadata:tutoria'] = 'Teacher-owned TutorIA submissions, immutable input, service result and status.';
$string['privacy:metadata:tutoriaexternal'] = 'The ADAPTIA gateway sends the educational request and fictional profile to TutorIA, which can use the configured AI provider.';
$string['privacy:metadata:tutoriainput'] = 'Topic, objective, requested difficulty and fictional profile supplied by the teacher.';
$string['privacy:metadata:tutoriaresult'] = 'Service response, content and correlation identifiers, without service credentials.';
$string['privacy:metadata:tutoriastatus'] = 'Submission status, service HTTP status and controlled error code.';
$string['privacy:metadata:usermodified'] = 'User who last updated the submission.';
$string['tutoriaauditory'] = 'Read-aloud script';
$string['tutoriaconcepts'] = 'Concepts';
$string['tutoriaconcepts_help'] = 'Enter one concept per line, up to 20 concepts of 160 characters each.';
$string['tutoriacontext'] = 'Learning objective and context';
$string['tutoriadifficulty'] = 'Requested difficulty (1–10)';
$string['tutoriaerrorai_service_unavailable'] = 'Generation is disabled or unavailable. Your request is saved; no automatic retry was made.';
$string['tutoriaerrorauthentication_failed'] = 'The service connection needs administrator attention. Your request is saved.';
$string['tutoriaerrorconnection_error'] = 'The service response could not be confirmed. Your request is saved and was not automatically retried.';
$string['tutoriaerrorinternal_server_error'] = 'The service could not complete this request. Your request is saved.';
$string['tutoriaerrorinvalid_request'] = 'The service rejected this request. Review its fields before creating another one.';
$string['tutoriaerrorinvalid_student_profile'] = 'Review the fictional profile before creating another request.';
$string['tutoriaerrorrate_limit_exceeded'] = 'The generation allowance or waiting limit has been reached. Your request is saved.';
$string['tutoriagaps'] = 'Concepts to reinforce';
$string['tutoriahistory'] = 'My last 20 requests in this course';
$string['tutoriainputtoolarge'] = 'The request exceeds the allowed size. Shorten the context or concepts.';
$string['tutoriainvalidconcepts'] = 'Enter up to 20 concepts, one per line, with a maximum of 160 characters each.';
$string['tutoriainvaliddifficulty'] = 'Choose an integer from 1 to 10.';
$string['tutoriaknown_concepts'] = 'Known concepts';
$string['tutoriamodality'] = 'Text presentation';
$string['tutoriamodel'] = 'Model';
$string['tutorianew'] = 'Create another request';
$string['tutorianoitems'] = 'No TutorIA requests in this course yet.';
$string['tutorianotice'] = 'Request educational text with a fictional profile. Use no personal data. Generation uses the allowance enabled by the administrator; content is not published automatically.';
$string['tutoriapending'] = 'This request has not finished. Reloading this page does not submit it again. If processing was interrupted, contact the administrator with the request identifier.';
$string['tutoriaprofile'] = 'Fictional profile details (optional)';
$string['tutoriarequeststate'] = 'Request status:';
$string['tutoriareviewnotice'] = 'Review the content before using it. This request generates text without retrieving course documents.';
$string['tutoriastatusfailed'] = 'Generation not completed';
$string['tutoriastatuspending'] = 'Processing';
$string['tutoriastatussucceeded'] = 'Content received';
$string['tutoriasubmit'] = 'Request content';
$string['tutoriatextual'] = 'Explanatory text';
$string['tutoriatitle'] = 'TutorIA · Content';
$string['tutoriatokens'] = 'Reported tokens';
$string['tutoriaunknownusage'] = 'Not reported';
$string['tutoriavisual'] = 'Text diagrams and visual descriptions';
