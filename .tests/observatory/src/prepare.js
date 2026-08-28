import { execFileSync } from 'node:child_process';
import {
  cpSync, existsSync, lstatSync, mkdirSync, mkdtempSync, readFileSync, rmSync, unlinkSync,
} from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import {
  dataDir, loadManifest, parallelDevBin, recorderConfig, siteDirectory, workspaceRoot, wp,
} from './common.js';

const { manifest } = loadManifest();

function pluginVersion(filename) {
  const match = readFileSync(filename, 'utf8').match(/^ \* Version:\s*([^\r\n]+)/m);
  if (!match) throw new Error(`No plugin header version in ${filename}`);
  return match[1].trim();
}

function removePrivatePaths(directory) {
  for (const entry of ['.ai', '.build', '.changelog-full.md', '.clinerules', '.compatibility', '.docs', '.features', '.github', '.gitignore', '.gitmodules', '.kb', '.roadmap', '.tests', 'AGENTS.md', 'CLAUDE.md', 'ai', 'wp-cli.local.yml']) {
    rmSync(path.join(directory, entry), { recursive: true, force: true });
  }
}

function buildHistoricalZip(pluginSlug, ref, expectedVersion) {
  const repository = path.join(workspaceRoot, pluginSlug);
  const buildDirectory = path.join(dataDir, 'builds');
  const zip = path.join(buildDirectory, `${pluginSlug}-${expectedVersion}-${ref.slice(0, 8)}.zip`);
  if (existsSync(zip)) return zip;
  mkdirSync(buildDirectory, { recursive: true });
  const work = mkdtempSync(path.join(os.tmpdir(), `${pluginSlug}-observatory-`));
  try {
    const rootTar = path.join(work, 'root.tar');
    execFileSync('git', ['-C', repository, 'archive', '--format=tar', `--prefix=${pluginSlug}/`, '-o', rootTar, ref]);
    execFileSync('tar', ['-xf', rootTar, '-C', work]);
    const stage = path.join(work, pluginSlug);
    const tree = execFileSync('git', ['-C', repository, 'ls-tree', ref, 'super-speedy-settings'], { encoding: 'utf8' }).trim();
    if (tree.startsWith('160000 commit ')) {
      const submoduleRef = tree.split(/\s+/)[2];
      const submoduleRepository = path.join(repository, 'super-speedy-settings');
      const submoduleStage = path.join(stage, 'super-speedy-settings');
      const submoduleTar = path.join(work, 'settings.tar');
      mkdirSync(submoduleStage, { recursive: true });
      execFileSync('git', ['-C', submoduleRepository, 'archive', '--format=tar', '-o', submoduleTar, submoduleRef]);
      execFileSync('tar', ['-xf', submoduleTar, '-C', submoduleStage]);
    }
    removePrivatePaths(stage);
    const stagedVersion = pluginVersion(path.join(stage, `${pluginSlug}.php`));
    if (stagedVersion !== expectedVersion) throw new Error(`${ref} is ${stagedVersion}, expected ${expectedVersion}`);
    execFileSync('zip', ['-rqX', zip, pluginSlug], { cwd: work });
  } finally {
    rmSync(work, { recursive: true, force: true });
  }
  return zip;
}

function installHistoricalPlugin(site, pluginSlug, directory) {
  const zip = buildHistoricalZip(pluginSlug, site.plugin_ref, site.expected_plugin_version);
  const pluginDirectory = path.join(directory, 'wp-content/plugins', pluginSlug);
  try { wp(site, ['plugin', 'deactivate', pluginSlug, '--quiet'], { plugin: pluginSlug, quiet: true }); } catch { /* A missing plugin is fine. */ }
  if (existsSync(pluginDirectory)) {
    if (lstatSync(pluginDirectory).isSymbolicLink()) unlinkSync(pluginDirectory);
    else rmSync(pluginDirectory, { recursive: true, force: true });
  }
  wp(site, ['plugin', 'install', zip, '--activate', '--quiet'], { plugin: pluginSlug, quiet: true });
}

function activateDeclaredTheme(site, pluginSlug, directory) {
  if (!site.theme) return;
  const target = path.join(directory, 'wp-content/themes', site.theme);
  const source = path.join(path.dirname(path.dirname(directory)), '.themes', site.theme);
  if (!existsSync(target)) {
    if (!existsSync(source)) throw new Error(`Theme is absent from the parallel-dev library: ${site.theme}`);
    cpSync(source, target, { recursive: true });
  }
  wp(site, ['theme', 'activate', site.theme, '--quiet'], { plugin: pluginSlug, quiet: true });
}

for (const site of manifest.sites) {
  const pluginSlug = site.plugin_slug || manifest.plugin;
  const directory = siteDirectory(pluginSlug, site.scenario);
  if (!existsSync(path.join(directory, 'wp-config.php'))) {
    execFileSync(path.join(parallelDevBin, 'create-site.sh'), [pluginSlug, site.scenario], { stdio: 'inherit' });
  }
  if (!existsSync(path.join(directory, 'wp-config.php'))) throw new Error(`Could not create parallel-dev site: ${directory}`);

  if (site.plugin_ref) installHistoricalPlugin(site, pluginSlug, directory);

  execFileSync(path.join(parallelDevBin, 'install-observatory.sh'), [pluginSlug, site.scenario], { stdio: 'inherit' });
  recorderConfig(pluginSlug, site);

  if (manifest.fixture === 'spro-version-matrix') {
    const muDirectory = path.join(directory, 'wp-content/mu-plugins');
    mkdirSync(muDirectory, { recursive: true });
    cpSync(path.join(path.dirname(new URL(import.meta.url).pathname), '../fixtures/spro-version-matrix.php'), path.join(muDirectory, 'spro-version-matrix.php'));
  }

  let active = wp(site, ['plugin', 'get', pluginSlug, '--field=status'], { plugin: pluginSlug, quiet: true });
  if (active !== 'active' && site.activate_plugin === true) {
    wp(site, ['plugin', 'activate', pluginSlug, '--quiet'], { plugin: pluginSlug, quiet: true });
    active = wp(site, ['plugin', 'get', pluginSlug, '--field=status'], { plugin: pluginSlug, quiet: true });
  }
  if (active !== 'active') throw new Error(`${site.url}: ${pluginSlug} is ${active}, not active; declare activate_plugin: true to repair it`);

  execFileSync(path.join(parallelDevBin, 'ensure-themes.sh'), [pluginSlug, site.scenario], { stdio: 'inherit' });
  activateDeclaredTheme(site, pluginSlug, directory);

  if (manifest.fixture === 'spro-version-matrix') {
    wp(site, ['eval-file', path.join(path.dirname(new URL(import.meta.url).pathname), '../fixtures/setup-spro-version-matrix.php')], { plugin: pluginSlug, quiet: true });
  }

  const installedVersion = wp(site, ['plugin', 'get', pluginSlug, '--field=version'], { plugin: pluginSlug, quiet: true });
  if (site.expected_plugin_version && installedVersion !== String(site.expected_plugin_version)) {
    throw new Error(`${site.url}: installed ${installedVersion}, expected ${site.expected_plugin_version}`);
  }

  const debug = wp(site, ['config', 'get', 'WP_DEBUG'], { plugin: pluginSlug, quiet: true });
  const debugLog = wp(site, ['config', 'get', 'WP_DEBUG_LOG'], { plugin: pluginSlug, quiet: true });
  const debugDisplay = wp(site, ['config', 'get', 'WP_DEBUG_DISPLAY'], { plugin: pluginSlug, quiet: true });
  if (debug !== '1' || debugLog !== '1' || debugDisplay === '1') {
    throw new Error(`${site.url}: expected WP_DEBUG=true, WP_DEBUG_LOG=true and WP_DEBUG_DISPLAY=false`);
  }
  process.stdout.write(`prepared ${site.url} ${pluginSlug} ${installedVersion}\n`);
}
