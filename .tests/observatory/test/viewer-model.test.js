import test from 'node:test';
import assert from 'node:assert/strict';
import {
  buildDescriptor,
  evidenceRows,
  filterRows,
  nextSampleSelection,
  pointState,
  sortEvidence,
  stateFromHash,
  stateToHash,
  uniqueInOrder,
} from '../viewer/model.js';

const rows = [
  {
    id: 'base-shop-1', target_id: 'base-shop', feature_id: 'search', area: 'frontend',
    page_type: 'shop', plugin_version: '1.5', sequence: 1, php_wall_ms: 120, valid: 1,
    fault_error_count: 0, fault_warning_count: 0, fault_notice_count: 0,
    characteristics_json: '{"release_label":"stable","plugin_ref":"aaaa1111"}',
  },
  {
    id: 'base-shop-2', target_id: 'base-shop', feature_id: 'search', area: 'frontend',
    page_type: 'shop', plugin_version: '1.5', sequence: 2, php_wall_ms: 110, valid: 1,
    fault_error_count: 0, fault_warning_count: 0, fault_notice_count: 0,
    characteristics_json: '{"release_label":"stable","plugin_ref":"aaaa1111"}',
  },
  {
    id: 'candidate-shop-1', target_id: 'candidate-shop', feature_id: 'search', area: 'frontend',
    page_type: 'shop', plugin_version: '1.6.1', sequence: 1, php_wall_ms: 180, valid: 0,
    fault_error_count: 1, fault_warning_count: 0, fault_notice_count: 0,
    characteristics_json: '{"release_label":"feature-branch","plugin_ref":"bbbb2222"}',
  },
  {
    id: 'candidate-shop-2', target_id: 'candidate-shop', feature_id: 'search', area: 'frontend',
    page_type: 'shop', plugin_version: '1.6.1', sequence: 2, php_wall_ms: 170, valid: 1,
    fault_error_count: 0, fault_warning_count: 1, fault_notice_count: 0,
    characteristics_json: '{"release_label":"feature-branch","plugin_ref":"bbbb2222"}',
  },
  {
    id: 'candidate-admin-1', target_id: 'candidate-admin', feature_id: 'pagination', area: 'backend',
    page_type: 'machine-list', plugin_version: '1.6.1', sequence: 1, php_wall_ms: 80, valid: 1,
    fault_error_count: 0, fault_warning_count: 0, fault_notice_count: 0,
    characteristics_json: '{"release_label":"feature-branch","plugin_ref":"bbbb2222"}',
  },
];

test('viewer model derives point state from the real sample fields', () => {
  assert.equal(pointState(rows[0]), 'clean');
  assert.equal(pointState(rows[2]), 'error');
  assert.equal(pointState(rows[3]), 'warning');
  assert.equal(pointState({ ...rows[0], valid: 0 }), 'invalid');
});

test('build identity includes the release label, version and ref', () => {
  assert.deepEqual(buildDescriptor(rows[0]), {
    id: 'stable|1.5|aaaa1111',
    label: 'stable · 1.5',
    ref: 'aaaa1111',
    version: '1.5',
  });
});

test('feature and scenario groups retain declaration order', () => {
  assert.deepEqual(uniqueInOrder(rows.map((row) => row.feature_id)), ['search', 'pagination']);
  assert.deepEqual(uniqueInOrder(rows.filter((row) => row.feature_id === 'search').map((row) => row.target_id)), ['base-shop', 'candidate-shop']);
});

test('area, build and point-state filters intersect', () => {
  const candidateBuild = buildDescriptor(rows[2]).id;
  const filtered = filterRows(rows, {
    areaFilter: 'frontend',
    buildFilter: [candidateBuild],
    pointStateFilter: ['error'],
  });
  assert.deepEqual(filtered.map((row) => row.id), ['candidate-shop-1']);
});

test('selecting one request returns every repetition in its page/build cell', () => {
  const selected = evidenceRows(rows, ['candidate-shop-1']);
  assert.deepEqual(selected.map((row) => row.id), ['candidate-shop-1', 'candidate-shop-2']);
});

test('ordinary click replaces selection and additive click toggles it', () => {
  assert.deepEqual(nextSampleSelection(['base-shop-1'], 'candidate-shop-1', false), ['candidate-shop-1']);
  assert.deepEqual(nextSampleSelection(['candidate-shop-1'], 'candidate-shop-1', false), []);
  assert.deepEqual(nextSampleSelection(['base-shop-1'], 'candidate-shop-1', true), ['base-shop-1', 'candidate-shop-1']);
  assert.deepEqual(nextSampleSelection(['base-shop-1', 'candidate-shop-1'], 'candidate-shop-1', true), ['base-shop-1']);
});

test('evidence sorting is deterministic', () => {
  assert.deepEqual(sortEvidence(rows, 'slowest').map((row) => row.id), [
    'candidate-shop-1', 'candidate-shop-2', 'base-shop-1', 'base-shop-2', 'candidate-admin-1',
  ]);
  assert.deepEqual(sortEvidence(rows, 'errors').map((row) => row.id), [
    'candidate-shop-1', 'candidate-shop-2', 'base-shop-1', 'base-shop-2', 'candidate-admin-1',
  ]);
});

test('local URL state round-trips without evidence values', () => {
  const state = {
    executionId: 'run-one', areaFilter: 'frontend', selectedFeatureId: 'search',
    buildFilter: ['stable|1.5|aaaa1111'], pointStateFilter: ['error'],
    focusedScenarioId: 'base-shop', selectedSampleIds: ['base-shop-1'], evidenceSort: 'errors',
  };
  const hash = stateToHash(state);
  assert.equal(hash.includes('shop.example'), false);
  assert.deepEqual(stateFromHash(hash), state);
});
