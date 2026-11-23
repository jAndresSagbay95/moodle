// Native Moodle form E2E: fictional accounts, real gateway and TutorIA, LLM disabled.
const {chromium} = require(process.env.PLAYWRIGHT_PATH || 'playwright');
const {readFileSync, mkdirSync, writeFileSync} = require('node:fs');
const {execFileSync} = require('node:child_process');
const {randomBytes} = require('node:crypto');
const {join, resolve} = require('node:path');
const assert = require('node:assert/strict');
const root = resolve(__dirname, '..');
const env = Object.fromEntries(readFileSync(join(root, '.env'), 'utf8').split('\n')
    .filter(s => s && !s.startsWith('#') && s.includes('='))
    .map(s => [s.slice(0, s.indexOf('=')), s.slice(s.indexOf('=') + 1)]));
const provider = readFileSync(resolve(root, '../../patricio-lucero/TutorIA-Tesis/licencias/secure_data.env'), 'utf8');
assert(/^LLM_ENABLED=false\s*$/m.test(provider), 'This check requires the real LLM to remain disabled');
const password = 'Qa-' + randomBytes(24).toString('hex') + '!';
const artifacts = process.env.ADAPTIA_QA_ARTIFACTS || '/private/tmp/adaptia-tutoria-ui';
mkdirSync(artifacts, {recursive: true});
function fixture(action) {
    const out = execFileSync('docker', ['compose', 'exec', '-T', '-u', 'www-data',
        '-e', 'ADAPTIA_QA_PASSWORD', 'moodle', 'php',
        '/var/www/html/ambiente-local/tests/tutoria_ui_fixture.php', action],
        {cwd: root, env: {...process.env, ADAPTIA_QA_PASSWORD: password}, encoding: 'utf8'});
    return JSON.parse(out.slice(out.indexOf('{')));
}
const base = 'http://localhost:8086';
async function login(browser, kind) {
    const context = await browser.newContext({viewport: {width: 1440, height: 1000}});
    const page = await context.newPage();
    await page.goto(base + '/login/index.php');
    await page.locator('#username').fill('adaptia.qa.tutoria.' + kind);
    await page.locator('#password').fill(password);
    await Promise.all([page.waitForURL(u => !u.pathname.includes('/login/')), page.locator('#loginbtn').click()]);
    await page.waitForLoadState('networkidle');
    const tour = page.getByRole('button', {name: /^(End tour|Salir del tour)$/});
    if (await tour.isVisible()) await tour.click();
    return {context, page};
}
async function stableView(page) {
    await page.waitForLoadState('networkidle');
    const button = page.locator('#theme_boost-drawers-courseindex [data-action="closedrawer"]');
    const box = await button.boundingBox();
    if (box && box.x >= 0 && box.x < await page.evaluate(() => innerWidth)) {
        await button.click();
        await page.waitForFunction(() => {
            const drawer = document.getElementById('theme_boost-drawers-courseindex');
            return !drawer || drawer.getBoundingClientRect().right <= 0 || getComputedStyle(drawer).visibility === 'hidden';
        });
    }
}
async function main() {
    const data = fixture('setup');
    const browser = await chromium.launch({headless: true,
        executablePath: process.env.CHROMIUM_EXECUTABLE || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'});
    const checks = [];
    try {
        const teacher = await login(browser, 'teacher');
        const path = '/local/adaptia_core/tutoria.php?courseid=' + data.courseid;
        await teacher.page.goto(base + '/course/view.php?id=' + data.courseid);
        assert(await teacher.page.locator('a[href*="/local/adaptia_core/tutoria.php"]').count());
        checks.push('native course navigation');
        await teacher.page.goto(base + path);
        await teacher.page.locator('#id_topic').fill('x');
        await teacher.page.locator('#id_context').fill('QA verificar explicación con perfil ficticio.');
        await teacher.page.locator('#id_submitbutton').click();
        await teacher.page.locator('#id_error_topic').waitFor({state: 'visible'});
        assert.equal(fixture('report').requests.length, 0);
        assert(await teacher.page.locator('#id_topic').isVisible());
        checks.push('server form validation before sending');
        await teacher.page.locator('#id_topic').fill('QA Derivadas del curso');
        const payload = await teacher.page.locator('form.mform').evaluate(form => Object.fromEntries(new FormData(form)));
        payload.submitbutton = 'Request content';
        const csrf = await teacher.context.request.post(base + path, {form: {...payload, sesskey: 'invalid'}});
        assert(!csrf.ok() || !(await csrf.text()).includes('Content received'));
        assert.equal(fixture('report').requests.length, 0);
        checks.push('sesskey rejects forged POST');
        await Promise.all([teacher.page.waitForURL(u => Boolean(u.searchParams.get('id'))),
            teacher.page.locator('#id_submitbutton').click()]);
        const detail = teacher.page.url();
        const requestid = new URL(detail).searchParams.get('id');
        assert.equal(requestid, payload.requestid);
        assert((await teacher.page.locator('#region-main').innerText()).includes('Generation is disabled or unavailable'));
        let report = fixture('report');
        assert.equal(report.requests.length, 1);
        assert.equal(report.requests[0].status, 'failed');
        assert.equal(report.requests[0].httpstatus, 503);
        checks.push('real service 503 is stored and shown accurately');
        await teacher.context.request.post(base + path, {form: payload});
        await teacher.page.reload();
        assert.equal(fixture('report').requests.length, 1);
        checks.push('duplicate POST and reload preserve one immutable submission');
        const other = await login(browser, 'other');
        await other.page.goto(detail);
        assert(!(await other.page.locator('#region-main').innerText()).includes('QA Derivadas del curso'));
        await teacher.page.goto(detail.replace('courseid=' + data.courseid, 'courseid=' + data.othercourseid));
        assert(!(await teacher.page.locator('#region-main').innerText()).includes('QA Derivadas del curso'));
        checks.push('teacher and course ownership enforced');
        const student = await login(browser, 'student');
        await student.page.goto(base + '/course/view.php?id=' + data.courseid);
        assert.equal(await student.page.locator('a[href*="/local/adaptia_core/tutoria.php"]').count(), 0);
        await student.page.goto(base + path);
        assert.equal(await student.page.locator('#id_submitbutton').count(), 0);
        checks.push('student capability denial and hidden navigation');
        const controlled = fixture('render-success');
        await teacher.page.goto(base + path + '&id=' + controlled.requestid);
        assert((await teacher.page.locator('#region-main').innerText()).includes('controlled-ui-fixture'));
        assert((await teacher.page.locator('#region-main').innerText()).includes('Not reported'));
        assert.equal(await teacher.page.evaluate(() => Boolean(window.tutoriaInjected)), false);
        assert((await teacher.page.locator('#region-main').innerText()).includes('<script>'));
        checks.push('Mustache escapes controlled provider text and preserves unknown usage');
        for (const key of ['ADAPTIA_SERVICE_TOKEN', 'ADAPTIA_ACTOR_SECRET', 'ADAPTIA_TUTORIA_TOKEN']) {
            if (env[key]) assert(!(await teacher.page.content()).includes(env[key]), 'Credential must stay on the server');
        }
        await stableView(teacher.page);
        await teacher.page.screenshot({path: join(artifacts, 'controlled-output.png'), fullPage: true, animations: 'disabled'});
        checks.push('stable desktop output with native drawer closed');
        await teacher.page.goto(detail);
        await teacher.page.setViewportSize({width: 390, height: 844});
        await teacher.page.reload();
        assert(await teacher.page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
        await stableView(teacher.page);
        await teacher.page.screenshot({path: join(artifacts, 'service-disabled-mobile.png'), fullPage: true, animations: 'disabled'});
        await teacher.page.goto(base + path);
        assert(await teacher.page.locator('#id_topic').isVisible());
        assert(await teacher.page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
        await stableView(teacher.page);
        await teacher.page.screenshot({path: join(artifacts, 'native-form-mobile.png'), fullPage: true, animations: 'disabled'});
        checks.push('mobile layout and absence of service credentials in HTML');
        assert.deepEqual(fixture('privacy'), {context_found: true, scoped_deletion: true});
        checks.push('Privacy API discovers course data and deletes only the approved user data');
        writeFileSync(join(artifacts, 'report.json'), JSON.stringify({checks,
            course: base + '/course/view.php?id=' + data.courseid,
            form: base + path, llm_real_generation_tested: false,
            success_rendering_source: 'explicit controlled fixture, not an LLM response'}, null, 2));
        console.log('PASS', checks.length, 'native Moodle UI checks');
        console.log('FORM', base + path);
    } finally {
        await browser.close();
        fixture('cleanup');
    }
}
main().catch(error => {console.error(error); process.exitCode = 1;});
