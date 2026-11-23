"""Real HTTP orchestration against explicitly configured local mock services."""
from datetime import datetime, timezone
import json
from time import monotonic
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen

from mock_contracts import fixture, scenario, tutor_payload


class MockServiceError(Exception):
    def __init__(self, service, trace):
        super().__init__(f'No se pudo completar el servicio mock {service}. Puedes reintentar.')
        self.trace = trace


def run_mock(config, row):
    if config.get('ADAPTIA_MODE') != 'mock':
        raise MockServiceError('configuración (se requiere modo mock)', [])
    case = scenario(row['scenario_id'])
    base = config.get('ADAPTIA_MOCK_URL', '').rstrip('/')
    if not base.startswith(('http://', 'https://')):
        raise MockServiceError('configuración de URL', [])
    trace = []

    def call(service, path, payload=None):
        started = monotonic()
        entry = {'service': service, 'path': path, 'mode': 'mock', 'request': payload,
                 'method': 'POST' if payload is not None else 'GET'}
        trace.append(entry)
        req = Request(base + path, data=None if payload is None else json.dumps(payload).encode(), headers={
            'Authorization': 'Bearer ' + config['ADAPTIA_SERVICE_TOKEN'],
            'Content-Type': 'application/json', 'X-ADAPTIA-Scenario': row['scenario_id'],
            'X-Request-ID': str(row['id']),
        })
        try:
            with urlopen(req, timeout=3) as response:
                entry['http_status'] = response.status
                if response.headers.get('X-ADAPTIA-Mode') != 'mock':
                    raise ValueError('The service did not identify itself as a mock.')
                body = json.loads(response.read(131073))
                if body.get('success') is not True or not isinstance(body.get('data'), dict):
                    raise ValueError('Invalid response envelope.')
                if body.get('tracking', {}).get('request_id') != str(row['id']):
                    raise ValueError('Response correlation does not match the workflow.')
                entry['response'] = body
                return body['data']
        except (HTTPError, URLError, TimeoutError, OSError, ValueError, TypeError, AttributeError) as error:
            entry['http_status'] = getattr(error, 'code', entry.get('http_status', 0))
            entry['error'] = type(error).__name__
            raise MockServiceError(service, trace) from error
        finally:
            entry['elapsed_ms'] = round((monotonic() - started) * 1000)

    decision = call('Motor adaptativo', '/api/v1/motor/decide', case['motor_request'])
    if decision != case['motor_response']['data']:
        raise MockServiceError('contrato del motor', trace)
    down = None
    if case['use_down']:
        call('Misión Down · reglas', '/extensions/v1/down/rules')
        down = call('Misión Down · evaluación', '/extensions/v1/down/evaluate', fixture(case['down_request_file']))
        if not isinstance(down.get('config_after'), dict) or not isinstance(down.get('model_state'), dict):
            raise MockServiceError('contrato Misión Down', trace)
    generated = call('TutorIA', '/api/v1/tutoria/generate-content', tutor_payload(case, row['objective']))
    structure = generated.get('structure', {})
    sections = structure.get('sections') if isinstance(structure, dict) else None
    if (not isinstance(generated.get('content'), str) or not isinstance(structure, dict)
            or not isinstance(structure.get('title'), str) or not isinstance(sections, list) or not sections
            or any(not isinstance(item, dict) or not isinstance(item.get('text'), str)
                   or not isinstance(item.get('title'), str) for item in sections)):
        raise MockServiceError('estructura de TutorIA', trace)
    return {'mode': 'mock', 'provider': None, 'tokens': 0, 'cost_usd': '0.000000',
            'scenario_id': row['scenario_id'], 'scenario_title': case['title'], 'source': case['source'],
            'validation': case['validation'], 'student_profile': case['profile'],
            'decision': decision, 'down': down, 'structure': structure, 'content': generated['content'],
            'trace': trace, 'completed_at': datetime.now(timezone.utc).isoformat()}
