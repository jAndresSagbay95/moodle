// Run with PLAYWRIGHT_PATH pointing to an installed Playwright package, or install it locally.
// Uses Chrome, local Docker, fictional accounts and a clearly labelled MOCK course.
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
const artifacts = process.env.ADAPTIA_QA_ARTIFACTS || '/private/tmp/adaptia-workflow-qa';
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
    if (await tour.isVisible()) {
        await tour.click();
        await page.waitForLoadState('networkidle');
    }
    return {context, page};
}
async function main() {
    const inspect = process.argv.includes('--inspect');
    const data = fixture(inspect ? 'report' : 'setup');
    const browser = await chromium.launch({headless: true,
        executablePath: process.env.CHROMIUM_EXECUTABLE || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'});
    try {
        const admin = await login(browser, 'admin', env.MOODLE_ADMIN_PASSWORD);
        const coursepath = '/local/adaptia_core/requests.php?courseid=' + data.courseid;
        if (inspect) {
            const publication = data.publications.find(item => Number(item.cmid) > 0);
            assert(publication);
            await admin.page.goto(base + coursepath + '&id=' + publication.requestid);
            await admin.page.waitForLoadState('networkidle');
            await admin.page.screenshot({path: join(artifacts, 'final-desktop.png'), fullPage: true, animations: 'disabled'});
            assert(await admin.page.getByRole('button', {name: 'Open published resource', exact: true}).isVisible());
            await admin.page.setViewportSize({width: 390, height: 844});
            await admin.page.reload();
            await admin.page.waitForLoadState('networkidle');
            assert(await admin.page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
                'The plugin page must fit the mobile viewport');
            await admin.page.screenshot({path: join(artifacts, 'final-mobile.png'), fullPage: true, animations: 'disabled'});
            assert(await admin.page.getByRole('button', {name: 'Open published resource', exact: true}).isVisible());
            await admin.page.goto(base + coursepath + '&action=new');
            await admin.page.waitForLoadState('networkidle');
            assert(await admin.page.locator('#id_scenario_id').isVisible());
            await admin.page.screenshot({path: join(artifacts, 'final-mobile-form.png'), fullPage: true, animations: 'disabled'});
            console.log('PASS final desktop and mobile inspection; no fixture users created');
            return;
        }
        const results = [];
        async function workflow(session, scenario, label) {
            const {page, context} = session;
            await page.goto(base + coursepath + '&action=new');
            await page.locator('#id_scenario_id').selectOption(scenario);
            const objective = `QA ${label}: comprobar el caso ${scenario} con perfiles ficticios.`;
            await page.locator('#id_objective').fill(objective);
            await page.screenshot({path: join(artifacts, label + '-form.png'), fullPage: true});
            await Promise.all([page.waitForURL(url => Boolean(url.searchParams.get('id'))),
                page.locator('#id_submitbutton').click()]);
            const detail = page.url();
            const requestid = new URL(detail).searchParams.get('id');
            assert(requestid);
            const run = page.getByRole('button', {name: 'Run mock services', exact: true});
            await run.waitFor();
            // A forged form must leave the request pending.
            await context.request.post(detail + '&action=run', {form: {sesskey: 'invalid'}});
            await page.reload();
            assert(await run.isVisible());
            await Promise.all([page.waitForNavigation(), run.click()]);
            const json = page.locator('details pre');
            await json.waitFor({state: 'attached'});
            const result = JSON.parse(await json.textContent());
            assert.equal(result.mode, 'mock');
            assert.equal(result.scenario_id, scenario);
            assert.equal(result.tokens, 0);
            assert.equal(result.cost_usd, '0.000000');
            assert.equal(result.trace.length, scenario === 'apoyo_down' ? 4 : 2);
            assert(result.trace.every(step => step.http_status === 200));
            if (scenario === 'apoyo_down') {
                assert.equal(result.down.config_after.offer_instruction_repeat, true);
                assert(Object.hasOwn(result.down.model_state, 'last_seq'));
            }
            const html = await page.content();
            for (const key of ['ADAPTIA_SERVICE_TOKEN', 'ADAPTIA_ACTOR_SECRET', 'ADAPTIA_DB_PASSWORD']) {
                assert(!html.includes(env[key]), `${key} leaked to browser`);
            }
            const sesskey = await page.evaluate(() => M.cfg.sesskey);
            // Replay does not call the services again or change the saved completion.
            await context.request.post(detail + '&action=run', {form: {sesskey}});
            await page.reload();
            assert.deepEqual(JSON.parse(await page.locator('details pre').textContent()), result);
            await page.screenshot({path: join(artifacts, label + '-preview.png'), fullPage: true});
            await context.request.post(detail + '&action=publish', {form: {sesskey: 'invalid'}});
            await page.reload();
            const publish = page.getByRole('button', {name: 'Publish MOCK resource in course', exact: true});
            assert(await publish.isVisible());
            await Promise.all([page.waitForURL(/\/mod\/page\/view.php/), publish.click()]);
            const published = page.url();
            assert((await page.locator('#region-main').innerText()).includes(result.structure.title));
            assert((await page.locator('#region-main').innerText()).includes('MOCK resource for integration testing'));
            // Replaying publication redirects to the same Page module.
            const replay = await context.request.post(detail + '&action=publish', {form: {sesskey}});
            assert.equal(new URL(replay.url()).searchParams.get('id'), new URL(published).searchParams.get('id'));
            await page.screenshot({path: join(artifacts, label + '-published.png'), fullPage: true});
            console.log('PASS workflow', label, scenario, published);
            return {scenario, detail, requestid, published, title: result.structure.title, objective};
        }
        for (const scenario of ['avance', 'refuerzo', 'apoyo_down']) {
            results.push(await workflow(admin, scenario, scenario));
        }
        // Returning from the global history must recover the correct course association.
        await admin.page.goto(base + '/local/adaptia_core/requests.php?id=' + results[0].requestid);
        assert((await admin.page.locator('#region-main').innerText()).includes('Course: [MOCK] ADAPTIA'));
        assert(await admin.page.getByRole('button', {name: 'Open published resource', exact: true}).isVisible());
        // The global form uses Moodle's native course selector.
        await admin.page.goto(base + '/local/adaptia_core/requests.php?action=new');
        assert(await admin.page.locator('[name="targetcourse"]').count());
        const teacher = await login(browser, 'adaptia.qa.teacher', password);
        const teacherResult = await workflow(teacher, 'avance', 'teacher');
        const other = await login(browser, 'adaptia.qa.other', password);
        await other.page.goto(teacherResult.detail);
        const otherText = await other.page.locator('#region-main').innerText();
        assert(!otherText.includes(teacherResult.objective));
        assert(otherText.includes('HTTP 404'));
        const student = await login(browser, 'adaptia.qa.student', password);
        await student.page.goto(base + '/course/view.php?id=' + data.courseid);
        assert.equal(await student.page.locator('a[href*="/local/adaptia_core/requests.php"]').count(), 0);
        for (const item of results) {
            await student.page.goto(item.published);
            const text = await student.page.locator('#region-main').innerText();
            assert(text.includes(item.title));
            assert(text.includes('MOCK resource for integration testing'));
        }
        await student.page.screenshot({path: join(artifacts, 'student-published.png'), fullPage: true});
        await student.page.goto(base + coursepath);
        assert(!(await student.page.locator('#region-main').innerText()).includes('QA teacher'));
        assert.equal(await student.page.getByRole('button', {name: 'Create mock content', exact: true}).count(), 0);
        const report = fixture('report');
        assert(report.views >= 3, 'Native Page view events must be recorded for the student');
        for (const item of [...results, teacherResult]) {
            assert.equal(report.publications.filter(row => row.requestid === item.requestid).length, 1);
        }
        writeFileSync(join(artifacts, 'report.json'), JSON.stringify({results, views: report.views,
            checks: ['3 JSON cases', 'HTTP trace', 'mock replay', 'publication replay', 'CSRF',
                'teacher isolation', 'student access', 'native Page view events', 'no service secrets in HTML']}, null, 2));
        fixture('cleanup');
        console.log('PASS permissions, student view events and temporary account cleanup');
        console.log('COURSE', base + '/course/view.php?id=' + data.courseid);
    } catch (error) {
        for (const [index, context] of browser.contexts().entries()) {
            for (const [pageindex, page] of context.pages().entries()) {
                await page.screenshot({path: join(artifacts, `failure-${index}-${pageindex}.png`), fullPage: true}).catch(() => {});
                console.error('FAILED PAGE', page.url(), (await page.locator('#region-main').innerText().catch(() => '')).slice(0, 5000));
            }
        }
        throw error;
    } finally {
        await browser.close();
    }
}
main().catch(error => {console.error(error); process.exitCode = 1;});
