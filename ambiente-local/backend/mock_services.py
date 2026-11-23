"""HTTP doubles for the documented Motor, TutorIA and Misión Down contracts."""
from copy import deepcopy
from datetime import datetime, timezone
import hmac
import os

from flask import Flask, jsonify, request
from mock_contracts import fixture, scenario


def create_app():
    app = Flask(__name__)
    app.config['MAX_CONTENT_LENGTH'] = 65536
    token = os.environ.get('ADAPTIA_SERVICE_TOKEN', '')
    if len(token) < 32:
        raise RuntimeError('Mock service token is not configured.')

    def error(status, message):
        return jsonify(success=False, error={'code': 'MOCK_INVALID_REQUEST', 'message': message}), status

    @app.before_request
    def auth():
        if request.path == '/health':
            return None
        if not hmac.compare_digest(request.headers.get('Authorization', '').encode(), ('Bearer ' + token).encode()):
            return error(401, 'Autenticación de servicio requerida.')

    @app.after_request
    def mark_mock(response):
        response.headers['X-ADAPTIA-Mode'] = 'mock'
        response.headers['Cache-Control'] = 'no-store'
        return response

    @app.get('/health')
    def health():
        return jsonify(status='ok', mode='mock', services=['motor', 'tutoria', 'mision_down'])

    def selected():
        try:
            return scenario(request.headers.get('X-ADAPTIA-Scenario', ''))
        except KeyError:
            return None

    def envelope(body):
        result = deepcopy(body)
        result.setdefault('metadata', {}).update(timestamp=datetime.now(timezone.utc).isoformat())
        result.setdefault('tracking', {})['request_id'] = request.headers.get('X-Request-ID', 'fixture')
        return jsonify(result)

    @app.post('/api/v1/motor/decide')
    def decide():
        case = selected()
        if not case or request.get_json() != case['motor_request']:
            return error(422, 'El request no corresponde al caso JSON del motor seleccionado.')
        return envelope(case['motor_response'])

    @app.post('/api/v1/tutoria/generate-content')
    def generate():
        case, body = selected(), request.get_json()
        if not case or not isinstance(body, dict):
            return error(422, 'Caso de TutorIA no válido.')
        required = {'student_id', 'topic', 'difficulty', 'preferred_modality', 'context',
                    'performance_history', 'known_concepts', 'gaps'}
        if set(body) != required or body['topic'] != case['topic'] or body['student_id'] != case['profile']['student_id']:
            return error(422, 'El request no cumple el contrato TutorIA del caso seleccionado.')
        if body['difficulty'] != case['motor_response']['data']['parameters']['new_difficulty']:
            return error(422, 'TutorIA no recibió la dificultad de la decisión del motor.')
        if not isinstance(body['context'], str) or not 3 <= len(body['context']) <= 2000:
            return error(422, 'Contexto educativo no válido.')
        return envelope(case['tutor_response'])

    @app.get('/extensions/v1/down/rules')
    def rules():
        return envelope(fixture('down-rules.response.json'))

    @app.post('/extensions/v1/down/evaluate')
    def evaluate():
        case = selected()
        if not case or not case['use_down'] or request.get_json() != fixture('down-evaluate.request.json'):
            return error(422, 'El request no corresponde al caso de test registrado de Misión Down.')
        return envelope(fixture('down-evaluate.response.json'))

    return app
