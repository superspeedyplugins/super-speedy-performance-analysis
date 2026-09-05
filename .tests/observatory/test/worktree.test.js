import test from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

test('explicit workspace root leaves observatory evidence inside its own checkout', () => {
  const moduleUrl = new URL('../src/common.js', import.meta.url);
  const workspace = path.resolve('separate-workspace');
  const result = JSON.parse(execFileSync(process.execPath, ['--input-type=module', '-e',
    `import {workspaceRoot, dataDir, parallelDevBin} from ${JSON.stringify(moduleUrl.href)}; console.log(JSON.stringify({workspaceRoot,dataDir,parallelDevBin}));`
  ], {encoding:'utf8',env:{...process.env,SSPA_WORKSPACE_ROOT:workspace}}));
  assert.equal(result.workspaceRoot, workspace);
  assert.equal(result.parallelDevBin, path.join(workspace, 'tools/parallel-dev/bin'));
  assert.equal(result.dataDir, fileURLToPath(new URL('../../../.data/e2e-observatory', import.meta.url)));
});
