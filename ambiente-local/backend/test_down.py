"""Pruebas del adaptador contra PostgreSQL aislado y la extensión real de Andrés."""
from concurrent.futures import ThreadPoolExecutor
from copy import deepcopy
import json
import os
from pathlib import Path
import unittest
from unittest.mock import patch
from uuid import uuid4

from app import connection, create_app
import down_client


class IntegracionDownTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        if os.environ.get('PGDATABASE') != 'adaptia_test' or os.environ.get('PGUSER') != 'adaptia_test':
            raise RuntimeError('Las pruebas requieren la base y el rol aislados adaptia_test.')
        cls.app = create_app({'TESTING': True})
        with connection(cls.app.config) as db:
            db.execute(Path(__file__).with_name('schema.sql').read_text())

    def setUp(self):
        self.client = self.app.test_client()
        self.actor = uuid4().hex * 2
        self.course = uuid4().hex * 2
        self.base = '/api/v1/down/sessions'
        self.scope = '?course_ref=' + self.course
        self.headers = {'Authorization': 'Bearer ' + self.app.config['ADAPTIA_SERVICE_TOKEN'],
                        'X-ADAPTIA-Actor': self.actor, 'Idempotency-Key': str(uuid4())}
        self.payload = {'student_id': 'stud_' + uuid4().hex, 'game_type': 'recognize_choose'}

    def tearDown(self):
        with connection(self.app.config) as db:
            db.execute('DELETE FROM down_sessions WHERE actor=%s', (self.actor,))

    def iniciar(self, **changes):
        r = self.client.post(self.base + self.scope, json={**self.payload, **changes}, headers=self.headers)
        self.assertEqual(r.status_code, 200, r.json)
        return r.json['data']

    def ensayo(self, seq, outcome='incorrect', **extra):
        return {'event_id': str(uuid4()), 'seq': seq, 'outcome': outcome,
                'first_action_latency_ms': 3000, 'help_requests': 0,
                'instruction_repeats': 0, 'backgrounded_ms': 0, 'paused_ms': 0, **extra}

    def enviar(self, session, event, client=None, headers=None):
        return (client or self.client).post(self.base + '/' + session['id'] + '/trials' + self.scope,
                                           json=event, headers=headers or self.headers)

    def consultar(self, session):
        return self.client.get(self.base + '/' + session['id'] + self.scope, headers=self.headers)

    def test_motor_real_tres_errores_y_traza_persistente(self):
        session = self.iniciar()
        self.assertEqual(session['mode'], 'extension')
        for seq in range(1, 4):
            r = self.enviar(session, self.ensayo(seq))
            self.assertEqual(r.status_code, 200, r.json)
        result = r.json['data']['last_decision']['data']
        self.assertEqual(result['rules_applied'], ['down_001'])
        self.assertEqual(result['config_after']['support_level'], 2)
        self.assertEqual(result['config_after']['distractors'], 2)
        other_worker = create_app({'TESTING': True}).test_client()
        saved = other_worker.get(self.base + '/' + session['id'] + self.scope, headers=self.headers).json['data']
        self.assertEqual(saved['last_seq'], 3)
        self.assertEqual(len(saved['history']), 4)
        self.assertNotIn(self.app.config['ADAPTIA_DOWN_TOKEN'], json.dumps(saved))
        self.assertNotIn('model_state', {k: v for k, v in saved.items() if k != 'last_decision' and k != 'history'})

    def test_reintentos_concurrentes_no_duplican_el_ensayo(self):
        session = self.iniciar()
        event = self.ensayo(1)
        def send(_):
            with self.app.test_client() as client:
                response = self.enviar(session, event, client)
                return response.status_code, response.json
        with ThreadPoolExecutor(max_workers=4) as pool:
            results = list(pool.map(send, range(4)))
        self.assertTrue(all(code == 200 for code, _ in results), results)
        self.assertTrue(all(body == results[0][1] for _, body in results))
        saved = self.consultar(session).json['data']
        self.assertEqual(saved['last_seq'], 1)
        self.assertEqual(len(saved['history']), 2)
        self.assertEqual(self.enviar(session, {**event, 'outcome': 'correct'}).status_code, 409)

    def test_sonia_aciertos_reducen_apoyo_antes_de_subir_dificultad(self):
        session = self.iniciar()
        for seq in range(1, 6):
            r = self.enviar(session, self.ensayo(seq, 'correct'))
            self.assertEqual(r.status_code, 200, r.json)
        config = r.json['data']['last_decision']['data']['config_after']
        self.assertEqual(config, {'level': 1, 'support_level': 0, 'distractors': 2,
                                  'max_instruction_words': 8, 'offer_instruction_repeat': False})

    def test_sonia_ayuda_frecuente_impide_progresion(self):
        session = self.iniciar()
        for seq in range(1, 6):
            r = self.enviar(session, self.ensayo(seq, 'correct', help_requests=1))
        self.assertEqual(r.status_code, 200, r.json)
        decision = r.json['data']['last_decision']['data']
        self.assertEqual(decision['outcome'], 'keep')
        self.assertEqual(decision['keep_reason'], 'no_condition_met')
        self.assertEqual(decision['config_before'], decision['config_after'])

    def test_sonia_ritmo_individual_y_pausas(self):
        for preferences, extra in [({'latency_threshold_ms': 20000}, {}), ({}, {'paused_ms': 1000}),
                                   ({}, {'backgrounded_ms': 1000})]:
            self.headers['Idempotency-Key'] = str(uuid4())
            session = self.iniciar(preferences=preferences)
            for seq in range(1, 4):
                r = self.enviar(session, self.ensayo(seq, 'correct', first_action_latency_ms=10000, **extra))
                self.assertEqual(r.status_code, 200, r.json)
            self.assertFalse(r.json['data']['last_decision']['data']['config_after']['offer_instruction_repeat'])

    def test_ritmo_lento_ofrece_repetir_sin_marcar_error(self):
        session = self.iniciar()
        for seq in range(1, 4):
            r = self.enviar(session, self.ensayo(seq, 'correct', first_action_latency_ms=10000))
        decision = r.json['data']['last_decision']['data']
        self.assertEqual(decision['rules_applied'], ['down_002'])
        self.assertTrue(decision['config_after']['offer_instruction_repeat'])
        self.assertEqual(decision['model_state']['indicators']['consecutive_errors'], 0)

    def test_sin_respuesta_es_neutro(self):
        session = self.iniciar()
        for seq in range(1, 4):
            r = self.enviar(session, self.ensayo(seq, 'no_response', first_action_latency_ms=None))
        state = r.json['data']['last_decision']['data']['model_state']
        self.assertEqual(state['no_response_count'], 3)
        self.assertEqual(state['indicators']['consecutive_errors'], 0)

    def test_fallo_conserva_estado_y_reintenta_mismo_evento(self):
        session = self.iniciar()
        event = self.ensayo(1)
        with patch('down_client.call', side_effect=down_client.DownUnavailable('PRUEBA_TIMEOUT')):
            self.assertEqual(self.enviar(session, event).status_code, 502)
        saved = self.consultar(session).json['data']
        self.assertEqual(saved['last_seq'], 0)
        self.assertEqual(saved['pending_event'], event)
        self.assertEqual(saved['last_decision'], session['last_decision'])
        self.assertEqual(self.enviar(session, self.ensayo(1)).status_code, 409)
        retry = self.client.post(self.base + '/' + session['id'] + '/retry' + self.scope, headers=self.headers)
        self.assertEqual(retry.status_code, 200, retry.json)
        self.assertEqual(retry.json['data']['last_seq'], 1)
        self.assertIsNone(retry.json['data']['last_error'])
        self.assertEqual(len(self.consultar(session).json['data']['history']), 2)

    def test_inicio_fallido_se_recupera_sin_crear_otra_sesion(self):
        with patch('down_client.call', side_effect=down_client.DownUnavailable('PRUEBA_TIMEOUT')):
            r = self.client.post(self.base + self.scope, json=self.payload, headers=self.headers)
            self.assertEqual(r.status_code, 502)
        rows = self.client.get(self.base + self.scope, headers=self.headers).json['data']
        self.assertEqual(len(rows), 1)
        self.assertEqual(rows[0]['status'], 'starting')
        recovered = self.iniciar()
        self.assertEqual(recovered['id'], rows[0]['id'])

    def test_aislamiento_por_docente_curso_y_autenticacion(self):
        session = self.iniciar()
        path = self.base + '/' + session['id']
        self.assertEqual(self.client.get(path + self.scope).status_code, 401)
        self.assertEqual(self.client.get(path + self.scope, headers={**self.headers, 'X-ADAPTIA-Actor': 'f' * 64}).status_code, 404)
        self.assertEqual(self.client.get(path + '?course_ref=' + 'e' * 64, headers=self.headers).status_code, 404)
        self.assertEqual(self.enviar(session, self.ensayo(1), headers={**self.headers, 'X-ADAPTIA-Actor': 'f' * 64}).status_code, 404)

    def test_no_admite_diagnosticos_ni_estado_del_cliente(self):
        for extra in ({'diagnosis': 'no se admite'}, {'model_state': {}},
                      {'preferences': {'latency_threshold_ms': True}}, {'preferences': {'initial_level': 4}}):
            r = self.client.post(self.base + self.scope, json={**self.payload, **extra}, headers=self.headers)
            self.assertEqual(r.status_code, 400, r.json)
        session = self.iniciar()
        self.assertEqual(self.enviar(session, self.ensayo(2)).status_code, 409)
        self.assertEqual(self.enviar(session, self.ensayo(1, model_state={})).status_code, 400)

    def test_cierra_al_llegar_al_limite_y_permite_releer_ultimo_evento(self):
        session = self.iniciar()
        last = None
        for seq in range(1, 21):
            last = self.ensayo(seq, 'no_response', first_action_latency_ms=None)
            r = self.enviar(session, last)
            self.assertEqual(r.status_code, 200, r.json)
        self.assertEqual(r.json['data']['status'], 'completed')
        self.assertEqual(self.enviar(session, last).status_code, 200)
        self.assertEqual(self.enviar(session, self.ensayo(21)).status_code, 409)

    def test_respuesta_invalida_no_se_guarda_como_decision(self):
        session = self.iniciar()
        original = down_client.call
        def altered(*args, **kwargs):
            body, elapsed = original(*args, **kwargs)
            body = deepcopy(body)
            body['data']['model_state']['last_seq'] = 99
            return body, elapsed
        with patch('down_client.call', side_effect=altered):
            self.assertEqual(self.enviar(session, self.ensayo(1)).status_code, 502)
        saved = self.consultar(session).json['data']
        self.assertEqual(saved['last_seq'], 0)
        self.assertEqual(len(saved['history']), 1)


if __name__ == '__main__':
    unittest.main()
