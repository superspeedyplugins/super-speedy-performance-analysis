// MCP over the official adapter endpoint: initialize, tools/list, discover the sspa
// abilities and execute one, with an Application Password - what an MCP client does.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');

const site = process.env.SSPA_E2E_URL;
const out = process.env.SSPA_E2E_OUT;
assert.match(new URL(site).hostname, /^tests[-.].*\.localhost$/);
const auth = 'Basic ' + Buffer.from(fs.readFileSync(process.env.SSPA_MCP_SECRET_FILE, 'utf8').trim()).toString('base64');
const endpoint = site + '/wp-json/mcp/mcp-adapter-default-server';

// Node's resolver does not map *.localhost to loopback as curl and browsers do.
function post(body, session) {
    return new Promise((resolve, reject) => {
        const target = new URL(endpoint);
        const payload = JSON.stringify(body);
        const headers = { Authorization: auth, 'Content-Type': 'application/json', Accept: 'application/json, text/event-stream', 'Content-Length': Buffer.byteLength(payload) };
        if (session) headers['Mcp-Session-Id'] = session;
        const req = http.request({
            hostname: target.hostname, port: target.port || 80, path: target.pathname, method: 'POST', headers,
            lookup: (host, options, callback) => options && options.all ? callback(null, [{ address: '127.0.0.1', family: 4 }]) : callback(null, '127.0.0.1', 4),
        }, res => {
            let data = '';
            res.setEncoding('utf8');
            res.on('data', chunk => { data += chunk; });
            res.on('end', () => {
                let json = null;
                try { json = JSON.parse(data); } catch (e) { /* reported below */ }
                resolve({ status: res.statusCode, session: res.headers['mcp-session-id'], json, raw: data });
            });
        });
        req.setTimeout(120000, () => req.destroy(new Error('request timed out')));
        req.on('error', reject);
        req.write(payload);
        req.end();
    });
}

function toolText(response) {
    const text = response.json && response.json.result && response.json.result.content && response.json.result.content[0] && response.json.result.content[0].text;
    assert.ok(text, 'tool result text: ' + response.raw.slice(0, 300));
    return JSON.parse(text);
}

(async () => {
    let failed = false;
    try {
        const init = await post({ jsonrpc: '2.0', id: 1, method: 'initialize', params: { protocolVersion: '2025-06-18', capabilities: {}, clientInfo: { name: 'sspa-transport-test', version: '1' } } });
        assert.equal(init.status, 200, 'initialize answered ' + init.status + ': ' + init.raw.slice(0, 200));
        assert.ok(init.session, 'the server issues an MCP session id');
        console.log('PASS initialize: session ' + init.session.slice(0, 8) + '…');

        const tools = await post({ jsonrpc: '2.0', id: 2, method: 'tools/list' }, init.session);
        const names = tools.json.result.tools.map(t => t.name);
        assert.ok(names.includes('mcp-adapter-discover-abilities') && names.includes('mcp-adapter-execute-ability'), 'adapter tools listed: ' + names.join(', '));
        console.log('PASS tools/list: ' + names.join(', '));

        const discovered = toolText(await post({ jsonrpc: '2.0', id: 3, method: 'tools/call', params: { name: 'mcp-adapter-discover-abilities', arguments: {} } }, init.session));
        const sspa = discovered.abilities.map(a => a.name).filter(n => n.startsWith('sspa/'));
        fs.writeFileSync(out + '/discovered.json', JSON.stringify(discovered, null, 1));
        assert.ok(sspa.includes('sspa/get-status') && sspa.includes('sspa/run-analysis'), 'discovery lists the sspa abilities (' + sspa.length + ')');
        console.log('PASS discover-abilities lists ' + sspa.length + ' sspa abilities');
        const parameterCheck = ['sspa/run-parameter-check', 'sspa/advance-parameter-check', 'sspa/get-parameter-check'];
        assert.deepEqual(parameterCheck.filter(n => !sspa.includes(n)), [], 'the parameter check abilities are discoverable');
        console.log('PASS the three parameter check abilities are discoverable over MCP');

        const executed = toolText(await post({ jsonrpc: '2.0', id: 4, method: 'tools/call', params: { name: 'mcp-adapter-execute-ability', arguments: { ability_name: 'sspa/get-status', parameters: {} } } }, init.session));
        assert.equal(executed.success, true, 'execute-ability succeeded: ' + JSON.stringify(executed).slice(0, 200));
        assert.ok('active' in executed.data, 'sspa/get-status returned its status');
        console.log('PASS execute-ability ran sspa/get-status: active=' + executed.data.active);

        // Every ability the plugin declares is reachable over MCP, not only over REST.
        const caps = toolText(await post({ jsonrpc: '2.0', id: 5, method: 'tools/call', params: { name: 'mcp-adapter-execute-ability', arguments: { ability_name: 'sspa/get-capabilities', parameters: {} } } }, init.session));
        if (caps.success) {
            const declared = caps.data.abilities;
            const missing = declared.filter(name => !sspa.includes(name));
            assert.deepEqual(missing, [], 'every declared ability is discoverable over MCP');
            assert.equal(declared.length, 60, 'the plugin declares 60 abilities');
            assert.ok(sspa.includes('sspa/profile-admin-save'), 'profile-admin-save is discoverable over MCP');
            console.log('PASS get-capabilities over MCP: all ' + declared.length + ' declared abilities discoverable');
        } else {
            console.log('INFO sspa/get-capabilities is not available in this version');
        }
    } catch (error) {
        failed = true;
        console.error('FAIL ' + (error && error.message ? error.message : error));
    }
    process.exit(failed ? 1 : 0);
})();
