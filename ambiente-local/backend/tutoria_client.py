"""Cliente de TutorIA real, con identidad de servicio y sin reintentos automáticos."""
import hashlib
import json
import re
from datetime import datetime, timezone
from http.client import HTTPException as ProtocolError
from time import monotonic
from urllib.error import HTTPError, URLError
from urllib.parse import urlsplit
from urllib.request import HTTPRedirectHandler, Request, build_opener

from flask import jsonify, request
from pydantic import BaseModel, ConfigDict, Field, ValidationError

PATH = '/api/v1/tutoria/generate-content'
CORRELATION = re.compile(r'[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}')
MAX_RESPONSE = 524288
ERRORS = {
    'INVALID_REQUEST': 'Revisa los campos o el estado del identificador de la solicitud.',
    'INVALID_STUDENT_PROFILE': 'Revisa el perfil de prueba enviado.',
    'AUTHENTICATION_FAILED': 'Revisa la credencial de servicio de TutorIA en el backend.',
    'AI_SERVICE_UNAVAILABLE': 'TutorIA no puede generar en este momento. El proveedor puede estar apagado.',
    'RATE_LIMIT_EXCEEDED': 'TutorIA alcanzó su límite de uso. No se realizó otro envío automático.',
    'INTERNAL_SERVER_ERROR': 'TutorIA no pudo completar la solicitud.',
}


class Model(BaseModel):
    model_config = ConfigDict(extra='forbid', strict=True)


class Section(Model):
    type: str = Field(pattern=r'^(explanation|example|exercise)$')
    title: str = Field(min_length=1, max_length=160)
    text: str = Field(min_length=1, max_length=4000)


class Structure(Model):
    title: str = Field(min_length=1, max_length=160)
    sections: list[Section] = Field(min_length=3, max_length=8)


class Usage(Model):
    model_used: str = Field(min_length=1, max_length=256)
    tokens_used: int | None = Field(ge=0)
    generation_time_ms: int = Field(ge=0)


class Content(Model):
    content: str = Field(min_length=1, max_length=36000)
    structure: Structure
    metadata_generation: Usage


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def upstream_id(actor, value):
    # No se puede reutilizar el ID de otro docente para recuperar su contenido.
    return 'moodle_' + hashlib.sha256((actor + ':' + value).encode()).hexdigest()


def generate(config, payload, actor, request_id, session_id):
    """Devuelve un envelope acotado; nunca cabeceras ni errores crudos del proveedor."""
    started = monotonic()
    uid = upstream_id(actor, request_id)
    sid = upstream_id(actor, session_id)

    def failure(status, code):
        return {
            'success': False,
            'error': {'code': code, 'message': ERRORS[code]},
            'metadata': {'timestamp': datetime.now(timezone.utc).isoformat(),
                         'processing_time_ms': round((monotonic() - started) * 1000),
                         'api_version': 'v1.0', 'mode': 'direct', 'replayed': False},
            'tracking': {'request_id': request_id, 'session_id': sid,
                         'upstream_request_id': uid},
        }, status

    base = config.get('ADAPTIA_TUTORIA_URL', '').rstrip('/')
    token = config.get('ADAPTIA_TUTORIA_TOKEN', '')
    try:
        origin = urlsplit(base)
        timeout = int(config.get('ADAPTIA_TUTORIA_TIMEOUT') or 70)
        if (origin.scheme not in {'http', 'https'} or not origin.hostname
                or origin.username or origin.password or origin.query or origin.fragment
                or origin.path or not 5 <= timeout <= 70 or not 32 <= len(token) <= 512
                or any(ord(c) < 33 or ord(c) > 126 for c in token)):
            return failure(503, 'AI_SERVICE_UNAVAILABLE')
        req = Request(base + PATH, data=json.dumps(
            payload, allow_nan=False, ensure_ascii=False, separators=(',', ':')).encode(), headers={
            'Authorization': 'Bearer ' + token, 'Accept': 'application/json',
            'Content-Type': 'application/json', 'X-Request-ID': uid, 'X-Session-ID': sid,
        })
        try:
            response = build_opener(NoRedirect).open(req, timeout=timeout)
        except HTTPError as error:
            response = error
        with response:
            status = response.status
            if response.headers.get('X-ADAPTIA-Mode') == 'mock':
                raise ValueError('unexpected mock')
            raw = response.read(MAX_RESPONSE + 1)
            if len(raw) > MAX_RESPONSE or token.encode() in raw:
                raise ValueError('invalid response size or exposed credential')
            body = json.loads(raw)
            if (not isinstance(body, dict) or not isinstance(body.get('tracking'), dict)
                    or body['tracking'].get('request_id') != uid
                    or not isinstance(body.get('metadata'), dict)):
                raise ValueError('invalid correlation')
            if status != 200:
                code = body.get('error', {}).get('code')
                if body.get('success') is not False or code not in ERRORS:
                    raise ValueError('invalid error')
                expected = {400: {'INVALID_REQUEST'}, 401: {'AUTHENTICATION_FAILED'},
                            409: {'INVALID_REQUEST'}, 413: {'INVALID_REQUEST'},
                            422: {'INVALID_REQUEST', 'INVALID_STUDENT_PROFILE'},
                            429: {'RATE_LIMIT_EXCEEDED'}, 500: {'INTERNAL_SERVER_ERROR'},
                            502: {'AI_SERVICE_UNAVAILABLE'}, 503: {'AI_SERVICE_UNAVAILABLE'}}
                if code not in expected.get(status, set()):
                    raise ValueError('invalid status')
                return failure(status, code)
            data = Content.model_validate(body.get('data'))
            if (body.get('success') is not True
                    or body['tracking'].get('student_id_hash') != payload.get('student_id')
                    or body['tracking'].get('session_id') != sid
                    or {s.type for s in data.structure.sections} != {'explanation', 'example', 'exercise'}
                    or data.content != '\n\n'.join(s.title + '\n' + s.text for s in data.structure.sections)):
                raise ValueError('invalid content')
            meta = body['metadata']
            if (meta.get('api_version') != 'v1.0' or meta.get('mode') != 'direct'
                    or type(meta.get('replayed')) is not bool
                    or type(meta.get('processing_time_ms')) is not int or meta['processing_time_ms'] < 0
                    or not isinstance(meta.get('timestamp'), str)
                    or datetime.fromisoformat(meta['timestamp'].replace('Z', '+00:00')).tzinfo is None):
                raise ValueError('invalid metadata')
            return {'success': True, 'data': data.model_dump(),
                    'metadata': {key: meta[key] for key in
                                 ('timestamp', 'processing_time_ms', 'api_version', 'mode', 'replayed')},
                    'tracking': {'request_id': request_id, 'session_id': sid,
                                 'student_id_hash': payload['student_id'], 'upstream_request_id': uid}}, 200
    except (URLError, TimeoutError, OSError, ValueError, TypeError, AttributeError,
            ProtocolError, ValidationError, RecursionError):
        return failure(502, 'AI_SERVICE_UNAVAILABLE')


def register_tutoria(app, problem):
    @app.post(PATH)
    def generate_content():
        request_id = request.headers.get('X-Request-ID', '')
        session_id = request.headers.get('X-Session-ID', request_id)
        if not CORRELATION.fullmatch(request_id) or not CORRELATION.fullmatch(session_id):
            return problem(400, 'Se requieren X-Request-ID y correlación ASCII válidos.')
        payload = request.get_json()
        if not isinstance(payload, dict):
            return problem(400, 'Se requiere el cuerpo JSON del contrato TutorIA.')
        try:
            json.dumps(payload, allow_nan=False)
        except (ValueError, TypeError, RecursionError):
            return problem(400, 'El cuerpo debe contener valores JSON válidos.')
        body, status = generate(app.config, payload, request.headers['X-ADAPTIA-Actor'],
                                request_id, session_id)
        response = jsonify(body)
        response.status_code = status
        response.headers['X-Request-ID'] = request_id
        return response
