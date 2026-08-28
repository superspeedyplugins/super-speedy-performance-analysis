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

test('fleet manifests may map sites to different plugins', () => {
  const { manifest } = loadManifest('fleet-mvp');
  assert.deepEqual(manifest.sites.map((site) => site.plugin_slug), ['scalability-pro', 'super-speedy-ajax-prices']);
  assert.equal(manifest.targets.length, 2);
});

test('SPRO version matrix pins two historical builds and exercises every journey on all three sites', () => {
  const { manifest } = loadManifest('spro-version-matrix');
  assert.equal(manifest.fixture, 'spro-version-matrix');
  assert.equal(manifest.sites.length, 3);
  assert.deepEqual(manifest.sites.map((site) => site.expected_plugin_version || 'working-tree'), ['5.71', '6.29.26', 'working-tree']);
  assert.equal(new Set(manifest.sites.map((site) => site.theme)).size, 3, 'each matrix site has a different theme');
  assert.equal(manifest.targets.length, 18);
  for (const site of manifest.sites) {
    assert.equal(manifest.targets.filter((target) => target.site === site.id).length, 6, `${site.id} has all six journeys`);
  }
  const finalPages = manifest.targets.filter((target) => target.page_type === 'pagination-final-page');
  assert.equal(finalPages.length, 3);
  finalPages.forEach((target) => assert.equal(target.expect.attributes['data-next'], '0'));
});
