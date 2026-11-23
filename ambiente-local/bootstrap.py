"""Prepara Moodle local; conserva la base de datos cuando ya está instalada."""
import os
from pathlib import Path
import secrets
import subprocess
import sys

ROOT = Path(__file__).resolve().parent


def compose(*args, check=True):
    return subprocess.run(['docker', 'compose', *args], cwd=ROOT, check=check)


if __name__ == '__main__':
    envpath = ROOT / '.env'
    current = envpath.read_text() if envpath.exists() else ''
    env = dict(line.split('=', 1) for line in current.splitlines()
               if line and not line.startswith('#') and '=' in line)
    for key in ('MOODLE_DB_PASSWORD', 'MOODLE_ADMIN_PASSWORD', 'ADAPTIA_DB_PASSWORD',
                'ADAPTIA_SERVICE_TOKEN', 'ADAPTIA_ACTOR_SECRET', 'ADAPTIA_TEST_DB_PASSWORD'):
        if not env.get(key):
            env[key] = 'Adaptia-' + secrets.token_hex(24) + '!'
            current += ('\n' if current and not current.endswith('\n') else '') + key + '=' + env[key] + '\n'
    envpath.write_text(current)
    envpath.chmod(0o600)
    # Subprocesses inherit secrets without placing their values in command arguments.
    os.environ.update(env)
    compose('build', 'backend', 'moodle')
    compose('up', '-d', '--wait', 'postgres', 'mocks', 'down')
    testmode = '--test' in sys.argv
    compose('run', '--rm', '--no-deps', '-e', 'MOODLE_DB_PASSWORD', '-e', 'ADAPTIA_TEST_DB_PASSWORD',
            'backend', 'python', 'init_db.py', *(['--test'] if testmode else []))
    if testmode:
        os.environ['PGPASSWORD'] = env['ADAPTIA_TEST_DB_PASSWORD']
        compose('run', '--rm', '--no-deps', '-e', 'PGDATABASE=adaptia_test', '-e', 'PGUSER=adaptia_test',
                '-e', 'PGPASSWORD',
                'backend', 'python', '-m', 'unittest', '-v', 'test_requests', 'test_down', 'test_tutoria')
        sys.exit(0)
    compose('up', '-d', '--wait')
    compose('exec', '-T', '-e', 'COMPOSER_ALLOW_SUPERUSER=1', 'moodle',
            'composer', 'install', '--no-dev', '--prefer-dist', '--no-interaction')
    probe = subprocess.run(
        ['docker', 'compose', 'exec', '-T', 'postgres', 'psql', '-U', 'moodle', '-d', 'moodle',
         '-Atc', "SELECT to_regclass('public.mdl_config') IS NOT NULL"],
        cwd=ROOT, check=True, capture_output=True, text=True)
    if probe.stdout.strip() != 't':
        compose('exec', '-T', '-u', 'www-data', 'moodle', 'php',
                'admin/cli/install_database.php', '--agree-license', '--lang=en',
                '--adminuser=admin', '--adminpass=' + env['MOODLE_ADMIN_PASSWORD'],
                '--adminemail=admin@localhost.invalid', '--fullname=ADAPTIA - Desarrollo',
                '--shortname=ADAPTIA')
    else:
        compose('exec', '-T', '-u', 'www-data', 'moodle', 'php',
                'admin/cli/upgrade.php', '--non-interactive', '--allow-unstable')
    compose('exec', '-T', '-u', 'www-data', 'moodle', 'php', 'admin/cli/purge_caches.php')
    print('Moodle listo en http://localhost:8086. Credenciales locales en ambiente-local/.env.')
