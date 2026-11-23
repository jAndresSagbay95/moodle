"""Idempotent schema setup for the initial integration registry."""
import os
from pathlib import Path
from app import connection

if __name__ == '__main__':
    with connection(os.environ) as db:
        db.execute(Path(__file__).with_name('schema.sql').read_text())
    print('ADAPTIA schema ready.')
