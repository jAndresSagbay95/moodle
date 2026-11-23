"""Local provisioning only; administrator password is never used by the web service."""
import os
import sys
import psycopg
from psycopg import sql

dbname = 'adaptia_test' if '--test' in sys.argv else 'adaptia'
role = 'adaptia_test' if '--test' in sys.argv else 'adaptia'
password = os.environ['ADAPTIA_TEST_DB_PASSWORD' if '--test' in sys.argv else 'PGPASSWORD']
with psycopg.connect(host='postgres', dbname='moodle', user='moodle',
                     password=os.environ['MOODLE_DB_PASSWORD'], autocommit=True) as db:
    if not db.execute('SELECT 1 FROM pg_roles WHERE rolname=%s', (role,)).fetchone():
        db.execute(sql.SQL('CREATE ROLE {} LOGIN PASSWORD {}').format(sql.Identifier(role), sql.Literal(password)))
    else:
        db.execute(sql.SQL('ALTER ROLE {} PASSWORD {}').format(sql.Identifier(role), sql.Literal(password)))
    if not db.execute('SELECT 1 FROM pg_database WHERE datname=%s', (dbname,)).fetchone():
        db.execute(sql.SQL('CREATE DATABASE {} OWNER {}').format(sql.Identifier(dbname), sql.Identifier(role)))
print('Local database ready:', dbname)
