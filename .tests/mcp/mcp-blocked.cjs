// MCP on a loopback-blocked site, through the same Abilities REST endpoints the MCP connection
// uses: an Application Password, no browser, WP-Cron disabled. blocked-proxy.cjs refuses PA's own
// requests at the edge (HTTP 403) and, in the second half, demands basic auth at the origin.
// Every step is a real ability call; nothing is read from the database behind the agent's back.
//
// Each check runs and reports on its own, so a run against code without the feature shows every
// reason it fails, not only the first.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');

const site = process.env.SSPA_E2E_URL;
const out = process.env.SSPA_E2E_OUT;
const authFile = process.env.SSPA_PROXY_AUTH_FILE;
assert.match(new URL(site).hostname, /^tests[-.].*\.localhost$/);
assert.equal(new URL(site).port, '8082', 'the blocked site is served through the proxy at :8082');
const auth = 'Basic ' + Buffer.from(fs.readFileSync(process.env.SSPA_MCP_SECRET_FILE, 'utf8').trim()).toString('base64');
const BASIC_USER = 'sspa-staging';
const BASIC_PASSWORD = 'staging-' + Math.random().toString(36).slice(2, 12);
const evidence = {};
let failures = 0;

// Node's resolver does not map *.localhost to loopback the way curl and browsers do; resolve to
// 127.0.0.1, which is the origin listener: the path a visitor's traffic takes once the edge
// passes it.
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
            res.on('end', () => resolve({ ok: res.statusCode >= 200 && res.statusCode < 300, status: res.statusCode, text: data }));
        });
        req.setTimeout(300000, () => req.destroy(new Error('request timed out: ' + url)));
        req.on('error', reject);
        if (body) req.write(body);
        req.end();
    });
}

function query(input, prefix = 'input') {
    const parts = [];
    for (const [key, value] of Object.entries(input || {})) {
        const name = `${prefix}[${key}]`;
        if (value && typeof value === 'object') parts.push(query(value, name));
        else parts.push(`${encodeURIComponent(name)}=${encodeURIComponent(typeof value === 'boolean' ? (value ? '1' : '0') : value)}`);
    }
    return parts.filter(Boolean).join('&');
}

// Readonly abilities are GET, the rest POST, as the core REST run controller requires.
async function raw(name, input = {}, read = false) {
    const base = `${site}/wp-json/wp-abilities/v1/abilities/sspa/${name}/run`;
    const qs = read ? query(input) : '';
    const response = await request(read ? base + (qs ? '?' + qs : '') : base, {
        method: read ? 'GET' : 'POST',
        headers: { Authorization: auth, 'Content-Type': 'application/json' },
        body: read ? undefined : JSON.stringify({ input }),
    });
    let body = null;
    try { body = JSON.parse(response.text); } catch (e) { body = { unparsed: response.text.slice(0, 300) }; }
    return { status: response.status, ok: response.ok, body };
}

async function call(name, input = {}, read = false) {
    const response = await raw(name, input, read);
    assert.ok(response.ok, `${name} answered ${response.status}: ${JSON.stringify(response.body).slice(0, 400)}`);
    evidence[name] = response.body;
    return response.body;
}

async function refused(name, input, code, read = false) {
    const response = await raw(name, input, read);
    assert.ok(!response.ok, `${name} must be refused with ${code}, got ${response.status}: ${JSON.stringify(response.body).slice(0, 300)}`);
    assert.equal(response.body && response.body.code, code, `${name} refusal code (message: ${response.body && response.body.message})`);
    return response.body;
}

async function drive(label, maxCalls = 60) {
    for (let i = 0; i < maxCalls; i++) {
        const step = await call('advance-run', {});
        if (!step.active) {
            assert.ok(step.run === null || step.run.status === 'done', `${label} ended ${step.run && step.run.status}`);
            return step;
        }
    }
    assert.fail(`${label} still running after ${maxCalls} advance-run calls`);
}

async function check(label, fn) {
    try {
        const detail = await fn();
        console.log('PASS ' + label + (detail ? ': ' + detail : ''));
        return true;
    } catch (error) {
        failures++;
        console.log('FAIL ' + label + ': ' + (error && error.message ? error.message : error));
        // Leave nothing running for the next check.
        await raw('cancel-run', {}).catch(() => {});
        return false;
    }
}

async function analyseHome(label) {
    const started = await call('run-analysis', { type: 'spot', pages: ['home'] });
    await drive(label);
    const run = await call('get-run', { run_id: started.run_id }, true);
    assert.equal(run.run.status, 'done', 'run done');
    const page = await call('get-page-profile', { page_key: 'home' }, true);
    fs.writeFileSync(`${out}/${label}-home-profile.json`, JSON.stringify(page, null, 1));
    const code = page.response_code ?? (page.profile && page.profile.response_code);
    assert.equal(Number(code), 200, 'the home page was measured with HTTP 200: ' + JSON.stringify(page).slice(0, 300));
    return 'run ' + started.run_id + ' measured home with HTTP 200';
}

async function adminSave(objectType, transport, expectedCode) {
    const targets = await call('get-admin-save-targets', { object_type: objectType }, true);
    const offered = (targets.transports || []).map(t => t.key);
    assert.ok(offered.includes(transport), `${objectType} offers the ${transport} transport (${offered.join(', ')})`);
    const result = await call('profile-admin-save', { object_type: objectType, transport });
    fs.writeFileSync(`${out}/admin-save-${objectType}-${transport}.json`, JSON.stringify(result, null, 1));
    assert.equal(result.transport, transport);
    assert.equal(result.response_code, expectedCode, 'the editor save answered ' + expectedCode);
    assert.equal(result.temporary_object_removed, true, 'the temporary object was removed');
    assert.ok(result.profile_id > 0 && result.run_id > 0, 'a profile and run were stored');
    const profile = await call('get-page-profile', { profile_id: result.profile_id }, true);
    fs.writeFileSync(`${out}/admin-save-${objectType}-${transport}-profile.json`, JSON.stringify(profile, null, 1));
    assert.equal(profile.page_key || (profile.profile && profile.profile.page_key), result.page_key, 'get-page-profile reads the save profile');
    assert.equal(result.page_key, `admin-save-${objectType}-${transport}`);
    const run = await call('get-run', { run_id: result.run_id }, true);
    assert.equal(run.run.status, 'done', 'the save run completed');
    const status = await call('get-status', {}, true);
    assert.ok(!status.active, 'no run left active');
    return `${result.page_key} profile ${result.profile_id}, HTTP ${result.response_code}, save ${result.wall_ms} ms, editor load ${result.editor_load_ms} ms`;
}

(async () => {
    try {
        fs.rmSync(authFile, { force: true });

        // ---- Part 1: the edge refuses PA's own requests; the origin is open. ----
        await check('get-capabilities reports loopback direct to 127.0.0.1 because the edge refuses the public route', async () => {
            const caps = await call('get-capabilities', {}, true);
            const loopback = caps.prerequisites && caps.prerequisites.loopback;
            assert.ok(loopback, 'prerequisites.loopback exists: ' + JSON.stringify(Object.keys(caps.prerequisites || {})));
            assert.equal(loopback.healthy, true, 'loopback healthy: ' + loopback.reason);
            assert.equal(loopback.transport, 'loopback');
            assert.equal(loopback.route, 'direct');
            assert.equal(loopback.origin_address, '127.0.0.1');
            assert.equal(loopback.attempts[0].route, 'public');
            assert.equal(loopback.attempts[0].healthy, false);
            assert.match(loopback.attempts[0].reason, /403/, 'the public route failed with the edge\'s 403');
            assert.ok(caps.abilities.includes('sspa/profile-admin-save'), 'profile-admin-save is listed');
            assert.ok(caps.workflows.some(w => w.name === 'editor save'), 'the editor save workflow is listed');
            return loopback.reason;
        });
        await check('run-analysis measures the home page over the direct route', () => analyseHome('direct'));
        await check('profile-admin-save profiles a block editor (REST) save of a post', () => adminSave('post', 'rest', 200));

        // ---- Part 2: the origin demands basic auth as well. ----
        fs.writeFileSync(authFile, `${BASIC_USER}:${BASIC_PASSWORD}`);
        await check('without credentials, run-analysis is refused with the origin\'s 401 named in the reason', async () => {
            const settings = await call('get-settings', {}, true);
            assert.equal(settings.loopback_credentials && settings.loopback_credentials.set, false, 'no credentials stored yet');
            const body = await refused('run-analysis', { type: 'spot', pages: ['home'] }, 'sspa_loopback_blocked');
            assert.match(body.message, /401/, 'the reason names HTTP 401');
            assert.match(body.message, /403/, 'the reason names the public route\'s 403');
            return body.message;
        });
        await check('update-settings stores loopback credentials and get-settings reports them only as set', async () => {
            await refused('update-settings', { loopback_credentials: { user: 'a:b', password: 'x' } }, 'sspa_loopback_credentials');
            await refused('update-settings', { loopback_origin: 'not-an-address' }, 'sspa_loopback_origin');
            const updated = await call('update-settings', { loopback_credentials: { user: BASIC_USER, password: BASIC_PASSWORD } });
            assert.deepEqual(updated.loopback_credentials, { set: true, user_set: true });
            const settings = await call('get-settings', {}, true);
            const text = JSON.stringify(settings);
            assert.deepEqual(settings.loopback_credentials, { set: true, user_set: true });
            assert.ok(!text.includes(BASIC_PASSWORD) && !text.includes(BASIC_USER), 'neither the user nor the password is returned');
            const caps = await call('get-capabilities', {}, true);
            assert.ok(!JSON.stringify(caps).includes(BASIC_PASSWORD), 'get-capabilities does not return the password');
            assert.equal(caps.prerequisites.loopback.credentials_set, true);
            assert.equal(caps.prerequisites.loopback.healthy, true, caps.prerequisites.loopback.reason);
            assert.equal(caps.prerequisites.loopback.route, 'direct');
            return 'loopback_credentials ' + JSON.stringify(settings.loopback_credentials) + ', route direct';
        });
        await check('with credentials, run-analysis measures the home page through basic auth', () => analyseHome('basic-auth'));
        await check('profile-admin-save profiles a block editor (REST) save of a post through basic auth', () => adminSave('post', 'rest', 200));
        await check('profile-admin-save profiles a classic editor form save of a product through basic auth', () => adminSave('product', 'classic', 302));
        await check('loopback_origin can be set, is reported, and is tried first', async () => {
            const updated = await call('update-settings', { loopback_origin: '127.0.0.1:8082' });
            assert.equal(updated.loopback_origin, '127.0.0.1:8082');
            const caps = await call('get-capabilities', {}, true);
            assert.equal(caps.prerequisites.loopback.origin_address, '127.0.0.1:8082', caps.prerequisites.loopback.reason);
            const cleared = await call('update-settings', { loopback_origin: '' });
            assert.equal(cleared.loopback_origin, '');
            return 'origin_address 127.0.0.1:8082 chosen first, then cleared';
        });
    } catch (error) {
        failures++;
        console.log('FAIL ' + (error && error.message ? error.message : error));
    }
    fs.writeFileSync(`${out}/evidence.json`, JSON.stringify(evidence, null, 1));
    console.log(failures ? `${failures} check(s) failed` : 'All checks passed');
    process.exit(failures ? 1 : 0);
})();
