import test from 'node:test';
import assert from 'node:assert/strict';
import { loadManifest } from '../src/common.js';

test('Scalability Pro manifest maps every target to a real declared site and feature', () => {
  const { manifest } = loadManifest('scalability-pro');
  const sites = new Set(manifest.sites.map((site) => site.id));
  const features = new Set(manifest.features);
  assert.ok(manifest.targets.length >= 5);
  for (const target of manifest.targets) {
    assert.ok(sites.has(target.site), `${target.id} site exists`);
    assert.ok(features.has(target.feature), `${target.id} feature exists`);
    assert.match(target.path, /^\//);
    assert.ok(target.readiness_selector);
  }
});
