"""Pasarela interna de Moodle para demostraciones y servicios ADAPTIA reales."""
import hmac
import os
import re
from uuid import UUID, uuid4

import psycopg
from psycopg.rows import dict_row
from psycopg.types.json import Jsonb
from flask import Flask, jsonify, request
from werkzeug.exceptions import HTTPException
from mock_contracts import scenarios
from pipeline import MockServiceError, run_mock
from down_api import register_down
from tutoria_client import register_tutoria

WORKSTREAMS = {'integracion', 'doctorado', 'tutoria', 'mision_down'}


def connection(config):
    return psycopg.connect(host=config['PGHOST'], dbname=config['PGDATABASE'],
                          user=config['PGUSER'], password=config['PGPASSWORD'],
                          connect_timeout=3, row_factory=dict_row)


def create_app(overrides=None):
    app = Flask(__name__)
    app.config.update({key: os.environ.get(key, '') for key in
                       ('PGHOST', 'PGDATABASE', 'PGUSER', 'PGPASSWORD', 'ADAPTIA_SERVICE_TOKEN',
                        'ADAPTIA_MODE', 'ADAPTIA_MOCK_URL', 'ADAPTIA_DOWN_URL', 'ADAPTIA_DOWN_TOKEN',
                        'ADAPTIA_TUTORIA_URL', 'ADAPTIA_TUTORIA_TOKEN', 'ADAPTIA_TUTORIA_TIMEOUT')})
    app.config.update(MAX_CONTENT_LENGTH=16384)
    if overrides:
        app.config.update(overrides)
    app.config['ADAPTIA_DOWN_TOKEN'] = app.config.get('ADAPTIA_DOWN_TOKEN') or app.config['ADAPTIA_SERVICE_TOKEN']
    if len(app.config['ADAPTIA_SERVICE_TOKEN']) < 32:
        raise RuntimeError('Configure ADAPTIA_SERVICE_TOKEN (at least 32 characters).')

    def problem(status, detail):
        titles = {400: 'Bad Request', 401: 'Unauthorized', 404: 'Not Found',
                  409: 'Conflict', 502: 'Bad Gateway', 503: 'Service Unavailable', 501: 'Not Implemented'}
        return jsonify(type='about:blank', title=titles.get(status, 'Request error'),
                       status=status, detail=detail), status, {'Content-Type': 'application/problem+json'}

    @app.errorhandler(HTTPException)
    def http_error(error):
        return problem(error.code, error.description)

    @app.errorhandler(psycopg.Error)
    def database_error(error):
        app.logger.error('Database operation failed (%s)', type(error).__name__)
        return problem(503, 'El registro de solicitudes no está disponible. Reintenta más tarde.')

    @app.before_request
    def authenticate():
        if not request.path.startswith('/api/'):
            return None
        expected = 'Bearer ' + app.config['ADAPTIA_SERVICE_TOKEN']
        if not hmac.compare_digest(request.headers.get('Authorization', '').encode('utf-8'), expected.encode('utf-8')):
            return problem(401, 'Autenticación de servicio requerida.')
        if not re.fullmatch(r'[a-f0-9]{64}', request.headers.get('X-ADAPTIA-Actor', '')):
            return problem(400, 'Identificador seudónimo de solicitante requerido.')

    @app.after_request
    def private_response(response):
        response.headers['Cache-Control'] = 'no-store'
        return response

    @app.get('/health')
    def health():
        with connection(app.config) as db:
            db.execute('SELECT 1 FROM integration_requests LIMIT 1')
        return jsonify(status='ok', service='ADAPTIA', mode=app.config['ADAPTIA_MODE'] or 'demo', version='0.6.0',
                       down='configured' if app.config.get('ADAPTIA_DOWN_URL') else 'not_configured',
                       tutoria='configured' if app.config.get('ADAPTIA_TUTORIA_URL')
                       and app.config.get('ADAPTIA_TUTORIA_TOKEN') else 'not_configured')

    @app.get('/api/v1/mock-scenarios')
    def mock_scenarios():
        return jsonify(data=[{key: case[key] for key in ('id', 'title', 'topic', 'objective', 'workstream', 'source')}
                             for case in scenarios().values()])

    def actor():
        return request.headers['X-ADAPTIA-Actor']

    def valid_course_ref(value):
        return isinstance(value, str) and re.fullmatch(r'[a-f0-9]{64}', value) is not None

    @app.before_request
    def validate_course_scope():
        if 'course_ref' in request.args and not valid_course_ref(request.args['course_ref']):
            return problem(400, 'Identificador seudónimo de curso no válido.')

    def serialise(row):
        result = dict(row)
        result.pop('actor', None)
        result.pop('idempotency_key', None)
        result['id'] = str(result['id'])
        for key in ('created_at', 'updated_at'):
            result[key] = result[key].isoformat()
        if result.get('scenario_id') in scenarios():
            result['scenario_title'] = scenarios()[result['scenario_id']]['title']
        return result

    def request_uuid(value):
        try:
            return UUID(str(value))
        except (ValueError, TypeError, AttributeError):
            return None

    @app.route('/api/v1/requests', methods=['GET', 'POST'])
    def requests():
        if request.method == 'GET':
            with connection(app.config) as db:
                scope = request.args.get('course_ref')
                rows = db.execute('SELECT * FROM integration_requests WHERE actor=%s '
                                  'AND (%s::text IS NULL OR course_ref=%s) '
                                  'ORDER BY created_at DESC, id DESC LIMIT 50', (actor(), scope, scope)).fetchall()
            return jsonify(data=[serialise(row) for row in rows], meta={'limit': 50, 'mode': 'demo'})
        payload = request.get_json()
        required = {'workstream', 'topic', 'objective'}
        if not isinstance(payload, dict) or not required <= set(payload) or set(payload) - required - {'course_ref', 'scenario_id'}:
            return problem(400, 'Se requieren workstream, topic y objective; course_ref y scenario_id son opcionales.')
        if 'scenario_id' in payload:
            cases = scenarios()
            if not isinstance(payload['scenario_id'], str) or payload['scenario_id'] not in cases:
                return problem(400, 'Caso JSON mock no válido.')
            case = cases[payload['scenario_id']]
            if payload['topic'] != case['topic'] or payload['workstream'] != case['workstream']:
                return problem(400, 'Tema y línea de trabajo deben corresponder al caso JSON.')
        payload.setdefault('scenario_id', None)
        if 'course_ref' in payload and not valid_course_ref(payload['course_ref']):
            return problem(400, 'Identificador seudónimo de curso no válido.')
        payload.setdefault('course_ref', None)
        if 'course_ref' in request.args and request.args['course_ref'] != payload['course_ref']:
            return problem(400, 'El curso de la solicitud no coincide con el contexto.')
        if not isinstance(payload['workstream'], str) or payload['workstream'] not in WORKSTREAMS:
            return problem(400, 'Línea de trabajo no válida.')
        for field, maximum in [('topic', 160), ('objective', 2000)]:
            value = payload[field]
            if not isinstance(value, str) or not 3 <= len(value.strip()) <= maximum:
                return problem(400, f'{field}: entre 3 y {maximum} caracteres.')
            payload[field] = value.strip()
        key = request_uuid(request.headers.get('Idempotency-Key'))
        if key is None:
            return problem(400, 'Idempotency-Key debe ser un UUID.')
        with connection(app.config) as db:
            row = db.execute('INSERT INTO integration_requests '
                             '(id, actor, idempotency_key, workstream, topic, objective, course_ref, scenario_id) '
                             'VALUES (%s,%s,%s,%s,%s,%s,%s,%s) ON CONFLICT (actor,idempotency_key) '
                             'DO NOTHING RETURNING *',
                             (uuid4(), actor(), key, payload['workstream'], payload['topic'],
                              payload['objective'], payload['course_ref'], payload['scenario_id'])).fetchone()
            created = row is not None
            if not created:
                row = db.execute('SELECT * FROM integration_requests WHERE actor=%s '
                                 'AND idempotency_key=%s', (actor(), key)).fetchone()
                if any(row[field] != payload[field] for field in payload):
                    return problem(409, 'Esta clave ya corresponde a otra solicitud.')
            else:
                db.execute('INSERT INTO integration_events (request_id,event) VALUES (%s,%s)',
                           (row['id'], 'created'))
        return jsonify(data=serialise(row)), 201 if created else 200

    @app.route('/api/v1/requests/<identifier>', methods=['GET'])
    @app.route('/api/v1/requests/<identifier>/run-demo', methods=['POST'])
    @app.route('/api/v1/requests/<identifier>/run-mock', methods=['POST'])
    def detail(identifier):
        identifier = request_uuid(identifier)
        if identifier is None:
            return problem(404, 'Solicitud no encontrada.')
        with connection(app.config) as db:
            row = db.execute('SELECT * FROM integration_requests WHERE id=%s AND actor=%s FOR UPDATE',
                             (identifier, actor())).fetchone()
            scope = request.args.get('course_ref')
            if row is None or (scope is not None and row['course_ref'] != scope):
                return problem(404, 'Solicitud no encontrada.')
            mock = request.path.endswith('/run-mock')
            if request.method == 'POST' and mock and not row['scenario_id']:
                return problem(409, 'La solicitud original no tiene un caso JSON mock. Crea una solicitud nueva.')
            if request.method == 'POST' and not mock and row['scenario_id']:
                return problem(409, 'Esta solicitud requiere ejecutar los servicios mock.')
            if request.method == 'POST' and mock and row['status'] == 'pending':
                try:
                    result = run_mock(app.config, row)
                except MockServiceError as error:
                    db.execute('UPDATE integration_requests SET last_error=%s, updated_at=clock_timestamp() WHERE id=%s',
                               (Jsonb({'message': str(error), 'trace': error.trace}), identifier))
                    db.execute('INSERT INTO integration_events(request_id,event) VALUES (%s,%s) ON CONFLICT DO NOTHING',
                               (identifier, 'mock_failed'))
                    return problem(502, str(error))
                row = db.execute("UPDATE integration_requests SET status='completed_mock', result=%s, last_error=NULL, "
                                 'updated_at=clock_timestamp() WHERE id=%s RETURNING *',
                                 (Jsonb(result), identifier)).fetchone()
                db.execute('INSERT INTO integration_events(request_id,event) VALUES (%s,%s)',
                           (identifier, 'mock_completed'))
            elif request.method == 'POST' and row['status'] == 'pending':
                result = {
                    'mode': 'demo', 'provider': None, 'tokens': 0, 'cost_usd': '0.000000',
                    'content': (f"Prueba de integración: {row['topic']}\n\n"
                                f"Objetivo registrado: {row['objective']}\n\n"
                                'Moodle envió la solicitud y el backend guardó el resultado en PostgreSQL. '
                                'Esta respuesta es una comprobación técnica local; no es contenido generado por IA.')
                }
                row = db.execute("UPDATE integration_requests SET status='completed_demo', result=%s, "
                                 'updated_at=clock_timestamp() WHERE id=%s RETURNING *',
                                 (Jsonb(result), identifier)).fetchone()
                db.execute('INSERT INTO integration_events (request_id,event) VALUES (%s,%s)',
                           (identifier, 'demo_completed'))
            events = db.execute('SELECT event, created_at FROM integration_events '
                                'WHERE request_id=%s ORDER BY id', (identifier,)).fetchall()
        data = serialise(row)
        data['events'] = [{'event': item['event'], 'created_at': item['created_at'].isoformat()}
                          for item in events]
        return jsonify(data=data)

    register_down(app, connection, problem)
    register_tutoria(app, problem)
    return app
