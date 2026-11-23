// Verificación desde Moodle con cuentas ficticias, PostgreSQL y el motor real de Andrés.
const {chromium} = require(process.env.PLAYWRIGHT_PATH || 'playwright');
const {readFileSync, mkdirSync, writeFileSync} = require('node:fs');
const {join, resolve} = require('node:path');
const {execFileSync} = require('node:child_process');
const {randomBytes} = require('node:crypto');
const assert = require('node:assert/strict');
const root = resolve(__dirname, '..');
const env = Object.fromEntries(readFileSync(join(root, '.env'), 'utf8').split('\n')
    .filter(line => line && !line.startsWith('#') && line.includes('='))
    .map(line => [line.slice(0, line.indexOf('=')), line.slice(line.indexOf('=') + 1)]));
const password = 'Qa-' + randomBytes(24).toString('hex') + '!';
const base = 'http://localhost:8086';
const artifacts = process.env.ADAPTIA_DOWN_QA_ARTIFACTS || '/private/tmp/adaptia-down-qa';
mkdirSync(artifacts, {recursive: true});
function fixture(action) {
    const output = execFileSync('docker', ['compose', 'exec', '-T', '-u', 'www-data',
        '-e', 'ADAPTIA_QA_PASSWORD', 'moodle', 'php', '/var/www/html/ambiente-local/tests/fixture.php', action],
        {cwd: root, env: {...process.env, ADAPTIA_QA_PASSWORD: password}, encoding: 'utf8'});
    return JSON.parse(output.slice(output.indexOf('{')));
}
async function login(browser, username, pass) {
    const context = await browser.newContext({viewport: {width: 1440, height: 1000}});
    const page = await context.newPage();
    await page.goto(base + '/login/index.php');
    await page.locator('#username').fill(username);
    await page.locator('#password').fill(pass);
    await Promise.all([page.waitForURL(url => !url.pathname.includes('/login/')), page.locator('#loginbtn').click()]);
    await page.waitForLoadState('networkidle');
    const tour = page.getByRole('button', {name: /^(End tour|Salir del tour)$/});
    if (await tour.isVisible()) await tour.click();
    return {context, page};
}
async function main() {
    const inspect = process.argv.includes('--inspect');
    const data = fixture(inspect ? 'report' : 'setup');
    const browser = await chromium.launch({headless: true,
        executablePath: process.env.CHROMIUM_EXECUTABLE || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'});
    const results = [];
    try {
        const admin = await login(browser, 'admin', env.MOODLE_ADMIN_PASSWORD);
        const path = '/local/adaptia_core/down.php?courseid=' + data.courseid;
        if (inspect) {
            await admin.page.goto(process.env.ADAPTIA_DOWN_SESSION_URL);
            const drawer = admin.page.getByRole('button', {name: /^(Close course index|Cerrar índice del curso)$/});
            if (await drawer.isVisible()) await drawer.click();
            assert(await admin.page.getByRole('button', {name: 'Cancelar', exact: true}).isVisible());
            assert(await admin.page.getByRole('link', {name: 'Volver al curso', exact: true}).isVisible());
            assert(await admin.page.getByRole('cell', {name: 'Error', exact: true}).count() >= 3);
            await admin.page.screenshot({path: join(artifacts, 'final-escritorio.png'), fullPage: true});
            await admin.page.setViewportSize({width: 390, height: 844});
            await admin.page.reload();
            assert(await admin.page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
            await admin.page.screenshot({path: join(artifacts, 'final-movil.png'), fullPage: true});
            console.log('CORRECTO: revisión final en escritorio y móvil, con textos en español.');
            return;
        }
        let firstSession;
        for (const game of ['recognize_choose', 'relate_associate', 'order_sequence']) {
            await admin.page.goto(base + path);
            await admin.page.locator('#id_game_type').selectOption(game);
            await admin.page.locator('#id_threshold_seconds').fill('20');
            await Promise.all([
                admin.page.waitForURL(url => url.searchParams.has('id')),
                admin.page.getByRole('button', {name: 'Iniciar sesión de prueba', exact: true}).click(),
            ]);
            const sessionUrl = admin.page.url();
            if (!firstSession) firstSession = sessionUrl;
            let adaptation;
            for (let seq = 1; seq <= 20; seq++) {
                await admin.page.locator('#id_outcome').selectOption(seq <= 3 ? 'incorrect' : 'correct');
                await admin.page.locator('#id_latency_seconds').fill('10');
                await admin.page.getByRole('button', {name: 'Registrar ensayo y consultar adaptación', exact: true}).click();
                await admin.page.waitForLoadState('networkidle');
                const history = JSON.parse(await admin.page.locator('details pre').textContent());
                assert.equal(history.at(-1).seq, seq);
                if (seq === 3) {
                    adaptation = history.at(-1).decision.data;
                    assert.deepEqual(adaptation.rules_applied, ['down_001']);
                    assert.equal(adaptation.config_after.support_level, 2);
                    assert.equal(adaptation.config_after.level, 1);
                    assert.equal(adaptation.config_after.distractors, game === 'recognize_choose' ? 2 : 0);
                    console.log(game + ': adaptación comprobada tras tres errores.');
                }
            }
            const history = JSON.parse(await admin.page.locator('details pre').textContent());
            assert.equal(history.length, 21, 'Debe conservar el inicio y los veinte ensayos');
            assert.equal(await admin.page.locator('#id_outcome').count(), 0, 'La sesión completada no admite otro ensayo');
            assert(await admin.page.locator('.alert').filter({hasText: 'Sesión de prueba completada. Puedes revisar su historial o iniciar otra.'}).isVisible());
            console.log(game + ': sesión completada, 20/20 y 21 registros persistidos.');
            assert(!await admin.page.content().then(text => text.includes(env.ADAPTIA_SERVICE_TOKEN)));
            await admin.page.screenshot({path: join(artifacts, game + '-escritorio.png'), fullPage: true});
            await admin.page.screenshot({path: join(artifacts, game + '-resumen.png')});
            await admin.page.setViewportSize({width: 390, height: 844});
            await admin.page.reload();
            assert(await admin.page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
                'La pantalla debe caber en el ancho del móvil');
            await admin.page.screenshot({path: join(artifacts, game + '-movil.png'), fullPage: true});
            await admin.page.setViewportSize({width: 1440, height: 1000});
            results.push({juego: game, ensayos: 20, estado: 'completed', registros_historial: history.length,
                regla_al_tercer_error: adaptation.rules_applied[0], apoyo_al_tercer_error: '1 → 2',
                decision_final: history.at(-1).decision.data.config_after, movil: 'correcto', url: sessionUrl});
        }
        const other = await login(browser, 'adaptia.qa.other', password);
        await other.page.goto(firstSession);
        assert.equal(await other.page.getByRole('heading', {name: 'Configuración de la siguiente actividad'}).count(), 0);
        assert.equal(await other.page.locator('#id_outcome').count(), 0);
        const student = await login(browser, 'adaptia.qa.student', password);
        await student.page.goto(firstSession);
        assert.equal(await student.page.locator('#id_outcome').count(), 0);
        assert.equal(await student.page.locator('#id_game_type').count(), 0);
        const csrf = await admin.context.request.post(firstSession + '&action=retry', {form: {sesskey: 'invalido'}});
        assert((await csrf.text()).match(/sesskey|session|sesión|invalid/i));
        await admin.page.goto(firstSession);
        const saved = JSON.parse(await admin.page.locator('details pre').textContent());
        assert.equal(saved.at(-1).seq, 20);
        assert.equal(saved.length, 21);
        writeFileSync(join(artifacts, 'resultado.json'), JSON.stringify({resultados: results,
            aislamiento_docentes: 'correcto', acceso_estudiante: 'bloqueado', csrf: 'protegido'}, null, 2));
        console.log('CORRECTO: tres sesiones completas, 60 ensayos con motor real, persistencia, diseño móvil, aislamiento y protección CSRF.');
        console.log('Sesión de ejemplo: ' + firstSession);
    } finally {
        await browser.close();
        if (!inspect) fixture('cleanup');
    }
}
main().catch(error => {console.error(error.message); process.exitCode = 1;});
