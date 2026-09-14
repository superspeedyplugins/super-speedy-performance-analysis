// Issue 9. The Observatory prepares its retained site on both supported hosts, discovering the
// parallel-dev sites root from the shared environment instead of assuming one machine's
// layout, and refuses to mutate anything until every prerequisite is present, with one
// actionable message naming the missing thing.
import test from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import path from 'node:path';

const moduleUrl = new URL('../src/common.js', import.meta.url);
const workspace = path.resolve(new URL('../../../..', import.meta.url).pathname);

function evaluate(code, env = {}) {
  const cleaned = { ...process.env, ...env };
  for (const [key, value] of Object.entries(env)) if (value === undefined) delete cleaned[key];
  return execFileSync(process.execPath, ['--input-type=module', '-e', code], { encoding: 'utf8', env: cleaned, cwd: path.dirname(new URL(import.meta.url).pathname) }).trim();
}

test('the sites root is discovered from the shared parallel-dev environment, not a hard-coded host layout', () => {
  const expected = execFileSync('bash', ['-c', 'source "$1"; printf "%s" "$SITES_ROOT"', 'sites-root', path.join(workspace, 'tools/parallel-dev/bin/lib.sh')], { encoding: 'utf8', env: { ...process.env, PD_SITES_ROOT: '' } }).trim();
  assert.ok(expected.startsWith('/'), `lib.sh must report an absolute SITES_ROOT (got ${JSON.stringify(expected)})`);
  const discovered = evaluate(
    `import {siteDirectory} from ${JSON.stringify(moduleUrl.href)}; console.log(siteDirectory('scalability-pro', 'tests-e2e'));`,
    { PD_SITES_ROOT: undefined },
  );
  assert.equal(discovered, path.join(expected, 'scalability-pro', 'tests-e2e'));
});

test('an explicit PD_SITES_ROOT still wins', () => {
  const discovered = evaluate(
    `import {siteDirectory} from ${JSON.stringify(moduleUrl.href)}; console.log(siteDirectory('p', 's'));`,
    { PD_SITES_ROOT: '/tmp/observatory-root-override' },
  );
  assert.equal(discovered, '/tmp/observatory-root-override/p/s');
});

test('prerequisites pass on a prepared host and each failure names the missing thing', () => {
  const probe = (overrides) => evaluate(
    `import {checkPrerequisites} from ${JSON.stringify(moduleUrl.href)};
     try { checkPrerequisites(${JSON.stringify(overrides)}); console.log('ok'); }
     catch (error) { console.log('error: ' + error.message); }`,
  );
  assert.equal(probe({}), 'ok');
  assert.match(probe({ nodeVersion: 'v18.20.0' }), /^error: .*Node\.js 20/);
  assert.match(probe({ path: '/nonexistent' }), /^error: .*sqlite3/);
  assert.match(probe({ browser: '/nonexistent/chromium' }), /^error: .*(Chromium|browser)/i);
  assert.match(probe({ packageDir: '/nonexistent' }), /^error: .*(dependencies|npm install)/i);
});
