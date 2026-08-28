import { createHmac, randomBytes } from 'node:crypto';
import { readFileSync, existsSync } from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';
import { parse as parseEnv } from 'dotenv';
import {
  dataDir, hash, initialiseDatabase, loadManifest, recorderConfig, sleep, sql, sqlite,
  siteDirectory, workspaceRoot, wp,
} from './common.js';

const RUNNER_VERSION = '0.1.0';
const { manifest, source } = loadManifest();
const defaults = { warmups: 2, repetitions: 7, timeout_ms: 30000, ...(manifest.defaults || {}) };
const runId = `${Date.now().toString(36)}${randomBytes(4).toString('hex')}`;
const seed = Number.parseInt(hash(runId).slice(0, 8), 16);
const env = parseEnv(readFileSync(path.join(workspaceRoot, 'workspace.env')));
const adminUser = env.PD_ADMIN_USER || 'dave';
const adminPass = env.PD_ADMIN_PASS;
if (!adminPass) throw new Error('PD_ADMIN_PASS is missing from workspace.env');

initialiseDatabase();
sqlite(`UPDATE runs SET status='aborted',finished_at=${sql(new Date().toISOString())} WHERE status='running';`);
sqlite(`INSERT INTO runs VALUES (${sql(runId)},${sql(manifest.plugin)},${sql(hash(source))},${sql(RUNNER_VERSION)},${seed},${sql(new Date().toISOString())},NULL,'running');`);

function wpValue(site, args) {
  try { return wp(site, args, { plugin: site.plugin_slug || manifest.plugin, quiet: true }); } catch { return ''; }
}

for (const site of manifest.sites) {
  const plugins = JSON.parse(wpValue(site, ['plugin', 'list', '--status=active', '--fields=name,version', '--format=json']) || '[]');
  const pluginSlug = site.plugin_slug || manifest.plugin;
  const config = recorderConfig(pluginSlug, site);
  const activeThemes = JSON.parse(wpValue(site, ['theme', 'list', '--status=active', '--fields=name,version', '--format=json']) || '[]');
  const activeTheme = activeThemes[0] || { name: '', version: '' };
  const themeDirectory = path.join(siteDirectory(pluginSlug, site.scenario), 'wp-content/themes', activeTheme.name || '');
  const hasThemeJson = activeTheme.name ? existsSync(path.join(themeDirectory, 'theme.json')) : false;
  const hasBlockTemplates = activeTheme.name ? existsSync(path.join(themeDirectory, 'templates/index.html')) : false;
  const themeEvidence = {
    slug: activeTheme.name || '',
    version: activeTheme.version || '',
    type: hasBlockTemplates ? 'block_fse' : hasThemeJson ? 'hybrid' : 'classic',
  };
  const countsJson = wpValue(site, ['eval', `
    global $wpdb;
    $posts = array();
    foreach (array('product','product_variation','post','page','shop_order') as $type) {
      $posts[$type] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", $type));
    }
    $orders_table = $wpdb->prefix . 'wc_orders';
    $hpos_orders = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s", $orders_table))
      ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$orders_table}") : 0;
    echo wp_json_encode(array(
      'products' => $posts['product'],
      'variations' => $posts['product_variation'],
      'posts' => $posts['post'],
      'pages' => $posts['page'],
      'users' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}"),
      'taxonomy_relationships' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->term_relationships}"),
      'orders' => max($posts['shop_order'], $hpos_orders),
    ));
  `]);
  const measuredCounts = JSON.parse(countsJson || '{}');
  const databaseMb = Number(wpValue(site, ['db', 'query', 'SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) FROM information_schema.tables WHERE table_schema = DATABASE();', '--skip-column-names'])) || 0;
  const catalogueSize = Number(measuredCounts.products || 0) + Number(measuredCounts.variations || 0);
  const datasetTier = catalogueSize >= 1000000 ? '1m_plus' : catalogueSize >= 100000 ? '100k_plus' : catalogueSize >= 10000 ? '10k_plus' : 'small';
  const characteristics = {
    site_type: plugins.some((plugin) => plugin.name === 'woocommerce') ? 'woocommerce' : 'wordpress',
    dataset_tier: datasetTier,
    database_mb: databaseMb,
    release_label: site.release_label || '',
    plugin_ref: site.plugin_ref || 'working-tree',
    offered_version: site.offered_version || '',
    ...measuredCounts,
  };
  const debug = {
    WP_DEBUG: wpValue(site, ['config', 'get', 'WP_DEBUG']),
    WP_DEBUG_LOG: wpValue(site, ['config', 'get', 'WP_DEBUG_LOG']),
    WP_DEBUG_DISPLAY: wpValue(site, ['config', 'get', 'WP_DEBUG_DISPLAY']),
  };
  const row = [
    runId, site.id, site.url, site.scenario,
    wpValue(site, ['core', 'version']),
    wpValue(site, ['eval', 'echo PHP_VERSION;']),
    wpValue(site, ['db', 'query', 'SELECT VERSION();', '--skip-column-names']),
    JSON.stringify(themeEvidence),
    JSON.stringify(plugins), hash(JSON.stringify(plugins)),
    wpValue(site, ['plugin', 'get', pluginSlug, '--field=version']), '0.1.0', JSON.stringify(debug), JSON.stringify({ ...characteristics, plugin_slug: pluginSlug }),
  ];
  sqlite(`INSERT INTO sites VALUES (${row.map(sql).join(',')});`);
  site.recorder = config;
}

for (const target of manifest.targets) {
  const repetitions = target.repetitions || defaults.repetitions;
  sqlite(`INSERT INTO targets VALUES (${[
    runId, target.id, target.site, target.feature, target.area, target.page_type, target.path,
    target.role, target.readiness_selector || null, repetitions,
  ].map(sql).join(',')});`);
}
for (const feature of manifest.features || []) {
  sqlite(`INSERT INTO run_features VALUES (${sql(runId)},${sql(feature)});`);
}

const browser = await chromium.launch({ headless: true });
let runStatus = 'completed';

async function login(page, baseUrl) {
  await page.goto(`${baseUrl}/wp-login.php`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(600);
  await page.locator('#user_login').fill(adminUser);
  await page.locator('#user_pass').fill(adminPass);
  if (await page.locator('#user_login').inputValue() !== adminUser || await page.locator('#user_pass').inputValue() !== adminPass) {
    throw new Error(`${baseUrl}: WordPress login fields did not retain the supplied credentials`);
  }
  await Promise.all([
    page.waitForURL(/\/wp-admin\//, { timeout: defaults.timeout_ms }),
    page.locator('#wp-submit').click(),
  ]);
}

async function waitForEnvelope(site, sampleId) {
  const filename = path.join(dataDir, 'inbox', site.recorder.siteId, runId, `${sampleId}.json`);
  for (let attempt = 0; attempt < 100; attempt += 1) {
    if (existsSync(filename)) return { filename, envelope: JSON.parse(readFileSync(filename, 'utf8')) };
    await sleep(50);
  }
  return { filename, envelope: null };
}

function counts(faults) {
  const result = { error: 0, warning: 0, notice: 0 };
  for (const fault of faults) {
    const severity = String(fault.severity || '');
    if (severity.includes('error') || severity === 'parse' || severity === 'assertion_failed' || severity === 'http_5xx') result.error += 1;
    else if (severity.includes('warning')) result.warning += 1;
    else result.notice += 1;
  }
  return result;
}

async function measure(target, site, sequence, context, page) {
  const sampleId = `${target.id.replace(/[^A-Za-z0-9_-]/g, '_')}_${String(sequence).padStart(3, '0')}_${randomBytes(3).toString('hex')}`;
  const expires = Math.floor(Date.now() / 1000) + 120;
  const targetUrl = new URL(target.path, site.url);
  const canonical = [runId, sampleId, 'GET', targetUrl.host.toLowerCase(), targetUrl.pathname, expires].join('\n');
  const signature = createHmac('sha256', site.recorder.secret).update(canonical).digest('hex');
  const browserFaults = [];
  const failed = (request) => browserFaults.push({
    kind: 'browser_request', severity: 'request_failed',
    message: `${request.resourceType()} request failed`,
    fingerprint: hash(`request_failed|${request.resourceType()}|${new URL(request.url()).pathname}`), file: '', line: 0,
  });
  page.on('requestfailed', failed);
  await page.route('**/*', async (route) => {
    const request = route.request();
    if (request.isNavigationRequest() && request.frame() === page.mainFrame()) {
      await route.continue({ headers: {
        ...request.headers(),
        'X-SSPA-Observatory': `${runId}:${sampleId}`,
        'X-SSPA-Observatory-Token': `${expires}:${signature}`,
      } });
      return;
    }
    await route.continue();
  });

  let response = null;
  let readinessOk = false;
  let navigationError = '';
  const started = performance.now();
  try {
    response = await page.goto(targetUrl.href, { waitUntil: 'domcontentloaded', timeout: defaults.timeout_ms });
    if (target.readiness_selector) {
      await page.locator(target.readiness_selector).first().waitFor({ state: 'attached', timeout: defaults.timeout_ms });
    }
    readinessOk = true;
  } catch (error) {
    navigationError = String(error?.message || error).split('\n')[0];
  }
  if (target.expect?.selector) {
    const expected = page.locator(target.expect.selector).first();
    if (await expected.count() === 0) {
      browserFaults.push({
        kind: 'browser_assertion', severity: 'assertion_failed',
        message: `Expected selector is absent: ${target.expect.selector}`,
        fingerprint: hash(`selector_absent|${target.expect.selector}`), file: '', line: 0,
      });
    } else {
      for (const [attribute, value] of Object.entries(target.expect.attributes || {})) {
        const actual = await expected.getAttribute(attribute);
        if (String(actual) !== String(value)) browserFaults.push({
          kind: 'browser_assertion', severity: 'assertion_failed',
          message: `${attribute} expected ${value}, got ${actual}`,
          fingerprint: hash(`attribute|${attribute}|${value}|${actual}`), file: '', line: 0,
        });
      }
    }
  }
  const browserMs = performance.now() - started;
  if (response && response.status() >= 500) browserFaults.push({
    kind: 'browser_response', severity: 'http_5xx',
    message: `HTTP ${response.status()} ${targetUrl.pathname}`,
    fingerprint: hash(`http_5xx|${response.status()}|${targetUrl.pathname}`), file: '', line: 0,
  });
  await page.unroute('**/*');
  page.off('requestfailed', failed);

  const { filename, envelope } = await waitForEnvelope(site, sampleId);
  const canary = response?.headers()['x-sspa-observatory-canary'] || '';
  const canaryOk = canary === sampleId;
  const faults = [...(envelope?.faults || []), ...browserFaults];
  if (navigationError) faults.push({
    kind: 'readiness', severity: 'navigation_error', message: navigationError,
    fingerprint: hash(`navigation|${navigationError}`), file: '', line: 0,
  });
  const invalidReason = !response ? 'navigation_failed' : !canaryOk ? 'missing_canary' : !envelope ? 'missing_envelope' : !readinessOk ? 'readiness_failed' : null;
  const valid = invalidReason === null;
  const totals = counts(faults);
  const envelopeHash = envelope ? hash(readFileSync(filename)) : null;

  sqlite('BEGIN;\n' +
    `INSERT INTO samples VALUES (${[
      sampleId, runId, target.id, sequence, envelope?.php_wall_ms ?? null, browserMs,
      envelope?.peak_memory_bytes ?? null, envelope?.query_count ?? null, response?.status() ?? null,
      canaryOk, readinessOk, totals.error, totals.warning, totals.notice, valid, invalidReason,
      envelopeHash, new Date().toISOString(),
    ].map(sql).join(',')});\n` +
    faults.map((fault) => `INSERT INTO faults (sample_id,run_id,target_id,kind,severity,fingerprint,message,file,line,repeat_count) VALUES (${[
      sampleId, runId, target.id, fault.kind || 'unknown', fault.severity || 'unknown',
      fault.fingerprint || hash(JSON.stringify(fault)), fault.message || '', fault.file || '', fault.line || 0, 1,
    ].map(sql).join(',')});`).join('\n') + '\nCOMMIT;');

  process.stdout.write(`${valid ? 'ok' : 'invalid'} ${target.id} #${sequence} ${envelope?.php_wall_ms ?? '-'}ms faults=${faults.length}${invalidReason ? ` ${invalidReason}` : ''}\n`);
  return valid;
}

try {
  for (const target of manifest.targets) {
    const site = manifest.sites.find((candidate) => candidate.id === target.site);
    const context = await browser.newContext({ ignoreHTTPSErrors: true });
    const page = await context.newPage();
    if (target.role === 'administrator') await login(page, site.url);
    for (let warmup = 0; warmup < (target.warmups ?? defaults.warmups); warmup += 1) {
      await page.goto(new URL(target.path, site.url).href, { waitUntil: 'domcontentloaded', timeout: defaults.timeout_ms });
    }
    for (let sequence = 1; sequence <= (target.repetitions ?? defaults.repetitions); sequence += 1) {
      const valid = await measure(target, site, sequence, context, page);
      if (!valid) runStatus = 'partial';
    }
    await context.close();
  }
} catch (error) {
  runStatus = 'failed';
  throw error;
} finally {
  await browser.close();
  sqlite(`UPDATE runs SET finished_at=${sql(new Date().toISOString())},status=${sql(runStatus)} WHERE id=${sql(runId)};`);
  process.stdout.write(`run ${runId} ${runStatus}\n`);
}
