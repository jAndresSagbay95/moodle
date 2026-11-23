"""Versioned integration fixtures; these are not validated scientific rules."""
from copy import deepcopy
import json
from pathlib import Path

FIXTURES = Path(__file__).with_name('fixtures')


def fixture(name):
    return json.loads((FIXTURES / name).read_text())


def scenarios():
    return fixture('scenarios.json')


def scenario(identifier):
    return deepcopy(scenarios()[identifier])


def tutor_payload(case, objective):
    profile, decision = case['profile'], case['motor_response']['data']
    return {
        'student_id': profile['student_id'], 'topic': case['topic'],
        'difficulty': decision['parameters']['new_difficulty'],
        'preferred_modality': profile['preferred_modality'], 'context': objective,
        'performance_history': [{'topic': event['topic'], 'score': event['score'], 'time': event['timestamp']}
                                for event in case['motor_request']['interaction_history']],
        'known_concepts': profile['known_concepts'], 'gaps': profile['gaps'],
    }
