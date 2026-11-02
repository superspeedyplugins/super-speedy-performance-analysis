// Full MCP control, end to end, through the same Abilities REST endpoints the MCP connection
// uses: an Application Password, no browser, WP-Cron disabled. Every step is a real ability
// call against the real site; nothing is read from the database behind the agent's back.
const assert = require('node:assert/strict');
const fs = require('node:fs');

const site = process.env.SSPA_E2E_URL;
const out = process.env.SSPA_E2E_OUT;
assert.match(new URL(site).hostname, /^tests[-.].*\.localhost$/);
const auth = 'Basic ' + Buffer.from(fs.readFileSync(process.env.SSPA_MCP_SECRET_FILE, 'utf8').trim()).toString('base64');
const evidence = {};

function query(input, prefix = 'input') {
    const parts = [];
    for (const [key, value] of Object.entries(input || {})) {
        const name = `${prefix}[${key}]`;
        if (Array.isArray(value)) value.forEach(v => parts.push(`${encodeURIComponent(name + '[]')}=${encodeURIComponent(v)}`));
        else if (value && typeof value === 'object') parts.push(query(value, name));
        else parts.push(`${encodeURIComponent(name)}=${encodeURIComponent(typeof value === 'boolean' ? (value ? '1' : '0') : value)}`);
    }
    return parts.filter(Boolean).join('&');
}

// Readonly abilities are GET, the rest POST, exactly as the core REST run controller requires.
async function call(name, input = {}, { read = false, expectError = null, as = auth } = {}) {
    const base = `${site}/wp-json/wp-abilities/v1/abilities/sspa/${name}/run`;
    const qs = read ? query(input) : '';
    const response = await request(read ? base + (qs ? '?' + qs : '') : base, {
        method: read ? 'GET' : 'POST',
        headers: { Authorization: as, 'Content-Type': 'application/json' },
        body: read ? undefined : JSON.stringify({ input }),
    });
    const body = await response.json().catch(() => null);
    if (expectError) {
        assert.ok(!response.ok, `${name} must be refused (${expectError}), got ${response.status}`);
        assert.equal(body && body.code, expectError, `${name} refusal code`);
        return body;
    }
    assert.ok(response.ok, `${name} answered ${response.status}: ${JSON.stringify(body).slice(0, 300)}`);
    evidence[name] = body;
    return body;
}

async function drive(label, maxCalls = 120) {
    for (let i = 0; i < maxCalls; i++) {
        const step = await call('advance-run', {});
        if (!step.active) {
            assert.ok(step.run === null || step.run.status === 'done', `${label} ended ${step.run && step.run.status}`);
            return step;
        }
    }
    assert.fail(`${label} still running after ${maxCalls} advance-run calls`);
}

const http = require('node:http');

// Node's resolver does not map *.localhost to loopback the way curl and browsers do, so
// requests to the parallel-dev site resolve it explicitly. Nothing else differs from fetch.
function request(url, { method = 'GET', headers = {}, body } = {}) {
    return new Promise((resolve, reject) => {
        const target = new URL(url);
        const req = http.request({
            hostname: target.hostname,
            port: target.port || 80,
            path: target.pathname + target.search,
            method,
            headers: body ? { ...headers, 'Content-Length': Buffer.byteLength(body) } : headers,
            lookup: (host, options, callback) => options && options.all
                ? callback(null, [{ address: '127.0.0.1', family: 4 }])
                : callback(null, '127.0.0.1', 4),
        }, res => {
            let data = '';
            res.setEncoding('utf8');
            res.on('data', chunk => { data += chunk; });
            res.on('end', () => resolve({
                ok: res.statusCode >= 200 && res.statusCode < 300,
                status: res.statusCode,
                json: async () => JSON.parse(data),
            }));
        });
        req.setTimeout(120000, () => req.destroy(new Error('request timed out: ' + url)));
        req.on('error', reject);
        if (body) req.write(body);
        req.end();
    });
}

const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));

(async () => {
    let failed = false;
    try {
        // 1. Discovery.
        let caps = await call('get-capabilities', {}, { read: true });
        assert.ok(caps.abilities.length >= 56, 'discovery lists every ability');
        assert.equal(caps.prerequisites.profiler.enabled, false, 'entry state: profiler off');
        assert.equal(caps.prerequisites.wp_cron.disabled, true, 'entry state: WP-Cron disabled');
        assert.ok(caps.page_catalogue.some(p => p.page_key === 'shop'), 'the catalogue offers shop');
        assert.ok(caps.notes.some(n => /set-profiler/.test(n)), 'notes say to enable the profiler');
        console.log('PASS get-capabilities: ' + caps.abilities.length + ' abilities, profiler off, WP-Cron disabled');

        // 2. The profiler, gated.
        await call('set-profiler', { enabled: true }, { expectError: 'sspa_confirm_required' });
        const profiler = await call('set-profiler', { enabled: true, confirm: true });
        assert.equal(profiler.enabled, true);
        console.log('PASS set-profiler refuses without confirm and enables with it');

        // 3. A run started over MCP does not move on its own with WP-Cron disabled.
        const started = await call('run-analysis', { type: 'spot', pages: ['home', 'shop', 'product-cat', 'product-single'] });
        await sleep(15000);
        const idle = await call('get-status', {}, { read: true });
        assert.equal(idle.run && idle.run.done, 0, 'with WP-Cron disabled, polling alone does not progress the run');
        console.log('PASS run ' + started.run_id + ' did not move in 15 s of polling (WP-Cron disabled)');
        await drive('analysis');
        console.log('PASS advance-run drove the analysis to done');

        // 4. Runs, page profiles and the Plugins table.
        const runs = await call('list-runs', {}, { read: true });
        assert.ok(runs.runs.some(r => r.run_id === started.run_id && r.status === 'done'), 'list-runs shows the run');
        const run = await call('get-run', { run_id: started.run_id }, { read: true });
        assert.ok(run.pages.some(p => p.page_key === 'shop'), 'get-run lists its pages');
        const profiles = await call('list-page-profiles', {}, { read: true });
        const shop = profiles.profiles.find(p => p.page_key === 'shop');
        assert.ok(shop && shop.profile_id > 0, 'list-page-profiles gives the shop profile id');
        const profile = await call('get-page-profile', { profile_id: shop.profile_id }, { read: true });
        assert.match(JSON.stringify(profile), /zz-slow-shop/, 'the page profile names zz-slow-shop');
        assert.match(JSON.stringify(profile), /SLEEP\(0\.12\)/, 'the page profile carries the slow query');
        const byKey = await call('get-page-profile', { page_key: 'shop' }, { read: true });
        assert.equal(JSON.stringify(byKey).length, JSON.stringify(profile).length, 'page_key resolves to the same profile');
        const table = await call('get-plugins-table', { page: 'shop' }, { read: true });
        const row = table.components.find(c => c.component === 'zz-slow-shop');
        assert.equal(table.page, 'shop');
        assert.ok(row && row.sql_ms >= 100, 'the Plugins table charges zz-slow-shop ' + (row && row.sql_ms) + ' ms of SQL on shop');
        console.log('PASS list-runs, get-run, list-page-profiles, get-page-profile and get-plugins-table read the analysis');

        // 5. Plugin Impact Analysis.
        const plan = await call('get-impact-plan', {}, { read: true });
        assert.ok(plan.plugins.some(p => p.slug === 'zz-slow-shop'), 'the impact plan offers zz-slow-shop');
        await call('run-deep-analysis', { suspects: ['zz-slow-shop'], cache_modes: false });
        await drive('impact analysis');
        const detail = await call('get-plugin-impact-detail', { plugin: 'zz-slow-shop' }, { read: true });
        const shopImpact = detail.rows.find(r => r.page_key === 'shop');
        assert.ok(shopImpact && Number(shopImpact.delta_ttfb_ms) > 60, 'zz-slow-shop measured on shop: ' + (shopImpact && shopImpact.delta_ttfb_ms));
        const measured = (await call('get-plugins-table', {}, { read: true })).components.find(c => c.component === 'zz-slow-shop');
        assert.equal(measured.measured_impact.state, 'adds', 'the Plugins table verdict says it adds time');
        console.log('PASS impact plan, run-deep-analysis, get-plugin-impact-detail: ' + measured.measured_impact.summary);

        // 6. Any URL.
        const posts = await request(`${site}/wp-json/wp/v2/posts?per_page=1`).then(r => r.json());
        const target = posts[0].link;
        const adhoc = await call('profile-url', { url: target, as: 'anon' });
        await drive('profile-url');
        const adhocProfile = await call('get-page-profile', { url: target }, { read: true });
        assert.ok(adhocProfile && adhocProfile.profile, 'profile-url result readable by URL (' + adhoc.page_key + ')');
        console.log('PASS profile-url measured ' + target + ' and get-page-profile read it back');

        // 7. Cache check without a browser.
        const check = await call('run-cache-check', {});
        let progress;
        for (let i = 0; i < 40; i++) {
            progress = await call('advance-cache-check', { assessment_id: check.assessment_id });
            if (progress.done) break;
        }
        assert.ok(progress.done, 'the cache check completed');
        const cacheReport = await call('get-cache-optimisation-analysis', {}, { read: true });
        assert.equal(cacheReport.schema, 'sspa/cache-optimisation-analysis@3');
        assert.equal(cacheReport.delivery_path_observations.browser.measured, false, 'the browser leg is recorded as not measured');
        assert.ok(cacheReport.page_verdicts.every(v => v.verdict === 'browser_not_measured'));
        console.log('PASS run-cache-check and advance-cache-check completed server-side: ' + progress.page_cache_status);

        // 8. Reports, history and settings.
        const markdown = await call('get-markdown-report', { kind: 'run', id: started.run_id }, { read: true });
        assert.match(JSON.stringify(markdown), /shop/, 'the run Markdown covers shop');
        const settings = await call('get-settings', {}, { read: true });
        assert.equal(settings.profiler_enabled, true);
        const toggled = await call('update-settings', { plugin_update_detection: !settings.plugin_update_detection });
        assert.equal(toggled.plugin_update_detection, !settings.plugin_update_detection, 'update-settings changes a setting');
        await call('update-settings', { plugin_update_detection: settings.plugin_update_detection });
        await call('get-submission-queue', {}, { read: true });
        await call('get-endpoint-profiles', {}, { read: true });
        await call('get-admin-save-targets', { object_type: 'post' }, { read: true });
        await call('get-history-series', { after_run_id: started.run_id }, { read: true });
        console.log('PASS Markdown, settings round trip, submission queue, endpoint profiles, save targets, history series');

        // 9. Run control and the destructive gates.
        await call('run-analysis', { type: 'spot', pages: ['home'] });
        const cancelled = await call('cancel-run', {});
        assert.ok(cancelled.cancelled_run_id > 0);
        const after = await call('get-status', {}, { read: true });
        assert.equal(after.active, false, 'cancel-run stopped the run');
        await call('prune-stored-captures', {}, { expectError: 'sspa_confirm_required' });
        await call('delete-traffic-collection', { collection_id: 1 }, { expectError: 'sspa_confirm_required' });
        await call('cleanup-workflow-objects', {}, { expectError: 'sspa_confirm_required' });
        console.log('PASS cancel-run, and every destructive ability refuses without confirm');

        // 10. AJAX windows, endpoint profiling and the quote request (send refused without
        //     confirm; a confirmed send would contact superspeedyplugins.com).
        const windowA = await call('start-ajax-window', { scenario: 'mcp journey before' });
        assert.ok(windowA.uuid, 'start-ajax-window returns its uuid');
        await call('stop-ajax-window', { uuid: windowA.uuid });
        const windowB = await call('start-ajax-window', { scenario: 'mcp journey after' });
        await call('stop-ajax-window', { uuid: windowB.uuid });
        const windows = await call('get-endpoint-profiles', {}, { read: true });
        assert.ok(windows.windows.some(w => w.uuid === windowA.uuid) && windows.windows.some(w => w.uuid === windowB.uuid), 'both windows listed');
        await call('compare-ajax-windows', { before: windowA.uuid, after: windowB.uuid, name: 'mcp journey' });
        assert.ok(Object.values((await call('get-endpoint-profiles', {}, { read: true })).comparisons).some(c => c.name === 'mcp journey'), 'the named comparison is saved');
        await call('profile-endpoint', { uuid: windowA.uuid, key: 'none', url: site + '/wp-json/' }, { expectError: 'sspa_endpoint_confirm' });
        const draft = await call('prepare-quote-request', { profile_id: shop.profile_id, source: 'page-analysis' });
        assert.match(draft.markdown, /shop/, 'the quote draft carries the page report');
        await call('send-quote-request', { request_uuid: draft.request_uuid, email: 'owner@example.invalid', consent: true }, { expectError: 'sspa_confirm_required' });
        console.log('PASS AJAX windows and comparison, endpoint confirm gate, quote draft and send gate');

        // 11. Capability gates over REST: a subscriber gets nothing.
        const subscriber = 'Basic ' + Buffer.from(fs.readFileSync(process.env.SSPA_MCP_SECRET_FILE + '.subscriber', 'utf8').trim()).toString('base64');
        await call('get-capabilities', {}, { read: true, as: subscriber, expectError: 'rest_ability_cannot_execute' });
        await call('advance-run', {}, { as: subscriber, expectError: 'rest_ability_cannot_execute' });
        await call('set-profiler', { enabled: false }, { as: subscriber, expectError: 'rest_ability_cannot_execute' });
        console.log('PASS a subscriber is refused reads and actions');

        caps = await call('get-capabilities', {}, { read: true });
        assert.equal(caps.prerequisites.profiler.enabled, true);
        assert.equal(caps.prerequisites.active_run, null);
        console.log('PASS discovery reflects the new state');
    } catch (error) {
        failed = true;
        console.error('FAIL ' + (error && error.message ? error.message : error));
    } finally {
        fs.writeFileSync(out + '/abilities.json', JSON.stringify(evidence, null, 1));
    }
    process.exit(failed ? 1 : 0);
})();
