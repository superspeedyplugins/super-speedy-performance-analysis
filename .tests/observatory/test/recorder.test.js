import test from 'node:test';
import assert from 'node:assert/strict';
import { createHmac } from 'node:crypto';
import { existsSync, mkdtempSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

test('the real PHP recorder writes a correlated warning envelope', () => {
  const directory = mkdtempSync(path.join(tmpdir(), 'sspa-observatory-'));
  const runId = 'recorder_test_run';
  const sampleId = 'warning_probe_001';
  const secret = 'test-secret-that-never-enters-a-site';
  const expires = Math.floor(Date.now() / 1000) + 60;
  const canonical = [runId, sampleId, 'GET', 'recorder.test', '/warning-probe', expires].join('\n');
  const signature = createHmac('sha256', secret).update(canonical).digest('hex');
  const script = path.join(path.dirname(fileURLToPath(import.meta.url)), 'recorder-probe.php');
  const result = spawnSync('php', [script], {
    encoding: 'utf8',
    env: {
      ...process.env,
      OBS_SPOOL: directory,
      OBS_SECRET: secret,
      OBS_ID: `${runId}:${sampleId}`,
      OBS_TOKEN: `${expires}:${signature}`,
    },
  });
  assert.equal(result.status, 0, result.stderr);
  assert.match(result.stderr, /Deliberate observatory warning probe/, 'the recorder does not suppress PHP warning output');
  const envelope = JSON.parse(readFileSync(path.join(directory, 'inbox/recorder-test', runId, `${sampleId}.json`), 'utf8'));
  assert.equal(envelope.sample_id, sampleId);
  assert.equal(envelope.faults.length, 1);
  assert.equal(envelope.faults[0].severity, 'user_warning');
  assert.match(envelope.faults[0].message, /Deliberate observatory warning probe/);
  assert.ok(envelope.php_wall_ms >= 0);
});

test('an invalid signature cannot arm the recorder', () => {
  const directory = mkdtempSync(path.join(tmpdir(), 'sspa-observatory-invalid-'));
  const runId = 'invalid_signature_run';
  const sampleId = 'invalid_signature_001';
  const expires = Math.floor(Date.now() / 1000) + 60;
  const script = path.join(path.dirname(fileURLToPath(import.meta.url)), 'recorder-probe.php');
  const result = spawnSync('php', [script], {
    encoding: 'utf8',
    env: {
      ...process.env,
      OBS_SPOOL: directory,
      OBS_SECRET: 'correct-secret',
      OBS_ID: `${runId}:${sampleId}`,
      OBS_TOKEN: `${expires}:${'0'.repeat(64)}`,
    },
  });
  assert.equal(result.status, 0);
  assert.equal(existsSync(path.join(directory, 'inbox/recorder-test', runId, `${sampleId}.json`)), false);
});
