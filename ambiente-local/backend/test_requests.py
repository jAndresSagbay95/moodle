"""Contract and concurrency tests against a dedicated PostgreSQL database."""
from concurrent.futures import ThreadPoolExecutor
from copy import deepcopy
import io
import json
import os
from pathlib import Path
import unittest
from unittest.mock import patch
from uuid import uuid4

from app import connection, create_app
from mock_contracts import scenarios


class RequestTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        if os.environ.get('PGDATABASE') != 'adaptia_test' or os.environ.get('PGUSER') != 'adaptia_test':
            raise RuntimeError('Tests require the isolated adaptia_test database and role.')
        cls.app = create_app({'TESTING': True})
        with connection(cls.app.config) as db:
            db.execute(Path(__file__).with_name('schema.sql').read_text())

    def setUp(self):
        self.client = self.app.test_client()
        self.actor = uuid4().hex * 2
        self.headers = {'Authorization': 'Bearer ' + self.app.config['ADAPTIA_SERVICE_TOKEN'],
                        'X-ADAPTIA-Actor': self.actor, 'Idempotency-Key': str(uuid4())}
        self.payload = {'workstream': 'integracion', 'topic': 'Prueba de Moodle',
                        'objective': 'Comprobar persistencia y trazabilidad.'}

    def tearDown(self):
        with connection(self.app.config) as db:
            db.execute('DELETE FROM integration_requests WHERE actor=%s', (self.actor,))

    def create(self, client=None, **changes):
        return (client or self.client).post('/api/v1/requests', headers=self.headers,
                                           json={**self.payload, **changes})

    def test_auth_and_no_secret_exposure(self):
        self.assertEqual(self.client.get('/api/v1/requests').status_code, 401)
        self.assertEqual(self.client.get('/api/v1/requests', headers={'Authorization': 'Bearer inválido'}).status_code, 401)
        self.assertEqual(self.client.get('/api/v1/requests', headers={
            'Authorization': self.headers['Authorization']}).status_code, 400)
        health = self.client.get('/health')
        self.assertEqual(health.status_code, 200)
        self.assertNotIn('password', health.get_data(as_text=True))
        self.assertEqual(health.headers['Cache-Control'], 'no-store')

    def test_validation(self):
        for changes in ({'topic': ''}, {'topic': 'x' * 161}, {'objective': 'x' * 2001},
                        {'workstream': 'unknown'}, {'workstream': []}, {'topic': 1},
                        {'unexpected': True}):
            self.assertEqual(self.create(**changes).status_code, 400, changes)
        self.assertEqual(self.client.post('/api/v1/requests', headers=self.headers,
                                         data='{', content_type='application/json').status_code, 400)
        self.assertEqual(self.client.post('/api/v1/requests', headers=self.headers,
                                         json=[]).status_code, 400)
        self.headers['Idempotency-Key'] = 'invalid'
        self.assertEqual(self.create().status_code, 400)

    def test_replay_conflict_and_persistence(self):
        first = self.create()
        self.assertEqual(first.status_code, 201)
        identifier = first.json['data']['id']
        replay = self.create()
        self.assertEqual(replay.status_code, 200)
        self.assertEqual(replay.json['data']['id'], identifier)
        self.assertEqual(self.create(topic='Otra solicitud').status_code, 409)
        other_worker = create_app({'TESTING': True}).test_client()
        saved = other_worker.get('/api/v1/requests/' + identifier, headers=self.headers)
        self.assertEqual(saved.json['data']['status'], 'pending')
        self.assertEqual(len(saved.json['data']['events']), 1)
        self.assertNotIn('actor', saved.json['data'])

    def test_actor_isolation(self):
        identifier = self.create().json['data']['id']
        other = {**self.headers, 'X-ADAPTIA-Actor': 'f' * 64}
        self.assertEqual(self.client.get('/api/v1/requests', headers=other).json['data'], [])
        self.assertEqual(self.client.get('/api/v1/requests/' + identifier, headers=other).status_code, 404)
        self.assertEqual(self.client.post('/api/v1/requests/' + identifier + '/run-demo',
                                         headers=other).status_code, 404)

    def test_concurrent_creation_is_idempotent(self):
        def submit(_):
            with self.app.test_client() as client:
                response = self.create(client)
                return response.status_code, response.json['data']['id']
        with ThreadPoolExecutor(max_workers=4) as executor:
            results = list(executor.map(submit, range(4)))
        self.assertEqual(sorted(code for code, _ in results), [200, 200, 200, 201])
        self.assertEqual(len({identifier for _, identifier in results}), 1)
        self.assertEqual(len(self.client.get('/api/v1/requests', headers=self.headers).json['data']), 1)

    def test_demo_retries_and_history(self):
        identifier = self.create().json['data']['id']
        path = '/api/v1/requests/' + identifier + '/run-demo'
        def execute(_):
            with self.app.test_client() as client:
                return client.post(path, headers=self.headers).json['data']
        with ThreadPoolExecutor(max_workers=4) as executor:
            results = list(executor.map(execute, range(4)))
        self.assertTrue(all(result == results[0] for result in results))
        result = results[0]
        self.assertEqual(result['status'], 'completed_demo')
        self.assertEqual([event['event'] for event in result['events']], ['created', 'demo_completed'])
        self.assertEqual(result['result']['cost_usd'], '0.000000')
        self.assertEqual(result['result']['tokens'], 0)
        self.assertIsNone(result['result']['provider'])

    def test_unconfigured_provider_is_explicit(self):
        with patch.dict(self.app.config, {'ADAPTIA_TUTORIA_URL': ''}):
            response = self.client.post('/api/v1/tutoria/generate-content',
                                        headers={**self.headers, 'X-Request-ID': str(uuid4())}, json={})
        self.assertEqual(response.status_code, 503)
        self.assertEqual(response.json['error']['code'], 'AI_SERVICE_UNAVAILABLE')

    def test_course_scope_isolation(self):
        course_a, course_b = uuid4().hex * 2, uuid4().hex * 2
        global_id = self.create().json['data']['id']
        self.headers['Idempotency-Key'] = str(uuid4())
        identifier = self.create(course_ref=course_a).json['data']['id']
        self.assertEqual(self.create(course_ref=course_b).status_code, 409)
        listing = self.client.get('/api/v1/requests?course_ref=' + course_a, headers=self.headers)
        self.assertEqual([item['id'] for item in listing.json['data']], [identifier])
        self.assertEqual(self.client.get('/api/v1/requests?course_ref=' + course_b,
                                         headers=self.headers).json['data'], [])
        for item_id, course in [(identifier, course_b), (global_id, course_a)]:
            path = '/api/v1/requests/' + item_id
            self.assertEqual(self.client.get(path + '?course_ref=' + course, headers=self.headers).status_code, 404)
            self.assertEqual(self.client.post(path + '/run-demo?course_ref=' + course,
                                              headers=self.headers).status_code, 404)
        good = '/api/v1/requests/' + identifier + '/run-demo?course_ref=' + course_a
        result = self.client.post(good, headers=self.headers)
        self.assertEqual(result.status_code, 200)
        self.assertEqual(result.json['data']['status'], 'completed_demo')
        self.assertEqual(result.json['data']['course_ref'], course_a)

    def test_course_validation(self):
        for value in [None, '', '123', [], 'z' * 64, 'a' * 65]:
            self.assertEqual(self.create(course_ref=value).status_code, 400, value)
        for path in ['/api/v1/requests', '/api/v1/requests/' + str(uuid4())]:
            self.assertEqual(self.client.get(path + '?course_ref=123', headers=self.headers).status_code, 400)
        self.assertEqual(self.client.post('/api/v1/requests?course_ref=' + 'a' * 64,
                                         headers=self.headers, json=self.payload).status_code, 400)

    def test_database_failure_returns_service_unavailable(self):
        unavailable = create_app({'TESTING': True, 'PGDATABASE': 'adaptia_missing_test_database'})
        response = unavailable.test_client().get('/api/v1/requests', headers=self.headers)
        self.assertEqual(response.status_code, 503)
        self.assertEqual(response.content_type, 'application/problem+json')
        self.assertNotIn(self.app.config['PGPASSWORD'], response.get_data(as_text=True))

    def create_mock(self, scenario_id='avance'):
        case = scenarios()[scenario_id]
        self.headers['Idempotency-Key'] = str(uuid4())
        response = self.create(scenario_id=scenario_id, topic=case['topic'],
                               workstream=case['workstream'], course_ref='a' * 64)
        self.assertEqual(response.status_code, 201)
        return response.json['data']['id']

    def test_mock_http_workflows_and_idempotency(self):
        for scenario_id in scenarios():
            identifier = self.create_mock(scenario_id)
            path = '/api/v1/requests/' + identifier + '/run-mock?course_ref=' + 'a' * 64
            response = self.client.post(path, headers=self.headers)
            self.assertEqual(response.status_code, 200, response.json)
            data = response.json['data']
            self.assertEqual(data['status'], 'completed_mock')
            result = data['result']
            self.assertEqual(result['mode'], 'mock')
            self.assertEqual(result['cost_usd'], '0.000000')
            self.assertEqual(result['tokens'], 0)
            self.assertEqual(len(result['trace']), 4 if scenario_id == 'apoyo_down' else 2)
            self.assertTrue(all(step['http_status'] == 200 for step in result['trace']))
            self.assertEqual(result['trace'][-1]['service'], 'TutorIA')
            if scenario_id == 'apoyo_down':
                self.assertTrue(result['down']['config_after']['offer_instruction_repeat'])
                self.assertIn('last_seq', result['down']['model_state'])
            replay = self.client.post(path, headers=self.headers)
            self.assertEqual(replay.json, response.json)

    def test_mock_failure_is_recorded_and_retryable(self):
        identifier = self.create_mock()
        path = '/api/v1/requests/' + identifier + '/run-mock'
        broken = create_app({'TESTING': True, 'ADAPTIA_MOCK_URL': 'http://mocks:1'})
        response = broken.test_client().post(path, headers=self.headers)
        self.assertEqual(response.status_code, 502)
        saved = self.client.get('/api/v1/requests/' + identifier, headers=self.headers).json['data']
        self.assertEqual(saved['status'], 'pending')
        self.assertIsNone(saved['result'])
        self.assertEqual(saved['last_error']['trace'][0]['http_status'], 0)
        recovered = self.client.post(path, headers=self.headers)
        self.assertEqual(recovered.status_code, 200)
        self.assertIsNone(recovered.json['data']['last_error'])
        self.assertEqual([item['event'] for item in recovered.json['data']['events']],
                         ['created', 'mock_failed', 'mock_completed'])

    def test_mock_concurrent_execution_has_one_result(self):
        identifier = self.create_mock()
        path = '/api/v1/requests/' + identifier + '/run-mock'
        def execute(_):
            with self.app.test_client() as client:
                response = client.post(path, headers=self.headers)
                self.assertEqual(response.status_code, 200)
                return response.json['data']
        with ThreadPoolExecutor(max_workers=4) as executor:
            results = list(executor.map(execute, range(4)))
        self.assertTrue(all(item == results[0] for item in results))
        self.assertEqual([event['event'] for event in results[0]['events']], ['created', 'mock_completed'])

    def test_invalid_tutoria_response_remains_pending(self):
        for invalid in ({'structure': None}, {'content': None}, {'structure': {'title': 'Test', 'sections': [None]}}):
            identifier = self.create_mock()
            case = scenarios()['avance']
            motor = deepcopy(case['motor_response'])
            tutor = deepcopy(case['tutor_response'])
            tutor['data'].update(invalid)
            for body in (motor, tutor):
                body.setdefault('tracking', {})['request_id'] = identifier
            def response(body):
                stream = io.BytesIO(json.dumps(body).encode())
                stream.status = 200
                stream.headers = {'X-ADAPTIA-Mode': 'mock'}
                return stream
            with patch('pipeline.urlopen', side_effect=[response(motor), response(tutor)]):
                failed = self.client.post('/api/v1/requests/' + identifier + '/run-mock', headers=self.headers)
            self.assertEqual(failed.status_code, 502)
            saved = self.client.get('/api/v1/requests/' + identifier, headers=self.headers).json['data']
            self.assertEqual(saved['status'], 'pending')
            self.assertIsNone(saved['result'])
            self.assertIn('TutorIA', saved['last_error']['message'])

    def test_mock_mode_and_course_are_enforced(self):
        identifier = self.create_mock()
        path = '/api/v1/requests/' + identifier + '/run-mock'
        wrongcourse = self.client.post(path + '?course_ref=' + 'b' * 64, headers=self.headers)
        self.assertEqual(wrongcourse.status_code, 404)
        disabled = create_app({'TESTING': True, 'ADAPTIA_MODE': 'live'})
        self.assertEqual(disabled.test_client().post(path, headers=self.headers).status_code, 502)
        self.assertEqual(self.client.post('/api/v1/requests/' + identifier + '/run-demo',
                                         headers=self.headers).status_code, 409)

    def test_scenarios_and_mock_validation(self):
        response = self.client.get('/api/v1/mock-scenarios', headers=self.headers)
        self.assertEqual({item['id'] for item in response.json['data']}, set(scenarios()))
        self.assertEqual(self.create(scenario_id='unknown').status_code, 400)
        self.assertEqual(self.create(scenario_id='avance').status_code, 400)
