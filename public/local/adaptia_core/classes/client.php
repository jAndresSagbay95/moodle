<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_adaptia_core;
defined('MOODLE_INTERNAL') || die();

final class client {
    /** Stable course reference; raw Moodle course IDs stay inside Moodle. */
    public static function course_ref(int $courseid): string {
        global $CFG;
        $secret = (string)getenv('ADAPTIA_ACTOR_SECRET');
        if (strlen($secret) < 32) {
            throw new \moodle_exception('configurationmissing', 'local_adaptia_core');
        }
        return hash_hmac('sha256', $CFG->wwwroot . ':course:' . $courseid, $secret);
    }

    /** Call the internal service; no provider credentials are sent to the browser. */
    public static function call(string $method, string $path, ?array $payload = null, ?string $key = null): array {
        global $CFG, $USER;
        require_once($CFG->libdir . '/filelib.php');
        $url = rtrim((string)get_config('local_adaptia_core', 'backendurl'), '/');
        $token = (string)getenv('ADAPTIA_SERVICE_TOKEN');
        $secret = (string)getenv('ADAPTIA_ACTOR_SECRET');
        if (!preg_match('~^https?://~i', $url) || strlen($token) < 32 || strlen($secret) < 32) {
            throw new \moodle_exception('configurationmissing', 'local_adaptia_core');
        }
        $curl = new \curl();
        $headers = [
            'Authorization: Bearer ' . $token,
            'X-ADAPTIA-Actor: ' . hash_hmac('sha256', $CFG->wwwroot . ':' . $USER->id, $secret),
            'Accept: application/json', 'Content-Type: application/json',
        ];
        if ($key !== null) {
            $headers[] = 'Idempotency-Key: ' . $key;
        }
        $curl->setHeader($headers);
        $options = ['CURLOPT_CONNECTTIMEOUT' => 3, 'CURLOPT_TIMEOUT' => 20, 'CURLOPT_FOLLOWLOCATION' => false];
        if ($method === 'POST') {
            $result = $curl->post($url . $path, json_encode($payload ?? new \stdClass()), $options);
        } else {
            $result = $curl->get($url . $path, [], $options);
        }
        $status = (int)($curl->get_info()['http_code'] ?? 0);
        $data = json_decode((string)$result, true);
        if ($curl->get_errno() || $status < 200 || $status >= 300 || !is_array($data) || !isset($data['data'])) {
            throw new \moodle_exception('requestfailed', 'local_adaptia_core', '', $status);
        }
        return $data['data'];
    }

    /** Real TutorIA contract. Explicit call only; returns controlled success/error and correlation. */
    public static function tutoria(array $payload, string $requestid, ?string $sessionid = null): array {
        global $CFG, $USER;
        require_once($CFG->libdir . '/filelib.php');
        $url = rtrim((string)get_config('local_adaptia_core', 'backendurl'), '/');
        $token = (string)getenv('ADAPTIA_SERVICE_TOKEN');
        $secret = (string)getenv('ADAPTIA_ACTOR_SECRET');
        $sessionid = $sessionid ?? $requestid;
        if (!preg_match('~^https?://~i', $url) || strlen($token) < 32 || strlen($secret) < 32) {
            throw new \moodle_exception('configurationmissing', 'local_adaptia_core');
        }
        foreach ([$requestid, $sessionid] as $value) {
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D', $value)) {
                throw new \moodle_exception('invalidrequest', 'local_adaptia_core');
            }
        }
        $curl = new \curl();
        $curl->setHeader([
            'Authorization: Bearer ' . $token,
            'X-ADAPTIA-Actor: ' . hash_hmac('sha256', $CFG->wwwroot . ':' . $USER->id, $secret),
            'Accept: application/json', 'Content-Type: application/json',
            'X-Request-ID: ' . $requestid, 'X-Session-ID: ' . $sessionid,
        ]);
        $raw = $curl->post($url . '/api/v1/tutoria/generate-content', json_encode($payload, JSON_UNESCAPED_UNICODE), [
            'CURLOPT_CONNECTTIMEOUT' => 3, 'CURLOPT_TIMEOUT' => 85, 'CURLOPT_FOLLOWLOCATION' => false,
        ]);
        $status = (int)($curl->get_info()['http_code'] ?? 0);
        $body = json_decode((string)$raw, true);
        if ($curl->get_errno() || !is_array($body) || !isset($body['success'])
                || ($body['tracking']['request_id'] ?? null) !== $requestid
                || ($status === 200 && ($body['success'] !== true || !isset($body['data'])))
                || ($status !== 200 && ($body['success'] !== false || !isset($body['error']['code'])))) {
            throw new \moodle_exception('requestfailed', 'local_adaptia_core', '', $status);
        }
        return ['http_status' => $status, 'body' => $body];
    }

    public static function health(): bool {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $url = rtrim((string)get_config('local_adaptia_core', 'backendurl'), '/');
        if (!preg_match('~^https?://~i', $url)) {
            return false;
        }
        $curl = new \curl();
        $result = $curl->get($url . '/health', [], [
            'CURLOPT_CONNECTTIMEOUT' => 3,
            'CURLOPT_TIMEOUT' => 8,
            'CURLOPT_FOLLOWLOCATION' => false,
        ]);
        $status = $curl->get_info()['http_code'] ?? 0;
        $data = json_decode((string)$result, true);
        return !$curl->get_errno() && $status === 200 && is_array($data)
            && ($data['status'] ?? null) === 'ok';
    }
}
