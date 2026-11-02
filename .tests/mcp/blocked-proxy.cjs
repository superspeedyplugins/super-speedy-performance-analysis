// A loopback-blocked site without touching nginx: two unprivileged listeners on port 8082 in
// front of the parallel-dev vhost (127.0.0.1:8081).
//
//   edge    [::1]:8082      a WAF rule: refuses any request whose User-Agent starts with
//                           "WordPress/" (the HTTP API's default, which PA's loopbacks send)
//                           with HTTP 403; everything else is forwarded to the origin.
//   origin  127.0.0.1:8082  optional HTTP basic auth (credentials read from SSPA_PROXY_AUTH_FILE
//                           on every request, "user:password", absent = no auth), exempting only
//                           the Abilities REST API so the agent's Application Password reaches it;
//                           then forwarded unchanged (Host included) to nginx.
//
// libcurl resolves *.localhost itself and connects to [::1] first, so PA's public-route loopback
// meets the edge and is refused, while 127.0.0.1 is the origin: exactly a site behind a CDN or
// firewall that blocks its own server. The test client resolves to 127.0.0.1, as a visitor whose
// traffic the edge passes.
//
// Started and stopped by .tests/mcp/run-mcp-blocked.sh. Run it by hand to open the site after a
// test: node .tests/mcp/blocked-proxy.cjs (Ctrl-C stops it).
const http = require('node:http');
const fs = require('node:fs');

const PORT = Number(process.env.SSPA_PROXY_PORT || 8082);
const UPSTREAM_PORT = Number(process.env.SSPA_PROXY_UPSTREAM_PORT || 8081);
const AUTH_FILE = process.env.SSPA_PROXY_AUTH_FILE || '';
const LOG = process.env.SSPA_PROXY_LOG || '';

function log(line) {
    if (LOG) fs.appendFileSync(LOG, new Date().toISOString() + ' ' + line + '\n');
}

function forward(req, res, port, label) {
    const upstream = http.request({
        host: '127.0.0.1',
        port,
        method: req.method,
        path: req.url,
        headers: req.headers,
    }, up => {
        res.writeHead(up.statusCode, up.rawHeaders);
        up.pipe(res);
    });
    upstream.on('error', error => {
        log(`${label} upstream error ${error.message}`);
        if (!res.headersSent) res.writeHead(502, { 'Content-Type': 'text/plain' });
        res.end('upstream error');
    });
    req.pipe(upstream);
}

function expectedAuth() {
    if (!AUTH_FILE || !fs.existsSync(AUTH_FILE)) return '';
    const pair = fs.readFileSync(AUTH_FILE, 'utf8').trim();
    return pair ? 'Basic ' + Buffer.from(pair).toString('base64') : '';
}

function isAbilitiesApi(url) {
    const parsed = new URL(url, 'http://x');
    return parsed.pathname.startsWith('/wp-json/wp-abilities/')
        || (parsed.searchParams.get('rest_route') || '').startsWith('/wp-abilities/');
}

const edge = http.createServer((req, res) => {
    const ua = String(req.headers['user-agent'] || '');
    if (/^WordPress\//.test(ua)) {
        log(`edge 403 ${req.method} ${req.url} ua=${ua.slice(0, 40)}`);
        res.writeHead(403, { 'Content-Type': 'text/html', 'Server': 'sspa-test-edge' });
        res.end('<html><body><h1>403 Forbidden</h1><p>Request blocked by the edge firewall.</p></body></html>');
        return;
    }
    forward(req, res, PORT, 'edge');
});

const origin = http.createServer((req, res) => {
    const expected = expectedAuth();
    if (expected && req.headers.authorization !== expected && !isAbilitiesApi(req.url)) {
        log(`origin 401 ${req.method} ${req.url}`);
        res.writeHead(401, { 'WWW-Authenticate': 'Basic realm="staging"', 'Content-Type': 'text/plain' });
        res.end('401 Authorization Required');
        return;
    }
    log(`origin pass ${req.method} ${req.url}`);
    forward(req, res, UPSTREAM_PORT, 'origin');
});

let ready = 0;
function listening() {
    ready++;
    if (ready === 2) console.log(`READY edge [::1]:${PORT} origin 127.0.0.1:${PORT} -> 127.0.0.1:${UPSTREAM_PORT}`);
}
for (const [server, host] of [[edge, '::1'], [origin, '127.0.0.1']]) {
    server.on('error', error => { console.error(`listen ${host}:${PORT} failed: ${error.message}`); process.exit(1); });
    server.listen(PORT, host, listening);
}
for (const signal of ['SIGTERM', 'SIGINT']) {
    process.on(signal, () => { edge.close(); origin.close(); process.exit(0); });
}
