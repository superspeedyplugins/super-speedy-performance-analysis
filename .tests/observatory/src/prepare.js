import { execFileSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import path from 'node:path';
import { loadManifest, parallelDevBin, recorderConfig, siteDirectory, wp } from './common.js';

const { manifest } = loadManifest();

for (const site of manifest.sites) {
  const directory = siteDirectory(manifest.plugin, site.scenario);
  if (!existsSync(path.join(directory, 'wp-config.php'))) throw new Error(`Missing parallel-dev site: ${directory}`);

  execFileSync(path.join(parallelDevBin, 'install-observatory.sh'), [manifest.plugin, site.scenario], { stdio: 'inherit' });
  recorderConfig(manifest.plugin, site);

  let active = wp(site, ['plugin', 'get', manifest.plugin, '--field=status'], { plugin: manifest.plugin, quiet: true });
  if (active !== 'active' && site.activate_plugin === true) {
    wp(site, ['plugin', 'activate', manifest.plugin, '--quiet'], { plugin: manifest.plugin, quiet: true });
    active = wp(site, ['plugin', 'get', manifest.plugin, '--field=status'], { plugin: manifest.plugin, quiet: true });
  }
  if (active !== 'active') throw new Error(`${site.url}: ${manifest.plugin} is ${active}, not active; declare activate_plugin: true to repair it`);

  execFileSync(path.join(parallelDevBin, 'ensure-themes.sh'), [manifest.plugin, site.scenario], { stdio: 'inherit' });

  const debug = wp(site, ['config', 'get', 'WP_DEBUG'], { plugin: manifest.plugin, quiet: true });
  const debugLog = wp(site, ['config', 'get', 'WP_DEBUG_LOG'], { plugin: manifest.plugin, quiet: true });
  const debugDisplay = wp(site, ['config', 'get', 'WP_DEBUG_DISPLAY'], { plugin: manifest.plugin, quiet: true });
  if (debug !== '1' || debugLog !== '1' || debugDisplay === '1') {
    throw new Error(`${site.url}: expected WP_DEBUG=true, WP_DEBUG_LOG=true and WP_DEBUG_DISPLAY=false`);
  }
  process.stdout.write(`prepared ${site.url}\n`);
}
