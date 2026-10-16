import {build} from 'esbuild';
import {copyFile, mkdir, readFile, writeFile} from 'node:fs/promises';
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
  legalComments: 'linked',
  banner: {js: '/*! Apache ECharts 6.1.0 (Apache-2.0), zrender 6.1.0 and d3 quantile (BSD-3-Clause). Source: https://github.com/apache/echarts */'}
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

await copyFile(path.join(buildDir, 'node_modules/echarts/licenses/LICENSE-d3'), path.join(outputDir, 'LICENSE-d3.txt'));

const legal = path.join(outputDir, 'echarts-history.min.js.LEGAL.txt');
await writeFile(legal, (await readFile(legal, 'utf8')).replace(/[ \t]+$/gm, ''));
