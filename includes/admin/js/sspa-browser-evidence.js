/* Browser response evidence. Only hashes, named signals and bounded counters leave the browser. */
window.SSPABrowserEvidence = (function () {
    'use strict';
    function md5(bytes) {
        var length = bytes.length, words = [], i, j, a = 0x67452301, b = -0x10325477, c = -0x67452302, d = 0x10325476;
        for (i = 0; i < length; i++) words[i >> 2] = (words[i >> 2] || 0) | bytes.charCodeAt(i) << ((i % 4) * 8);
        words[length >> 2] = (words[length >> 2] || 0) | 0x80 << ((length % 4) * 8);
        var blocks = (((length + 8) >> 6) + 1) * 16;
        words[blocks - 2] = length * 8;
        words[blocks - 1] = 0;
        var shifts = [7,12,17,22,5,9,14,20,4,11,16,23,6,10,15,21];
        for (i = 0; i < blocks; i += 16) {
            var aa = a, bb = b, cc = c, dd = d;
            for (j = 0; j < 64; j++) {
                var round = j >> 4, f, g;
                if (round === 0) { f = (b & c) | (~b & d); g = j; }
                else if (round === 1) { f = (d & b) | (~d & c); g = (5 * j + 1) % 16; }
                else if (round === 2) { f = b ^ c ^ d; g = (3 * j + 5) % 16; }
                else { f = c ^ (b | ~d); g = (7 * j) % 16; }
                var sum = (a + f + (words[i + g] || 0) + (Math.floor(Math.abs(Math.sin(j + 1)) * 4294967296) | 0)) | 0;
                var shift = shifts[round * 4 + j % 4], next = (b + ((sum << shift) | (sum >>> (32 - shift)))) | 0;
                a = d; d = c; c = b; b = next;
            }
            a = (a + aa) | 0; b = (b + bb) | 0; c = (c + cc) | 0; d = (d + dd) | 0;
        }
        return [a,b,c,d].map(function (word) { var out = ''; for (var k = 0; k < 4; k++) out += ('0' + ((word >>> (k * 8)) & 255).toString(16)).slice(-2); return out; }).join('');
    }
    function bytes(text) { return Array.from(new TextEncoder().encode(text), function (value) { return String.fromCharCode(value); }).join(''); }
    function hash(body, headers) {
        if (!body) return null;
        var meaningful = ['content-type','location','x-redirect-by','x-frame-options','x-content-type-options','referrer-policy','permissions-policy','strict-transport-security','content-security-policy','content-security-policy-report-only','x-robots-tag','access-control-allow-origin','access-control-allow-methods','access-control-allow-headers','access-control-allow-credentials','access-control-expose-headers','cross-origin-opener-policy','cross-origin-embedder-policy','cross-origin-resource-policy','x-permitted-cross-domain-policies','link'];
        var lines = Object.keys(headers).filter(function (key) { return meaningful.indexOf(key) !== -1; }).map(function (key) {
            var value = headers[key];
            if (key.indexOf('content-security-policy') === 0) value = value.replace(/'nonce-[^']*'/g, "'nonce-0'");
            if (key === 'link') value = value.replace(/([?&;]sspa_nc=)[a-f0-9]+/gi, '$10');
            return key + ': ' + value;
        }).sort().join('\n') + '\n\n';
        var normal = body.replace(/<!--[\s\S]*?-->/g, '')
            .replace(/([?&;]_wpnonce=)[a-f0-9]{6,12}/gi, '$10')
            .replace(/(name=["'][^"']*nonce[^"']*["'][^>]{0,80}value=["'])[a-f0-9]{6,12}(["'])/gi, '$10$2')
            .replace(/(value=["'])[a-f0-9]{6,12}(["'][^>]{0,80}name=["'][^"']*nonce[^"']*["'])/gi, '$10$2')
            .replace(/(["'][a-z0-9_-]*nonce["']\s*[:=]\s*["'])[a-f0-9]{6,12}(["'])/gi, '$10$2')
            .replace(/(\snonce=["'])[^"']*(["'])/gi, '$10$2')
            .replace(/([?&;]sspa_nc=)[a-f0-9]+/gi, '$10')
            .replace(/([?&;](?:t|ts|time|_)=)\d{10,13}/g, '$10');
        return md5(bytes(lines) + normal);
    }
    function derive(text, headers) {
        // Match the previous transport's JS character cap followed by PHP's byte cap.
        var body = bytes(text.substring(0, 20000)).substring(0, 20000), evidence = {}, regions = {};
        function matches(regex) { return Array.from(body.matchAll(regex)); }
        function nonce(name, context, container) {
            name = name.toLowerCase().replace(/[^a-z0-9_\-]/g, '');
            if (!name) return;
            if (!evidence[name]) evidence[name] = {name: name, occurrences: 0, contexts: [], containers: []};
            var row = evidence[name]; row.occurrences++;
            if (row.contexts.indexOf(context) === -1) row.contexts.push(context);
            if (row.containers.indexOf(container) === -1 && row.containers.length < 10) row.containers.push(container);
        }
        matches(/<input[^>]+name=["']([^"']*nonce[^"']*)["']/gi).forEach(function (m) { nonce(m[1], 'input_field', 'input_field'); });
        matches(/[?&;]((?:_wp)?[a-z0-9_\-]*nonce[a-z0-9_\-]*)=[a-f0-9]{8,12}\b/gi).forEach(function (m) { nonce(m[1], 'url_parameter', 'url_parameter'); });
        matches(/data-([a-z0-9_\-]*nonce[a-z0-9_\-]*)=/gi).forEach(function (m) { nonce(m[1], 'data_attribute', 'data_attribute'); });
        matches(/<script\b([^>]*)>([\s\S]*?)<\/script>/gi).slice(0,500).forEach(function (script) {
            var id = /\bid=["']([^"']+)["']/i.exec(script[1]);
            Array.from(script[2].matchAll(/["']([a-z0-9_\-]*nonce[a-z0-9_\-]*)["']\s*[:=]\s*["'][a-f0-9]{8,12}["']/gi)).forEach(function (m) { nonce(m[1], 'script_data', id ? 'script#' + id[1].replace(/%[a-f0-9]{2}/gi,'').replace(/[^A-Za-z0-9_\-]/g,'') : 'inline_script'); });
        });
        matches(/data-ssap-visitor\s*=\s*["']([a-z0-9_-]+)["']/gi).forEach(function (m) { var id = m[1].toLowerCase(); regions[id] = (regions[id] || 0) + 1; });
        var auth = {in:0,out:0}; matches(/data-ssap-auth\s*=\s*["'](in|out)["']/gi).forEach(function (m) { auth[m[1].toLowerCase()]++; });
        var access = 'document\\.cookie|[.]cookie\\b|getCookie\\s*\\(|readCookie\\s*\\(|Cookies\\.get\\s*\\(|\\$\\.cookie\\s*\\(';
        var legacy = ['wordpress_logged_in','woocommerce_items_in_cart','woocommerce_cart_hash'].filter(function (name) { return new RegExp('(?:' + access + ')[^;<]{0,140}' + name + '|' + name + '[^;<]{0,140}(?:' + access + ')', 'is').test(body); });
        var attrs = matches(/<(?:a|form|div|section|aside|button)\b[^>]*(?:href|action|class|id|data-[a-z0-9_-]+)\s*=\s*["'][^"']+["'][^>]*>/gi).slice(0,1000).map(function (m) { return m[0]; }).join('\n');
        var hints = {wishlist:'wish(?:list| list)',shortlist:'shortlist',saved_search:'saved[-_ ]?(?:search|job|item)',loyalty:'loyalty|reward[-_ ]?(?:point|balance)',quote:'quote[-_ ]?(?:basket|cart|request)|request[-_ ]?a[-_ ]?quote',booking:'booking|appointment',downloads:'customer[-_ ]?download|my[-_ ]?download',subscription:'my[-_ ]?subscription|subscription[-_ ]?(?:account|manage)'};
        return {schema:1, body_hash: hash(body, headers), wordfence: /wordfence/i.test(body), bytes_scanned: body.length,
            nonce_evidence: Object.keys(evidence).slice(0,30).map(function (name) { return evidence[name]; }),
            legacy_cookie_reads: legacy, client_state_reads: {cookie:matches(/document\.cookie|Cookies\.get\s*\(|getCookie\s*\(/gi).length, local_storage:matches(/\blocalStorage\b/gi).length, session_storage:matches(/\bsessionStorage\b/gi).length},
            coverage:{type_a_in:auth.in,type_a_out:auth.out,type_b_regions:regions,cart_fragment_markers:matches(/data-ssap-frag\s*=|class=["'][^"']*ssap-frag/gi).length},
            private_surface_hints:Object.keys(hints).filter(function (name) { return new RegExp(hints[name],'i').test(attrs); }), forms:matches(/<form\b/gi).length};
    }
    return {derive: derive};
})();
