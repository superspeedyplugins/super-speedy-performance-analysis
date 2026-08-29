import test from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync, spawn } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { chromium } from 'playwright';

const observatory = path.resolve(import.meta.dirname, '..');

function createFixture(database) {
  const schema = readFileSync(path.join(observatory, 'schema.sql'), 'utf8');
  const fixture = `
INSERT INTO runs VALUES ('viewer-run','fixture-plugin','manifest','viewer-test',1,'2026-08-29T10:00:00Z','2026-08-29T10:01:00Z','completed');
INSERT INTO sites VALUES ('viewer-run','base','http://base.example','base','6.9','8.3','MariaDB','{"slug":"theme","version":"1","type":"classic"}','[]','base-hash','1.5','recorder','{}','{"release_label":"stable","plugin_ref":"aaaa1111"}');
INSERT INTO sites VALUES ('viewer-run','candidate','http://candidate.example','candidate','6.9','8.3','MariaDB','{"slug":"theme","version":"1","type":"classic"}','[]','candidate-hash','1.6.1','recorder','{}','{"release_label":"feature-branch","plugin_ref":"bbbb2222"}');
INSERT INTO targets VALUES ('viewer-run','base-search','base','search','frontend','shop','/shop','anonymous',NULL,2);
INSERT INTO targets VALUES ('viewer-run','candidate-search','candidate','search','frontend','shop','/shop','anonymous',NULL,2);
INSERT INTO targets VALUES ('viewer-run','candidate-pagination','candidate','pagination','backend','machine-list','/wp-admin/edit.php','administrator',NULL,1);
INSERT INTO run_features VALUES ('viewer-run','search');
INSERT INTO run_features VALUES ('viewer-run','pagination');
INSERT INTO samples VALUES ('base-search-1','viewer-run','base-search',1,120,180,1000,20,200,1,1,0,0,0,1,NULL,'hash-1','2026-08-29T10:00:01Z');
INSERT INTO samples VALUES ('base-search-2','viewer-run','base-search',2,110,170,1000,19,200,1,1,0,0,0,1,NULL,'hash-2','2026-08-29T10:00:02Z');
INSERT INTO samples VALUES ('candidate-search-1','viewer-run','candidate-search',1,180,240,1200,28,500,1,1,1,0,0,0,'fault','hash-3','2026-08-29T10:00:03Z');
INSERT INTO samples VALUES ('candidate-search-2','viewer-run','candidate-search',2,170,230,1100,25,200,1,1,0,0,0,1,NULL,'hash-4','2026-08-29T10:00:04Z');
INSERT INTO samples VALUES ('candidate-pagination-1','viewer-run','candidate-pagination',1,80,120,900,12,200,1,1,0,1,0,1,NULL,'hash-5','2026-08-29T10:00:05Z');
INSERT INTO faults (sample_id,run_id,target_id,kind,severity,fingerprint,message,file,line,repeat_count) VALUES ('candidate-search-1','viewer-run','candidate-search','php','error','fault-one','Deliberate viewer fault','plugin.php',42,1);
`;
  execFileSync('sqlite3', [database], { input: `${schema}\n${fixture}` });
}

async function waitForServer(url, process) {
  for (let attempt = 0; attempt < 60; attempt += 1) {
    if (process.exitCode !== null) throw new Error(`PHP viewer stopped with exit ${process.exitCode}`);
    try {
      const response = await fetch(url);
      if (response.ok) return;
    } catch { /* The server has not bound its socket yet. */ }
    await new Promise((resolve) => setTimeout(resolve, 50));
  }
  throw new Error(`PHP viewer did not start at ${url}`);
}

test('feature drill-down, keys and repeated-request evidence work in a browser', async () => {
  const work = mkdtempSync(path.join(tmpdir(), 'sspa-viewer-'));
  const database = path.join(work, 'viewer.sqlite');
  const port = 18000 + (process.pid % 1000);
  const url = `http://127.0.0.1:${port}/`;
  createFixture(database);
  const php = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', 'viewer'], {
    cwd: observatory,
    env: { ...process.env, SSPA_OBSERVATORY_DATABASE: database },
    stdio: ['ignore', 'ignore', 'pipe'],
  });
  let browser;
  try {
    await waitForServer(url, php);
    browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1400, height: 900 } });
    const browserErrors = [];
    page.on('pageerror', (error) => browserErrors.push(error.message));
    page.on('console', (message) => { if (message.type() === 'error') browserErrors.push(message.text()); });
    await page.goto(url);
    await page.locator('#explorer-title').waitFor();

    assert.equal(await page.locator('#explorer-title').textContent(), 'Feature overview');
    assert.deepEqual(await page.locator('.x-label-button').evaluateAll((items) => items.map((item) => item.dataset.groupId)), ['pagination', 'search']);
    assert.equal(await page.locator('[data-key-type="build"]').count(), 2);
    assert.equal(await page.locator('[data-key-type="state"][data-key-id="error"] small').textContent(), '1');

    await page.locator('.x-label-button[data-group-id="search"]').click();
    assert.equal(await page.locator('#explorer-title').textContent(), 'search');
    assert.equal(await page.locator('.x-label-button').count(), 1, 'the two builds share one stable shop page column');
    assert.equal(await page.locator('.point-hit').count(), 4);

    await page.locator('[data-sample-id="candidate-search-1"]').click();
    assert.equal(await page.locator('.evidence-card').count(), 2, 'all candidate repetitions are listed');
    assert.equal(await page.locator('.evidence-card.selected').getAttribute('data-evidence-sample'), 'candidate-search-1');
    assert.match(await page.locator('.evidence-card.selected').textContent(), /Deliberate viewer fault/);

    await page.locator('[data-sample-id="base-search-1"]').click({ modifiers: [process.platform === 'darwin' ? 'Meta' : 'Control'] });
    assert.equal(await page.locator('.evidence-card').count(), 4, 'additive selection includes both page/build cells');
    assert.equal(await page.locator('.evidence-card.selected').count(), 2);

    await page.locator('#back-to-features').click();
    await page.locator('[data-key-type="state"][data-key-id="error"]').click();
    assert.equal(await page.locator('.point-hit').count(), 1, 'the error key isolates the red square');
    assert.match(page.url(), /pointStateFilter=error/);
    await page.locator('#clear-filters').click();
    await page.locator('.x-label-button[data-group-id="search"]').focus();
    await page.keyboard.press('Enter');
    assert.equal(await page.locator('#explorer-title').textContent(), 'search', 'feature labels work from the keyboard');
    assert.deepEqual(browserErrors, []);
  } finally {
    if (browser) await browser.close();
    php.kill('SIGTERM');
    rmSync(work, { recursive: true, force: true });
  }
});
