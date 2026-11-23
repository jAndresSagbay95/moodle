<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// CLI only: validates Moodle PHP → gateway → real TutorIA with the LLM disabled.
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
if (getenv('ADAPTIA_SMOKE_LLM_DISABLED') !== 'confirmed') {
    throw new RuntimeException('Confirmar LLM apagado antes de ejecutar esta comprobación.');
}
$USER = get_admin();
$payload = [
    'student_id' => 'stud_synthetic_smoke', 'topic' => 'Derivadas', 'difficulty' => 5,
    'preferred_modality' => 'textual', 'context' => 'Explicar la derivada como tasa de cambio.',
    'performance_history' => [], 'known_concepts' => ['Funciones'], 'gaps' => ['Derivadas'],
];
$results = [];
foreach ([['difficulty' => 5, 'status' => 503, 'code' => 'AI_SERVICE_UNAVAILABLE'],
          ['difficulty' => 11, 'status' => 422, 'code' => 'INVALID_REQUEST']] as $case) {
    $requestid = \core\uuid::generate();
    $payload['difficulty'] = $case['difficulty'];
    $reply = \local_adaptia_core\client::tutoria($payload, $requestid);
    if ($reply['http_status'] !== $case['status'] || $reply['body']['success'] !== false
            || $reply['body']['error']['code'] !== $case['code']
            || $reply['body']['tracking']['request_id'] !== $requestid
            || !preg_match('/^moodle_[a-f0-9]{64}$/D', $reply['body']['tracking']['upstream_request_id'])) {
        throw new RuntimeException('Contrato TutorIA real no verificado.');
    }
    $results[] = ['http_status' => $reply['http_status'], 'code' => $reply['body']['error']['code'],
                  'correlation' => true];
}
echo json_encode(['path' => 'Moodle PHP → ADAPTIA → TutorIA real', 'checks' => $results,
                  'provider_generation_tested' => false, 'secrets_omitted' => true],
                 JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
