import { createHash } from 'node:crypto';
import { execFileSync, spawnSync } from 'node:child_process';
import { existsSync, mkdirSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import YAML from 'yaml';

const here = path.dirname(fileURLToPath(import.meta.url));
export const observatoryDir = path.resolve(here, '..');
export const pluginRoot = path.resolve(observatoryDir, '../..');
export const workspaceRoot = path.resolve(pluginRoot, '..');
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

export function siteDirectory(plugin, scenario) {
  const root = process.env.PD_SITES_ROOT || '/opt/homebrew/var/www/sites';
  const directory = path.join(root, plugin, scenario);
  if (!path.resolve(directory).startsWith(`${path.resolve(root)}${path.sep}`)) throw new Error(`Unsafe site path: ${directory}`);
  return directory;
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
