export const palette = ['#66d9ef', '#a6e22e', '#fd971f', '#ae81ff', '#f92672', '#e6db74', '#7aa2f7', '#bb9af7'];

export function parseObject(value) {
  if (!value || typeof value === 'object') return value || {};
  try { return JSON.parse(value); } catch { return {}; }
}

export function pointState(row) {
  if (Number(row.fault_error_count) > 0) return 'error';
  if (Number(row.fault_warning_count) > 0 || Number(row.fault_notice_count) > 0) return 'warning';
  if (!Number(row.valid)) return 'invalid';
  return 'clean';
}

export function buildDescriptor(row) {
  const characteristics = parseObject(row.characteristics_json);
  const version = String(row.plugin_version || '?');
  const release = String(characteristics.release_label || 'unlabelled');
  const ref = String(characteristics.plugin_ref || '?');
  return {
    id: `${release}|${version}|${ref}`,
    label: `${release} · ${version}`,
    ref,
    version,
  };
}

export function colourForIdentity(identity) {
  let hash = 2166136261;
  for (const character of String(identity)) {
    hash ^= character.codePointAt(0);
    hash = Math.imul(hash, 16777619);
  }
  return palette[Math.abs(hash >>> 0) % palette.length];
}

export function uniqueInOrder(values) {
  return [...new Set(values.filter((value) => value !== null && value !== undefined && value !== ''))];
}

export function filterRows(rows, state) {
  return rows.filter((row) => {
    if (state.areaFilter && state.areaFilter !== 'all' && row.area !== state.areaFilter) return false;
    if (state.buildFilter?.length && !state.buildFilter.includes(buildDescriptor(row).id)) return false;
    if (state.pointStateFilter?.length && !state.pointStateFilter.includes(pointState(row))) return false;
    return true;
  });
}

export function nextSampleSelection(current, sampleId, additive) {
  if (!additive) return current.length === 1 && current[0] === sampleId ? [] : [sampleId];
  return current.includes(sampleId)
    ? current.filter((id) => id !== sampleId)
    : [...current, sampleId];
}

export function evidenceRows(rows, selectedSampleIds) {
  const selectedTargets = new Set(
    rows.filter((row) => selectedSampleIds.includes(row.id)).map((row) => row.target_id),
  );
  return rows.filter((row) => selectedTargets.has(row.target_id));
}

function number(value, fallback = Number.POSITIVE_INFINITY) {
  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : fallback;
}

export function sortEvidence(rows, order) {
  const states = { error: 0, warning: 1, invalid: 2, clean: 3 };
  const sorted = rows.map((row, index) => ({ row, index }));
  sorted.sort((left, right) => {
    const a = left.row;
    const b = right.row;
    let comparison = 0;
    if (order === 'errors') {
      comparison = states[pointState(a)] - states[pointState(b)]
        || number(b.php_wall_ms, Number.NEGATIVE_INFINITY) - number(a.php_wall_ms, Number.NEGATIVE_INFINITY);
    } else if (order === 'slowest') {
      comparison = number(b.php_wall_ms, Number.NEGATIVE_INFINITY) - number(a.php_wall_ms, Number.NEGATIVE_INFINITY);
    } else if (order === 'fastest') {
      comparison = number(a.php_wall_ms) - number(b.php_wall_ms);
    } else if (order === 'feature') {
      comparison = String(a.feature_id).localeCompare(String(b.feature_id))
        || String(a.page_type).localeCompare(String(b.page_type));
    } else if (order === 'version') {
      comparison = buildDescriptor(a).label.localeCompare(buildDescriptor(b).label)
        || String(a.page_type).localeCompare(String(b.page_type));
    } else if (order === 'recorded') {
      comparison = String(a.created_at || '').localeCompare(String(b.created_at || ''));
    } else {
      comparison = String(a.feature_id).localeCompare(String(b.feature_id))
        || String(a.target_id).localeCompare(String(b.target_id))
        || buildDescriptor(a).label.localeCompare(buildDescriptor(b).label)
        || number(a.sequence) - number(b.sequence);
    }
    return comparison || left.index - right.index;
  });
  return sorted.map(({ row }) => row);
}

const stateKeys = [
  'executionId', 'areaFilter', 'selectedFeatureId', 'buildFilter', 'pointStateFilter',
  'focusedScenarioId', 'selectedSampleIds', 'evidenceSort',
];

export function stateToHash(state) {
  const params = new URLSearchParams();
  for (const key of stateKeys) {
    const value = state[key];
    if (Array.isArray(value)) {
      if (value.length) params.set(key, value.join(','));
    } else if (value) {
      params.set(key, String(value));
    }
  }
  return `#${params.toString()}`;
}

export function stateFromHash(hash) {
  const params = new URLSearchParams(String(hash || '').replace(/^#/, ''));
  const state = {};
  for (const key of stateKeys) {
    if (!params.has(key)) continue;
    state[key] = ['buildFilter', 'pointStateFilter', 'selectedSampleIds'].includes(key)
      ? params.get(key).split(',').filter(Boolean)
      : params.get(key);
  }
  return state;
}
