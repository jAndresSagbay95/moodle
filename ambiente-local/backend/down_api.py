"""Adaptador con persistencia para la extensión sin estado de Misión Down."""
from datetime import datetime, timezone
import re
from typing import Literal
from uuid import UUID, uuid4

from flask import jsonify, request
from pydantic import BaseModel, ConfigDict, Field, ValidationError
from psycopg.types.json import Jsonb

import down_client


class Input(BaseModel):
    model_config = ConfigDict(extra='forbid', strict=True)


class Preferences(Input):
    initial_level: int | None = Field(default=None, ge=1, le=3)
    max_support_level: int | None = Field(default=None, ge=0, le=3)
    max_distractors: int | None = Field(default=None, ge=0, le=4)
    latency_threshold_ms: int | None = Field(default=None, ge=300, le=120000)


class Session(Input):
    student_id: str = Field(pattern=r'^stud_[A-Za-z0-9]{8,32}$')
    game_type: Literal['recognize_choose', 'relate_associate', 'order_sequence']
    preferences: Preferences = Field(default_factory=Preferences)


class Trial(Input):
    event_id: str
    seq: int = Field(ge=1)
    outcome: Literal['correct', 'incorrect', 'no_response']
    first_action_latency_ms: int | None = Field(ge=0, le=600000)
    backgrounded_ms: int = Field(default=0, ge=0, le=600000)
    paused_ms: int = Field(default=0, ge=0, le=600000)
    help_requests: int = Field(default=0, ge=0, le=100)
    instruction_repeats: int = Field(default=0, ge=0, le=100)


def register_down(app, connection, problem):
    def actor():
        return request.headers['X-ADAPTIA-Actor']

    def scope():
        value = request.args.get('course_ref', '')
        return value if re.fullmatch(r'[a-f0-9]{64}', value) else None

    def identifier(value):
        try:
            return UUID(value)
        except (ValueError, TypeError, AttributeError):
            return None

    def parse(model):
        try:
            return model.model_validate(request.get_json())
        except ValidationError:
            return None

    def public(row, history=None):
        data = {k: row[k] for k in ('student_id', 'game_type', 'preferences', 'versions', 'trial_limit',
                                    'status', 'last_seq', 'last_decision', 'pending_event', 'last_error')}
        data.update(id=str(row['id']), course_ref=row['course_ref'], mode='extension',
                    created_at=row['created_at'].isoformat(), updated_at=row['updated_at'].isoformat())
        if history is not None:
            data['history'] = history
        return data

    def row_for(db, value):
        uid = identifier(value)
        if uid is None or scope() is None:
            return None
        return db.execute('SELECT * FROM down_sessions WHERE id=%s AND actor=%s '
                          'AND course_ref=%s FOR UPDATE', (uid, actor(), scope())).fetchone()

    def failed(db, row, error, event=None):
        detail = {'message': str(error), 'code': error.code, 'upstream_http_status': error.http_status,
                  'fallback': 'keep_current_config', 'timestamp': datetime.now(timezone.utc).isoformat()}
        db.execute('UPDATE down_sessions SET last_error=%s,pending_event=%s,updated_at=clock_timestamp() '
                   'WHERE id=%s', (Jsonb(detail), Jsonb(event) if event is not None else None, row['id']))
        return problem(502, str(error))

    def initialise(db, row):
        catalog = down_client.catalogs(app.config, row['id'])
        games = catalog['games'].get('games', [])
        game = next((g for g in games if g.get('game_type') == row['game_type']), None)
        prefs = row['preferences']
        if game is None:
            raise down_client.DownUnavailable('UNKNOWN_GAME')
        if prefs.get('max_distractors') is not None and not (
                game['distractors']['min'] <= prefs['max_distractors'] <= game['distractors']['max']):
            # Se vuelve a comprobar por si cambió el catálogo del proveedor.
            raise down_client.DownUnavailable('PREFERENCES_OUT_OF_RANGE')
        versions = row['versions'] or catalog['versions']
        payload = {'student_id': row['student_id'], 'session_id': str(row['id']),
                   'game_type': row['game_type'], 'model_state': None, 'preferences': prefs,
                   'rules_version': versions['rules'], 'params_version': versions['params']}
        body, elapsed = down_client.evaluate(app.config, payload, row['id'], versions)
        trial_limit = catalog['games']['common']['trials_per_session']
        updated = db.execute("UPDATE down_sessions SET status='active',versions=%s,trial_limit=%s,model_state=%s,"
                             'last_decision=%s,last_error=NULL,updated_at=clock_timestamp() WHERE id=%s RETURNING *',
                             (Jsonb(versions), trial_limit, Jsonb(body['data']['model_state']), Jsonb(body), row['id'])).fetchone()
        snapshot = public(updated)
        db.execute('INSERT INTO down_trials(session_id,event_id,seq,event,response,elapsed_ms) VALUES (%s,%s,0,%s,%s,%s)',
                   (row['id'], row['id'], Jsonb({'kind': 'initial', 'preferences': prefs}), Jsonb(snapshot), elapsed))
        return jsonify(data=snapshot)

    def submit(db, row, event):
        payload = {'student_id': row['student_id'], 'session_id': str(row['id']),
                   'game_type': row['game_type'], 'rules_version': row['versions']['rules'],
                   'params_version': row['versions']['params'], 'preferences': row['preferences'],
                   'model_state': row['model_state'], 'interaction_event': event}
        try:
            body, elapsed = down_client.evaluate(app.config, payload, event['event_id'],
                                                row['versions'], row['model_state'])
        except down_client.DownUnavailable as error:
            return failed(db, row, error, event)
        completed = event['seq'] >= row['trial_limit']
        updated = db.execute('UPDATE down_sessions SET model_state=%s,last_decision=%s,last_seq=%s,status=%s,'
                             'last_error=NULL,pending_event=NULL,updated_at=clock_timestamp() WHERE id=%s RETURNING *',
                             (Jsonb(body['data']['model_state']), Jsonb(body), event['seq'],
                              'completed' if completed else 'active', row['id'])).fetchone()
        snapshot = public(updated)
        db.execute('INSERT INTO down_trials(session_id,event_id,seq,event,response,elapsed_ms) VALUES (%s,%s,%s,%s,%s,%s)',
                   (row['id'], UUID(event['event_id']), event['seq'], Jsonb(event), Jsonb(snapshot), elapsed))
        return jsonify(data=snapshot)

    @app.get('/api/v1/down/catalogs')
    def down_catalogs():
        try:
            return jsonify(data=down_client.catalogs(app.config, uuid4()))
        except down_client.DownUnavailable as error:
            return problem(502, str(error))

    @app.route('/api/v1/down/sessions', methods=['GET', 'POST'])
    def down_sessions():
        if scope() is None:
            return problem(400, 'Se requiere course_ref seudónimo del curso.')
        if request.method == 'GET':
            with connection(app.config) as db:
                rows = db.execute('SELECT * FROM down_sessions WHERE actor=%s AND course_ref=%s '
                                  'ORDER BY created_at DESC,id DESC LIMIT 50', (actor(), scope())).fetchall()
            return jsonify(data=[public(row) for row in rows])
        payload = parse(Session)
        key = identifier(request.headers.get('Idempotency-Key'))
        if payload is None or key is None:
            return problem(400, 'Sesión no válida. Usa un estudiante seudónimo, un juego válido y una clave UUID.')
        values = payload.model_dump()
        prefs = {k: v for k, v in values['preferences'].items() if v is not None}
        low, high = (1, 4) if values['game_type'] == 'recognize_choose' else (0, 2)
        if prefs.get('max_distractors') is not None and not low <= prefs['max_distractors'] <= high:
            return problem(400, 'El máximo de distractores debe respetar el rango del juego.')
        with connection(app.config) as db:
            row = db.execute('INSERT INTO down_sessions(id,actor,course_ref,idempotency_key,student_id,game_type,preferences) '
                             'VALUES (%s,%s,%s,%s,%s,%s,%s) ON CONFLICT(actor,idempotency_key) DO NOTHING RETURNING *',
                             (uuid4(), actor(), scope(), key, values['student_id'], values['game_type'], Jsonb(prefs))).fetchone()
            if row is None:
                row = db.execute('SELECT * FROM down_sessions WHERE actor=%s AND idempotency_key=%s FOR UPDATE',
                                 (actor(), key)).fetchone()
                if (row['course_ref'] != scope() or row['student_id'] != values['student_id']
                        or row['game_type'] != values['game_type'] or row['preferences'] != prefs):
                    return problem(409, 'Esta clave ya corresponde a otra sesión.')
                # Repetir el inicio conserva la sesión y su avance.
                if row['status'] != 'starting':
                    return jsonify(data=public(row))
            try:
                return initialise(db, row)
            except down_client.DownUnavailable as error:
                return failed(db, row, error)

    @app.get('/api/v1/down/sessions/<value>')
    def down_detail(value):
        with connection(app.config) as db:
            row = row_for(db, value)
            if row is None:
                return problem(404, 'Sesión no encontrada en este curso.')
            events = db.execute('SELECT seq,event,response,elapsed_ms,created_at FROM down_trials '
                                'WHERE session_id=%s ORDER BY seq', (row['id'],)).fetchall()
            history = [{'seq': e['seq'], 'event': e['event'],
                        'decision': e['response']['last_decision'], 'elapsed_ms': e['elapsed_ms'],
                        'created_at': e['created_at'].isoformat()} for e in events]
        return jsonify(data=public(row, history))

    @app.post('/api/v1/down/sessions/<value>/trials')
    def down_trial(value):
        trial = parse(Trial)
        if trial is None or identifier(trial.event_id) is None or identifier(trial.event_id).version != 4:
            return problem(400, 'Ensayo no válido. event_id debe ser UUID v4; revisa resultado, secuencia y tiempos.')
        event = trial.model_dump()
        with connection(app.config) as db:
            row = row_for(db, value)
            if row is None:
                return problem(404, 'Sesión no encontrada en este curso.')
            old = db.execute('SELECT event,response FROM down_trials WHERE session_id=%s AND event_id=%s',
                             (row['id'], UUID(event['event_id']))).fetchone()
            if old is not None:
                if old['event'] != event:
                    return problem(409, 'event_id ya corresponde a otro ensayo.')
                return jsonify(data=old['response'])
            if row['status'] != 'active' or event['seq'] != row['last_seq'] + 1:
                return problem(409, 'La sesión no está activa o el ensayo no es el siguiente de la secuencia.')
            if row['pending_event'] is not None and row['pending_event'] != event:
                return problem(409, 'Primero reintenta el ensayo pendiente; se conserva la configuración actual.')
            return submit(db, row, event)

    @app.post('/api/v1/down/sessions/<value>/retry')
    def down_retry(value):
        with connection(app.config) as db:
            row = row_for(db, value)
            if row is None:
                return problem(404, 'Sesión no encontrada en este curso.')
            if row['status'] == 'starting':
                try:
                    return initialise(db, row)
                except down_client.DownUnavailable as error:
                    return failed(db, row, error)
            if row['pending_event'] is None:
                return problem(409, 'No hay un ensayo pendiente de reintento.')
            return submit(db, row, row['pending_event'])
