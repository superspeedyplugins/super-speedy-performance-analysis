import {build} from 'esbuild';
import {copyFile, mkdir} from 'node:fs/promises';
import {fileURLToPath} from 'node:url';
import path from 'node:path';

const buildDir = path.dirname(fileURLToPath(import.meta.url));
const pluginDir = path.resolve(buildDir, '../..');
const outputDir = path.join(pluginDir, 'includes/admin/vendor');

await mkdir(outputDir, {recursive: true});
await build({
  entryPoints: [path.join(buildDir, 'entry.js')],
  outfile: path.join(outputDir, 'echarts-history.min.js'),
  bundle: true,
  minify: true,
  format: 'iife',
  platform: 'browser',
  target: ['es2018'],
  legalComments: 'none'
});

await copyFile(
  path.join(buildDir, 'node_modules/echarts/LICENSE'),
  path.join(outputDir, 'LICENSE-echarts.txt')
);
await copyFile(
  path.join(buildDir, 'node_modules/echarts/NOTICE'),
  path.join(outputDir, 'NOTICE-echarts.txt')
);
await copyFile(
  path.join(buildDir, 'node_modules/zrender/LICENSE'),
  path.join(outputDir, 'LICENSE-zrender.txt')
);
