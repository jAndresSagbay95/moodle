"""Llamadas HTTP acotadas a la extensión real; las credenciales quedan en el servidor."""
import hashlib
import json
import re
from http.client import HTTPException as ErrorProtocoloHTTP
from time import monotonic
from urllib.error import HTTPError, URLError
from urllib.parse import urlsplit
from urllib.request import HTTPRedirectHandler, Request, build_opener


class DownUnavailable(Exception):
    def __init__(self, code='SERVICE_UNAVAILABLE', http_status=0):
        super().__init__('Misión Down no pudo completar la evaluación. Se conserva la configuración y puedes reintentar.')
        self.code = code
        self.http_status = http_status


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def call(config, path, request_id, payload=None):
    base = config.get('ADAPTIA_DOWN_URL', '').rstrip('/')
    url = urlsplit(base)
    token = config.get('ADAPTIA_DOWN_TOKEN', '')
    if (url.scheme not in {'http', 'https'} or not url.hostname or url.username or url.password
            or url.query or url.fragment or len(token) < 32):
        raise DownUnavailable('NOT_CONFIGURED')
    started = monotonic()
    headers = {'Authorization': 'Bearer ' + token, 'Accept': 'application/json',
               'Content-Type': 'application/json', 'X-Request-Id': str(request_id),
               'X-Trace-Id': str(request_id)}
    req = Request(base + path, headers=headers,
                  data=None if payload is None else json.dumps(payload).encode('utf-8'))
    try:
        with build_opener(NoRedirect).open(req, timeout=4) as response:
            raw = response.read(262145)
            if len(raw) > 262144:
                raise ValueError('La respuesta supera el tamaño permitido.')
            body = json.loads(raw)
            if (not isinstance(body, dict) or body.get('success') is not True
                    or not isinstance(body.get('data'), dict)
                    or not isinstance(body.get('metadata'), dict)
                    or body.get('tracking', {}).get('request_id') != str(request_id)):
                raise ValueError('Respuesta inválida o sin correlación con la petición.')
            return body, round((monotonic() - started) * 1000)
    except HTTPError as error:
        # Moodle recibe un error controlado, sin cabeceras, credenciales ni HTML del proveedor.
        code = 'UPSTREAM_ERROR'
        try:
            body = json.loads(error.read(8192))
            candidate = body.get('error', {}).get('code', '')
            if isinstance(candidate, str) and re.fullmatch(r'[A-Z_]{1,64}', candidate):
                code = candidate
        except (ValueError, TypeError, AttributeError):
            pass
        raise DownUnavailable(code, error.code) from None
    except (URLError, TimeoutError, OSError, ValueError, TypeError, AttributeError, ErrorProtocoloHTTP):
        raise DownUnavailable('INVALID_RESPONSE') from None


def catalogs(config, request_id):
    health, _ = call(config, '/health', request_id)
    versions = health['data'].get('versions', {})
    if not isinstance(versions, dict) or health['data'].get('status') != 'ok' or any(
            not isinstance(versions.get(k), str) or not re.fullmatch(r'\d+\.\d+\.\d+', versions[k])
            for k in ('model', 'rules', 'params')):
        raise DownUnavailable('INVALID_VERSIONS')
    rules, _ = call(config, '/rules?version=' + versions['rules'], request_id)
    games, _ = call(config, '/games?version=' + versions['params'], request_id)
    if (rules['data'].get('catalog_version') != versions['rules']
            or games['data'].get('params_version') != versions['params']):
        raise DownUnavailable('VERSION_MISMATCH')
    game_list = games['data'].get('games')
    common = games['data'].get('common')
    if (not isinstance(game_list, list) or not game_list or not all(isinstance(g, dict) for g in game_list)
            or not isinstance(common, dict) or type(common.get('trials_per_session')) is not int
            or not 1 <= common['trials_per_session'] <= 100):
        raise DownUnavailable('INVALID_CATALOG')
    return {'versions': versions, 'rules': rules['data'], 'games': games['data']}


def evaluate(config, payload, request_id, versions, previous_state=None):
    body, elapsed_ms = call(config, '/evaluate', request_id, payload)
    data, meta = body['data'], body['metadata']
    state = data.get('model_state', {})
    expected_seq = payload.get('interaction_event', {}).get('seq', 0)
    if (not isinstance(state, dict) or not isinstance(data.get('config_after'), dict)
            or state.get('config') != data['config_after'] or type(state.get('last_seq')) is not int
            or state.get('last_seq') != expected_seq
            or state.get('game_type') != payload['game_type']
            or data.get('applies_from') != 'next_trial'
            or data.get('decision_kind') != ('trial' if expected_seq else 'initial')
            or data.get('outcome') not in {'change', 'keep'}
            or not isinstance(data.get('explanation'), str)
            or not isinstance(data.get('rules_applied'), list)
            or body.get('tracking', {}).get('session_id') != payload['session_id']):
        raise DownUnavailable('INVALID_DECISION')
    for kind in ('model', 'rules', 'params'):
        if state.get(kind + '_version') != versions[kind] or meta.get(kind + '_version') != versions[kind]:
            raise DownUnavailable('VERSION_MISMATCH')
    serialized = json.dumps({k: v for k, v in state.items() if k != 'state_hash'},
                            sort_keys=True, separators=(',', ':'), ensure_ascii=False)
    if state.get('state_hash') != 'sha256:' + hashlib.sha256(serialized.encode()).hexdigest():
        raise DownUnavailable('INVALID_STATE_HASH')
    cfg = data['config_after']
    if (set(cfg) != {'level', 'distractors', 'support_level', 'max_instruction_words', 'offer_instruction_repeat'}
            or any(type(cfg.get(k)) is not int for k in ('level', 'distractors', 'support_level', 'max_instruction_words'))
            or not 1 <= cfg['level'] <= 3 or not 0 <= cfg['support_level'] <= 3
            or not 3 <= cfg['max_instruction_words'] <= 12 or type(cfg['offer_instruction_repeat']) is not bool):
        raise DownUnavailable('INVALID_CONFIG')
    low, high = (1, 4) if payload['game_type'] == 'recognize_choose' else (0, 2)
    if not low <= cfg['distractors'] <= high:
        raise DownUnavailable('INVALID_CONFIG')
    if previous_state is not None and data.get('config_before') != previous_state['config']:
        raise DownUnavailable('STATE_MISMATCH')
    return body, elapsed_ms
