"""Ejecuta la extensión real de Andrés con sus catálogos versionados, sin Firebase.

El código de la tesis se monta en solo lectura en /mision-down; las reglas se reutilizan.
"""
import os
from pathlib import Path
import sys


def create_app():
    root = Path(os.environ.get('MISION_DOWN_SOURCE', '/mision-down'))
    sys.path.insert(0, str(root / 'backend' / 'src'))
    from api.app import create_app as extension_app
    from core.catalogos import RepositorioCatalogos
    token = os.environ.get('ADAPTIA_DOWN_TOKEN') or os.environ['ADAPTIA_SERVICE_TOKEN']
    if len(token) < 32:
        raise RuntimeError('Configura ADAPTIA_DOWN_TOKEN con al menos 32 caracteres.')
    return extension_app(
        RepositorioCatalogos.desde_directorio(root / 'docs' / 'fase2' / 'catalogos'),
        [token], root / 'docs' / 'fase2' / 'contrato' / 'extension_adaptia.openapi.yaml',
    )
