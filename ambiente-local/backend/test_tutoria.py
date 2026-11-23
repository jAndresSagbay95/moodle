"""Contrato del cliente real: HTTP local controlado, sin proveedor ni datos privados."""
from copy import deepcopy
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import threading
import unittest

from app import create_app
from tutoria_client import generate, upstream_id


PAYLOAD = {
    'student_id': 'stud_synthetic_123', 'topic': 'Derivadas', 'difficulty': 5,
    'preferred_modality': 'textual', 'context': 'Explicar la tasa de cambio.',
    'performance_history': [], 'known_concepts': ['Funciones'], 'gaps': ['Derivadas'],
}
SECTIONS = [
    {'type': 'explanation', 'title': 'Idea', 'text': 'La derivada mide el cambio.'},
    {'type': 'example', 'title': 'Ejemplo', 'text': 'La derivada de x² es 2x.'},
    {'type': 'exercise', 'title': 'Práctica', 'text': 'Calcula la derivada de x³.'},
]


class TutorIAClientTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        class Handler(BaseHTTPRequestHandler):
            def do_POST(self):
                body = json.loads(self.rfile.read(int(self.headers['Content-Length'])))
                self.server.calls.append({'path': self.path, 'body': body, 'headers': dict(self.headers),
                                          'raw_size': int(self.headers['Content-Length'])})
                rid, sid = self.headers['X-Request-ID'], self.headers['X-Session-ID']
                status, response, headers = self.server.respond(body, rid, sid)
                raw = response if isinstance(response, bytes) else json.dumps(response).encode()
                self.send_response(status)
                self.send_header('Content-Type', 'application/json')
                for name, value in headers.items():
                    self.send_header(name, value)
                self.end_headers()
                self.wfile.write(raw)

            def log_message(self, *_):
                pass

        cls.server = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
        cls.worker = threading.Thread(target=cls.server.serve_forever, daemon=True)
        cls.worker.start()

    @classmethod
    def tearDownClass(cls):
        cls.server.shutdown()
        cls.server.server_close()
        cls.worker.join()

    def setUp(self):
        self.server.calls = []
        self.server.respond = self.success
        self.config = {'ADAPTIA_TUTORIA_URL': 'http://127.0.0.1:' + str(self.server.server_port),
                       'ADAPTIA_TUTORIA_TOKEN': 'synthetic-tutoria-service-token-not-a-real-secret',
                       'ADAPTIA_TUTORIA_TIMEOUT': 5,
                       'ADAPTIA_SERVICE_TOKEN': 'synthetic-moodle-gateway-token-not-a-real-secret'}
        self.actor = 'a' * 64
        self.app = create_app({**self.config, 'TESTING': True})
        self.client = self.app.test_client()
        self.headers = {'Authorization': 'Bearer ' + self.config['ADAPTIA_SERVICE_TOKEN'],
                        'X-ADAPTIA-Actor': self.actor, 'X-Request-ID': 'test_request_001'}

    @staticmethod
    def success(payload, rid, sid):
        return 200, {
            'success': True,
            'data': {'content': '\n\n'.join(s['title'] + '\n' + s['text'] for s in SECTIONS),
                     'structure': {'title': 'Derivadas', 'sections': deepcopy(SECTIONS)},
                     'metadata_generation': {'model_used': 'controlled-test-model',
                                             'tokens_used': None, 'generation_time_ms': 10}},
            'metadata': {'timestamp': '2026-11-19T12:00:00Z', 'processing_time_ms': 12,
                         'api_version': 'v1.0', 'mode': 'direct', 'replayed': False},
            'tracking': {'request_id': rid, 'session_id': sid,
                         'student_id_hash': payload['student_id']},
        }, {}

    def post(self, headers=None, payload=None):
        return self.client.post('/api/v1/tutoria/generate-content',
                                headers=self.headers if headers is None else headers,
                                json=PAYLOAD if payload is None else payload)

    def test_http_success_preserves_contract_and_private_credentials(self):
        response = self.post()
        self.assertEqual(response.status_code, 200)
        self.assertTrue(response.json['success'])
        self.assertIsNone(response.json['data']['metadata_generation']['tokens_used'])
        self.assertEqual(response.json['tracking']['request_id'], 'test_request_001')
        self.assertEqual(response.headers['Cache-Control'], 'no-store')
        sent = self.server.calls[0]
        self.assertEqual(sent['path'], '/api/v1/tutoria/generate-content')
        self.assertEqual(sent['body'], PAYLOAD)
        self.assertEqual(sent['headers']['Authorization'], 'Bearer ' + self.config['ADAPTIA_TUTORIA_TOKEN'])
        self.assertNotIn('X-Adaptia-Actor', sent['headers'])
        self.assertEqual(response.json['tracking']['upstream_request_id'],
                         upstream_id(self.actor, 'test_request_001'))
        for key in ('ADAPTIA_SERVICE_TOKEN', 'ADAPTIA_TUTORIA_TOKEN'):
            self.assertNotIn(self.config[key], response.get_data(as_text=True))

    def test_request_ids_are_stable_per_actor_and_isolated_between_teachers(self):
        one = self.post().json['tracking']['upstream_request_id']
        again = self.post().json['tracking']['upstream_request_id']
        other = self.post({**self.headers, 'X-ADAPTIA-Actor': 'b' * 64}).json['tracking']['upstream_request_id']
        self.assertEqual(one, again)
        self.assertNotEqual(one, other)

    def test_unicode_request_is_transmitted_without_ascii_size_inflation(self):
        response = self.post(payload={**PAYLOAD, 'context': 'á' * 2000})
        self.assertEqual(response.status_code, 200)
        self.assertLess(self.server.calls[0]['raw_size'], 6000)
        self.assertEqual(self.server.calls[0]['body']['context'], 'á' * 2000)

    def test_gateway_auth_and_correlation_reject_before_network(self):
        for headers, status in [({}, 401), ({**self.headers, 'Authorization': 'Bearer wrong'}, 401),
                                ({**self.headers, 'X-ADAPTIA-Actor': ''}, 400),
                                ({**self.headers, 'X-Request-ID': ''}, 400),
                                ({**self.headers, 'X-Session-ID': 'bad space'}, 400)]:
            self.assertEqual(self.post(headers).status_code, status)
        self.assertEqual(self.server.calls, [])

    def test_missing_configuration_and_unsafe_url_do_not_call_network(self):
        for changes in [{'ADAPTIA_TUTORIA_URL': ''}, {'ADAPTIA_TUTORIA_TOKEN': ''},
                        {'ADAPTIA_TUTORIA_URL': 'http://user:password@localhost'},
                        {'ADAPTIA_TUTORIA_URL': self.config['ADAPTIA_TUTORIA_URL'] + '?key=x'},
                        {'ADAPTIA_TUTORIA_TIMEOUT': '999'}]:
            result, status = generate({**self.config, **changes}, PAYLOAD, self.actor, 'test_req', 'test_req')
            self.assertEqual(status, 503)
            self.assertFalse(result['success'])
        self.assertEqual(self.server.calls, [])

    def test_upstream_errors_are_sanitized_and_never_retried(self):
        for status, code in [(401, 'AUTHENTICATION_FAILED'), (409, 'INVALID_REQUEST'),
                             (422, 'INVALID_REQUEST'), (429, 'RATE_LIMIT_EXCEEDED'),
                             (503, 'AI_SERVICE_UNAVAILABLE')]:
            with self.subTest(status=status):
                def respond(body, rid, sid):
                    return status, {'success': False, 'error': {'code': code, 'message': 'SECRET-SENTINEL'},
                                    'tracking': {'request_id': rid}, 'metadata': {}}, {}
                self.server.respond = respond
                before = len(self.server.calls)
                response = self.post()
                self.assertEqual(response.status_code, status)
                self.assertEqual(response.json['error']['code'], code)
                self.assertNotIn('SECRET-SENTINEL', response.get_data(as_text=True))
                self.assertEqual(len(self.server.calls), before + 1)

    def test_invalid_mock_redirect_and_oversized_responses_fail_closed(self):
        def bad_correlation(body):
            body['tracking']['request_id'] = 'another-request'
        def bad_student(body):
            body['tracking']['student_id_hash'] = 'another-student'
        def bad_content(body):
            body['data']['content'] = 'Different unvalidated content'
        def bad_metadata(body):
            body['metadata']['replayed'] = 'false'
        def exposed_credential(body):
            body['data']['metadata_generation']['model_used'] = self.config['ADAPTIA_TUTORIA_TOKEN']
        for mutate in (bad_correlation, bad_student, bad_content, bad_metadata, exposed_credential):
            with self.subTest(mutate=mutate.__name__):
                def respond(payload, rid, sid):
                    status, body, headers = self.success(payload, rid, sid)
                    mutate(body)
                    return status, body, headers
                self.server.respond = respond
                self.assertEqual(self.post().status_code, 502)
        cases = [
            lambda p, r, s: (200, b'not-json', {}),
            lambda p, r, s: (200, b'x' * 524289, {}),
            lambda p, r, s: (302, b'', {'Location': '/capture-token'}),
            lambda p, r, s: (*self.success(p, r, s)[:2], {'X-ADAPTIA-Mode': 'mock'}),
        ]
        for respond in cases:
            self.server.respond = respond
            before = len(self.server.calls)
            self.assertEqual(self.post().status_code, 502)
            self.assertEqual(len(self.server.calls), before + 1)

    def test_transport_timeout_returns_once_without_retry(self):
        from unittest.mock import patch
        with patch('tutoria_client.build_opener') as factory:
            factory.return_value.open.side_effect = TimeoutError('SECRET-SENTINEL')
            response = self.post()
        self.assertEqual(response.status_code, 502)
        self.assertNotIn('SECRET-SENTINEL', response.get_data(as_text=True))
        self.assertEqual(factory.return_value.open.call_count, 1)

    def test_replayed_response_and_unknown_usage_are_preserved(self):
        def respond(payload, rid, sid):
            status, body, headers = self.success(payload, rid, sid)
            body['metadata']['replayed'] = True
            return status, body, headers
        self.server.respond = respond
        response = self.post()
        self.assertTrue(response.json['metadata']['replayed'])
        self.assertIsNone(response.json['data']['metadata_generation']['tokens_used'])

    def test_malformed_or_oversized_input_does_not_reach_tutoria(self):
        for data, status in [('{', 400), ('[]', 400), ('{"score": NaN}', 400), ('x' * 17000, 413)]:
            response = self.client.post('/api/v1/tutoria/generate-content', headers=self.headers,
                                        data=data, content_type='application/json')
            self.assertEqual(response.status_code, status)
        self.assertEqual(self.server.calls, [])


if __name__ == '__main__':
    unittest.main()
