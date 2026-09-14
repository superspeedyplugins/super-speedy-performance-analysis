import { createHash } from 'node:crypto';
import { execFileSync, spawnSync } from 'node:child_process';
import { existsSync, mkdirSync, readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import YAML from 'yaml';

const here = path.dirname(fileURLToPath(import.meta.url));
export const observatoryDir = path.resolve(here, '..');
export const pluginRoot = path.resolve(observatoryDir, '../..');
export const workspaceRoot = process.env.SSPA_WORKSPACE_ROOT
  ? path.resolve(process.env.SSPA_WORKSPACE_ROOT)
  : path.resolve(pluginRoot, '..');
export const dataDir = path.join(pluginRoot, '.data/e2e-observatory');
export const databasePath = path.join(dataDir, 'observatory.sqlite');
export const parallelDevBin = path.join(workspaceRoot, 'tools/parallel-dev/bin');

export function manifestName() {
  const separator = process.argv.indexOf('--');
  const value = separator >= 0 ? process.argv[separator + 1] : process.argv[2];
  return value && !value.startsWith('-') ? value : 'scalability-pro';
}

export function loadManifest(name = manifestName()) {
  const filename = path.join(observatoryDir, `${name}.yml`);
  const source = readFileSync(filename, 'utf8');
  const manifest = YAML.parse(source);
  validateManifest(manifest, filename);
  return { manifest, filename, source };
}

function validateManifest(manifest, filename) {
  if (!manifest?.plugin || !Array.isArray(manifest.sites) || !Array.isArray(manifest.targets)) {
    throw new Error(`Invalid observatory manifest: ${filename}`);
  }
  const sites = new Set(manifest.sites.map((site) => site.id));
  for (const site of manifest.sites) {
    site.plugin_slug = site.plugin_slug || manifest.plugin;
    if (!site.plugin_slug) throw new Error(`Site ${site.id} has no plugin_slug`);
    if (site.plugin_ref && !site.expected_plugin_version) {
      throw new Error(`Historical site ${site.id} needs expected_plugin_version`);
    }
  }
  const targets = new Set();
  for (const target of manifest.targets) {
    if (!target.id || targets.has(target.id)) throw new Error(`Duplicate or missing target id: ${target.id}`);
    if (!sites.has(target.site)) throw new Error(`Target ${target.id} names unknown site ${target.site}`);
    if (!['frontend', 'backend'].includes(target.area)) throw new Error(`Target ${target.id} has invalid area`);
    if (!['guest', 'administrator'].includes(target.role)) throw new Error(`Target ${target.id} has invalid role`);
    if (!String(target.path || '').startsWith('/')) throw new Error(`Target ${target.id} needs an absolute path`);
    targets.add(target.id);
  }
}

let discoveredSitesRoot = null;
// The parallel-dev sites root, from the one place that knows it on every supported host:
// tools/parallel-dev/bin/lib.sh (Homebrew tree on macOS, /var/www/sites on WSL2, or the
// PD_SITES_ROOT override). Asking the shell script keeps the Observatory in step with the
// same rule every other tool applies, instead of carrying its own copy of one machine's path.
export function sitesRoot() {
  if (process.env.PD_SITES_ROOT) return path.resolve(process.env.PD_SITES_ROOT);
  if (discoveredSitesRoot) return discoveredSitesRoot;
  const lib = path.join(parallelDevBin, 'lib.sh');
  if (!existsSync(lib)) throw new Error(`Cannot discover the parallel-dev sites root: ${lib} is missing. Set SSPA_WORKSPACE_ROOT to the workspace or PD_SITES_ROOT to the sites directory.`);
  const root = execFileSync('bash', ['-c', 'source "$1"; printf "%s" "$SITES_ROOT"', 'sites-root', lib], { encoding: 'utf8' }).trim();
  if (!root.startsWith('/')) throw new Error(`parallel-dev reported no sites root from ${lib}`);
  discoveredSitesRoot = root;
  return root;
}

export function siteDirectory(plugin, scenario) {
  const root = sitesRoot();
  const directory = path.join(root, plugin, scenario);
  if (!path.resolve(directory).startsWith(`${path.resolve(root)}${path.sep}`)) throw new Error(`Unsafe site path: ${directory}`);
  return directory;
}

/**
 * Everything preparation needs, checked before anything is created or changed, each failing
 * with one message that says what to do. The overrides exist so the checks themselves can be
 * tested without uninstalling anything.
 */
export function checkPrerequisites(overrides = {}) {
  const nodeVersion = overrides.nodeVersion || process.version;
  const major = Number(String(nodeVersion).replace(/^v/, '').split('.')[0]);
  if (!Number.isFinite(major) || major < 20) {
    throw new Error(`Node.js 20 or newer is required; this is ${nodeVersion}. Install Node 20 and run again.`);
  }
  const searchPath = overrides.path !== undefined ? overrides.path : process.env.PATH || '';
  const hasSqlite = searchPath.split(path.delimiter).some((dir) => dir && existsSync(path.join(dir, 'sqlite3')));
  if (!hasSqlite) {
    throw new Error('sqlite3 is not on PATH; the Observatory stores its measurements in SQLite. Install sqlite3 (apt install sqlite3 / brew install sqlite) and run again.');
  }
  const packageDir = overrides.packageDir || observatoryDir;
  for (const dependency of ['playwright', 'yaml']) {
    if (!existsSync(path.join(packageDir, 'node_modules', dependency, 'package.json'))) {
      throw new Error(`The Observatory's package dependencies are not installed (${dependency} is missing under ${packageDir}). Run npm install in .tests/observatory and run again.`);
    }
  }
  let browser = overrides.browser;
  if (browser === undefined) {
    try {
      const require = createRequire(path.join(packageDir, 'package.json'));
      browser = require('playwright').chromium.executablePath();
    } catch (error) {
      throw new Error(`The browser runtime could not be resolved from playwright (${error.message}). Run npx playwright install chromium in .tests/observatory and run again.`);
    }
  }
  if (!browser || !existsSync(browser)) {
    throw new Error(`Chromium is not installed for playwright (expected ${browser || 'an executable'}). Run npx playwright install chromium in .tests/observatory and run again.`);
  }
  return { nodeVersion, sqlite: true, packageDir, browser };
}

export function wp(site, args, options = {}) {
  const directory = siteDirectory(options.plugin, site.scenario);
  return execFileSync('wp', [`--path=${directory}`, `--url=${site.url}`, ...args], {
    encoding: 'utf8',
    stdio: options.quiet ? ['ignore', 'pipe', 'pipe'] : ['ignore', 'pipe', 'inherit'],
    env: { ...process.env, XDEBUG_MODE: 'off' },
  }).trim();
}

export function sqlite(sql) {
  mkdirSync(dataDir, { recursive: true });
  const result = spawnSync('sqlite3', [databasePath], { input: sql, encoding: 'utf8' });
  if (result.status !== 0) throw new Error(result.stderr || 'sqlite3 failed');
  return result.stdout;
}

export function sqliteJson(sql) {
  if (!existsSync(databasePath)) return [];
  const result = execFileSync('sqlite3', ['-json', databasePath, sql], { encoding: 'utf8' }).trim();
  return result ? JSON.parse(result) : [];
}

export function sql(value) {
  if (value === null || value === undefined) return 'NULL';
  if (typeof value === 'number') return Number.isFinite(value) ? String(value) : 'NULL';
  if (typeof value === 'boolean') return value ? '1' : '0';
  return `'${String(value).replaceAll("'", "''")}'`;
}

export function hash(value) {
  return createHash('sha256').update(String(value)).digest('hex');
}

export function initialiseDatabase() {
  sqlite(readFileSync(path.join(observatoryDir, 'schema.sql'), 'utf8'));
  const siteColumns = new Set(sqliteJson('PRAGMA table_info(sites);').map((column) => column.name));
  if (!siteColumns.has('characteristics_json')) sqlite("ALTER TABLE sites ADD COLUMN characteristics_json TEXT NOT NULL DEFAULT '{}';");
}

export function recorderConfig(plugin, site) {
  const filename = path.join(siteDirectory(plugin, site.scenario), 'wp-content/mu-plugins/sspa-e2e-observatory-config.php');
  const source = readFileSync(filename, 'utf8');
  const read = (name) => {
    const match = source.match(new RegExp(`define\\('${name}', '([^']*)'\\);`));
    if (!match) throw new Error(`Recorder config ${filename} has no ${name}`);
    return match[1];
  };
  return { secret: read('SSPA_OBSERVATORY_SECRET'), siteId: read('SSPA_OBSERVATORY_SITE_ID'), filename };
}

export function sleep(milliseconds) {
  return new Promise((resolve) => setTimeout(resolve, milliseconds));
}
