import {
  buildDescriptor,
  colourForIdentity,
  evidenceRows,
  filterRows,
  nextSampleSelection,
  parseObject,
  pointState,
  sortEvidence,
  stateFromHash,
  stateToHash,
  uniqueInOrder,
} from './model.js';

const runSelect = document.querySelector('#run');
const tooltip = document.querySelector('#tooltip');
const chart = document.querySelector('#explorer-chart');
const evidence = document.querySelector('#evidence');
const evidenceSort = document.querySelector('#evidence-sort');
const live = document.querySelector('#live');
const pointChooser = document.querySelector('#point-chooser');
const stateLabels = {
  clean: 'Clean request', warning: 'Warning or notice', error: 'Error', invalid: 'Invalid sample',
};
const state = {
  executionId: '',
  areaFilter: 'all',
  selectedFeatureId: '',
  buildFilter: [],
  pointStateFilter: [],
  focusedScenarioId: '',
  selectedSampleIds: [],
  evidenceSort: 'plot',
  ...stateFromHash(location.hash),
};
let samples = [];
let faults = [];
let declaredFeatures = [];
let transientBuildHighlight = '';
let overviewScrollLeft = 0;

async function api(operation, run = '') {
  const response = await fetch(`?api=${operation}${run ? `&run=${encodeURIComponent(run)}` : ''}`);
  if (!response.ok) throw new Error((await response.json()).error || `HTTP ${response.status}`);
  return response.json();
}

function escapeHtml(value) {
  const node = document.createElement('span');
  node.textContent = String(value ?? '');
  return node.innerHTML;
}

function featureLabel(feature) {
  return String(feature || '?').split('/').pop().replaceAll('-', ' ').replaceAll('_', ' ');
}

function scenarioDescriptor(row) {
  return {
    id: [row.area, row.page_type, row.path, row.role].map((value) => String(value || '')).join('|'),
    label: String(row.page_type || row.target_id || '?').replaceAll('-', ' '),
  };
}

function median(values) {
  const sorted = values.filter(Number.isFinite).sort((a, b) => a - b);
  if (!sorted.length) return null;
  const middle = Math.floor(sorted.length / 2);
  return sorted.length % 2 ? sorted[middle] : (sorted[middle - 1] + sorted[middle]) / 2;
}

function syncHash() {
  history.replaceState(null, '', `${location.pathname}${location.search}${stateToHash(state)}`);
}

function announce(message) {
  live.textContent = '';
  requestAnimationFrame(() => { live.textContent = message; });
}

function setState(changes, message = '') {
  Object.assign(state, changes);
  syncHash();
  renderCurrent();
  if (message) announce(message);
}

function sanitizeState() {
  const buildIds = new Set(samples.map((row) => buildDescriptor(row).id));
  const featureIds = new Set(declaredFeatures);
  const sampleIds = new Set(samples.map((row) => row.id));
  state.buildFilter = (state.buildFilter || []).filter((id) => buildIds.has(id));
  state.pointStateFilter = (state.pointStateFilter || []).filter((item) => stateLabels[item]);
  state.selectedSampleIds = (state.selectedSampleIds || []).filter((id) => sampleIds.has(id));
  if (state.selectedFeatureId && !featureIds.has(state.selectedFeatureId)) state.selectedFeatureId = '';
  if (!['all', 'frontend', 'backend'].includes(state.areaFilter)) state.areaFilter = 'all';
  if (!['plot', 'errors', 'slowest', 'fastest', 'feature', 'version', 'recorded'].includes(state.evidenceSort)) state.evidenceSort = 'plot';
}

function renderAreaControls() {
  document.querySelectorAll('[data-area]').forEach((button) => {
    const active = button.dataset.area === state.areaFilter;
    button.classList.toggle('active', active);
    button.setAttribute('aria-pressed', String(active));
    button.onclick = () => {
      transientBuildHighlight = '';
      setState({
        areaFilter: button.dataset.area,
        selectedFeatureId: '',
        focusedScenarioId: '',
        selectedSampleIds: [],
      }, `${button.textContent.trim()} requests shown.`);
    };
  });
}

function keyButton({ type, id, label, count, colour = '', stateName = '' }) {
  const activeItems = type === 'build' ? state.buildFilter : state.pointStateFilter;
  const active = activeItems.includes(id);
  const button = document.createElement('button');
  button.type = 'button';
  button.className = `key-button${active ? ' active' : ''}`;
  button.setAttribute('aria-pressed', String(active));
  button.dataset.keyType = type;
  button.dataset.keyId = id;
  if (type === 'build') {
    button.innerHTML = `<span class="key-symbol build-symbol" style="--series-colour:${escapeHtml(colour)}"></span><span>${escapeHtml(label)}</span><small>${count}</small>`;
  } else {
    button.innerHTML = `<span class="key-symbol state-symbol state-${escapeHtml(stateName)}"></span><span>${escapeHtml(label)}</span><small>${count}</small>`;
  }
  button.onclick = () => {
    const property = type === 'build' ? 'buildFilter' : 'pointStateFilter';
    const next = active ? [] : [id];
    transientBuildHighlight = '';
    setState({ [property]: next, focusedScenarioId: '', selectedSampleIds: [] }, `${label} ${active ? 'filter cleared' : 'isolated'}.`);
  };
  return button;
}

function renderKeys() {
  const buildContainer = document.querySelector('#build-key');
  const stateContainer = document.querySelector('#state-key');
  const areaRows = samples.filter((row) => state.areaFilter === 'all' || row.area === state.areaFilter);
  const builds = new Map();
  areaRows.forEach((row) => {
    const build = buildDescriptor(row);
    if (!builds.has(build.id)) builds.set(build.id, { ...build, count: 0 });
    builds.get(build.id).count += 1;
  });
  buildContainer.replaceChildren();
  builds.forEach((build) => buildContainer.append(keyButton({
    type: 'build', id: build.id, label: build.label, count: build.count, colour: colourForIdentity(build.id),
  })));
  stateContainer.replaceChildren();
  Object.entries(stateLabels).forEach(([id, label]) => {
    const count = areaRows.filter((row) => pointState(row) === id).length;
    stateContainer.append(keyButton({ type: 'state', id, label, count, stateName: id }));
  });
  const hasFilters = state.buildFilter.length || state.pointStateFilter.length;
  const clear = document.querySelector('#clear-filters');
  clear.disabled = !hasFilters;
  clear.onclick = () => {
    transientBuildHighlight = '';
    setState({ buildFilter: [], pointStateFilter: [], focusedScenarioId: '', selectedSampleIds: [] }, 'Filters cleared.');
  };
}

function renderBreadcrumb() {
  const breadcrumb = document.querySelector('#breadcrumb');
  const title = document.querySelector('#explorer-title');
  if (!state.selectedFeatureId) {
    breadcrumb.innerHTML = '<span aria-current="page">All features</span>';
    title.textContent = 'Feature overview';
    return;
  }
  title.textContent = featureLabel(state.selectedFeatureId);
  breadcrumb.innerHTML = `<button type="button" id="back-to-features">All features</button><span aria-hidden="true">›</span><span aria-current="page">${escapeHtml(featureLabel(state.selectedFeatureId))}</span>`;
  document.querySelector('#back-to-features').onclick = () => {
    setState({ selectedFeatureId: '', focusedScenarioId: '', selectedSampleIds: [] }, 'Returned to all features.');
    requestAnimationFrame(() => { chart.scrollLeft = overviewScrollLeft; });
  };
}

function renderSummary(rows, allRows) {
  const valid = rows.filter((row) => Number(row.valid)).length;
  const errors = rows.reduce((sum, row) => sum + Number(row.fault_error_count), 0);
  const warnings = rows.reduce((sum, row) => sum + Number(row.fault_warning_count) + Number(row.fault_notice_count), 0);
  const features = new Set(rows.map((row) => row.feature_id)).size;
  const suffix = rows.length === allRows.length ? '' : ` of ${allRows.length}`;
  document.querySelector('#summary').innerHTML = `<div><strong>${rows.length}${suffix}</strong><span>requests shown</span></div><div><strong>${valid}</strong><span>valid samples</span></div><div><strong>${errors}</strong><span>errors</span></div><div><strong>${warnings}</strong><span>warnings/notices</span></div><div><strong>${features}/${declaredFeatures.length}</strong><span>features measured</span></div>`;
}

function pointElement(ns, row, x, y, colour) {
  const status = pointState(row);
  let point;
  if (status === 'error') {
    point = document.createElementNS(ns, 'rect');
    point.setAttribute('x', x - 5); point.setAttribute('y', y - 5);
    point.setAttribute('width', 10); point.setAttribute('height', 10);
  } else if (status === 'warning') {
    point = document.createElementNS(ns, 'path');
    point.setAttribute('d', `M ${x} ${y - 6} L ${x + 6} ${y + 5} L ${x - 6} ${y + 5} Z`);
  } else if (status === 'invalid') {
    point = document.createElementNS(ns, 'path');
    point.setAttribute('d', `M ${x} ${y - 6} L ${x + 6} ${y} L ${x} ${y + 6} L ${x - 6} ${y} Z`);
  } else {
    point = document.createElementNS(ns, 'circle');
    point.setAttribute('cx', x); point.setAttribute('cy', y); point.setAttribute('r', 5);
  }
  point.setAttribute('fill', colour);
  point.setAttribute('class', `point-visible state-${status}`);
  return point;
}

function showTooltip(event, row) {
  const theme = parseObject(row.theme_json);
  const site = parseObject(row.characteristics_json);
  const build = buildDescriptor(row);
  tooltip.innerHTML = `<strong>${escapeHtml(row.page_type || row.feature_id)}</strong><br>${escapeHtml(featureLabel(row.feature_id))}<br>${escapeHtml(build.label)} · ${escapeHtml(build.ref.slice(0, 12))}<br>${escapeHtml(row.site_id)}<br>${escapeHtml(site.site_type || 'site')} · ${escapeHtml(site.dataset_tier || 'unclassified size')} · ${Number(site.database_mb || 0).toLocaleString()} MB database<br>${Number(site.products || 0).toLocaleString()} products · ${Number(site.orders || 0).toLocaleString()} orders<br>Theme ${escapeHtml(theme.slug || row.theme_json || '?')} ${escapeHtml(theme.version || '')}<br>PHP ${Number(row.php_wall_ms).toFixed(1)} ms · ${row.query_count ?? '?'} queries<br>${row.fault_error_count} errors · ${row.fault_warning_count} warnings · ${row.fault_notice_count} notices`;
  tooltip.style.left = `${event.clientX + 12}px`;
  tooltip.style.top = `${event.clientY + 12}px`;
  tooltip.style.display = 'block';
}

function hidePointChooser() {
  pointChooser.hidden = true;
  pointChooser.replaceChildren();
}

function showPointChooser(event, rows) {
  tooltip.style.display = 'none';
  pointChooser.innerHTML = `<strong>${rows.length} requests overlap here</strong><p>Choose the request to inspect.</p>${rows.map((row) => {
    const build = buildDescriptor(row);
    return `<button type="button" data-choose-sample="${escapeHtml(row.id)}"><span>${escapeHtml(build.label)} · request ${Number(row.sequence)}</span><small>${Number(row.php_wall_ms).toFixed(1)} ms · ${escapeHtml(stateLabels[pointState(row)])}</small></button>`;
  }).join('')}`;
  pointChooser.style.left = `${Math.min(event.clientX + 12, window.innerWidth - 330)}px`;
  pointChooser.style.top = `${Math.min(event.clientY + 12, window.innerHeight - 260)}px`;
  pointChooser.hidden = false;
  pointChooser.querySelectorAll('[data-choose-sample]').forEach((button) => {
    button.onclick = () => {
      const row = rows.find((item) => item.id === button.dataset.chooseSample);
      hidePointChooser();
      activatePoint(row, event);
    };
  });
  pointChooser.querySelector('button')?.focus();
}

function activatePoint(row, event) {
  if (!state.selectedFeatureId) {
    overviewScrollLeft = chart.scrollLeft;
    transientBuildHighlight = buildDescriptor(row).id;
    setState({ selectedFeatureId: row.feature_id, focusedScenarioId: '', selectedSampleIds: [] }, `${featureLabel(row.feature_id)} pages opened.`);
    return;
  }
  const selected = nextSampleSelection(state.selectedSampleIds, row.id, event.ctrlKey || event.metaKey);
  transientBuildHighlight = '';
  setState({ selectedSampleIds: selected, focusedScenarioId: scenarioDescriptor(row).id }, `${selected.length} request ${selected.length === 1 ? 'point' : 'points'} selected.`);
}

function renderChart(rows, groups, groupForRow, labelForGroup, buildIds) {
  chart.replaceChildren();
  const width = Math.max(900, groups.length * 132 + 110);
  const height = 440;
  const margin = { top: 30, right: 28, bottom: 125, left: 76 };
  const values = rows.map((row) => Number(row.php_wall_ms)).filter(Number.isFinite);
  const max = Math.max(10, ...values) * 1.12;
  const ns = 'http://www.w3.org/2000/svg';
  const svg = document.createElementNS(ns, 'svg');
  svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
  svg.setAttribute('aria-label', state.selectedFeatureId ? `${featureLabel(state.selectedFeatureId)} page timing scatter plot` : 'Feature timing scatter plot');
  const plotWidth = width - margin.left - margin.right;
  const groupCentre = (group) => margin.left + ((groups.indexOf(group) + 0.5) / Math.max(1, groups.length)) * plotWidth;
  const buildOffset = (buildId) => (buildIds.indexOf(buildId) - (buildIds.length - 1) / 2) * Math.min(18, 54 / Math.max(1, buildIds.length));
  const x = (row) => groupCentre(groupForRow(row)) + buildOffset(buildDescriptor(row).id) + ((Number(row.sequence) % 7) - 3) * 2.2;
  const y = (value) => margin.top + (1 - Number(value) / max) * (height - margin.top - margin.bottom);

  for (let tick = 0; tick <= 4; tick += 1) {
    const value = max * tick / 4;
    const line = document.createElementNS(ns, 'line');
    line.setAttribute('x1', margin.left); line.setAttribute('x2', width - margin.right);
    line.setAttribute('y1', y(value)); line.setAttribute('y2', y(value)); line.setAttribute('class', 'grid');
    svg.append(line);
    const label = document.createElementNS(ns, 'text');
    label.setAttribute('x', margin.left - 10); label.setAttribute('y', y(value) + 4); label.setAttribute('text-anchor', 'end');
    label.textContent = `${Math.round(value)} ms`;
    svg.append(label);
  }

  groups.forEach((groupId) => {
    if (state.selectedFeatureId && state.focusedScenarioId === groupId) {
      const band = document.createElementNS(ns, 'rect');
      band.setAttribute('x', groupCentre(groupId) - Math.max(40, plotWidth / Math.max(1, groups.length) / 2));
      band.setAttribute('y', margin.top);
      band.setAttribute('width', Math.max(80, plotWidth / Math.max(1, groups.length)));
      band.setAttribute('height', height - margin.top - margin.bottom);
      band.setAttribute('class', 'focus-band');
      svg.append(band);
    }
    const button = document.createElementNS(ns, 'g');
    button.setAttribute('class', `x-label-button${state.selectedFeatureId && state.focusedScenarioId === groupId ? ' active' : ''}`);
    button.setAttribute('role', 'button');
    button.setAttribute('tabindex', '0');
    button.setAttribute('data-group-id', groupId);
    button.setAttribute('aria-label', `${state.selectedFeatureId ? 'Focus page' : 'Open feature'} ${labelForGroup(groupId)}`);
    const hit = document.createElementNS(ns, 'rect');
    hit.setAttribute('x', groupCentre(groupId) - 50); hit.setAttribute('y', height - margin.bottom + 4);
    hit.setAttribute('width', 108); hit.setAttribute('height', 100); hit.setAttribute('class', 'axis-hit');
    button.append(hit);
    const label = document.createElementNS(ns, 'text');
    label.setAttribute('x', groupCentre(groupId)); label.setAttribute('y', height - margin.bottom + 20);
    label.setAttribute('transform', `rotate(35 ${groupCentre(groupId)} ${height - margin.bottom + 20})`);
    label.setAttribute('class', 'x-label'); label.textContent = labelForGroup(groupId);
    button.append(label);
    const activate = () => {
      if (!state.selectedFeatureId) {
        overviewScrollLeft = chart.scrollLeft;
        transientBuildHighlight = '';
        setState({ selectedFeatureId: groupId, focusedScenarioId: '', selectedSampleIds: [] }, `${featureLabel(groupId)} pages opened.`);
      } else {
        const focus = state.focusedScenarioId === groupId ? '' : groupId;
        setState({ focusedScenarioId: focus, selectedSampleIds: [] }, focus ? `${labelForGroup(groupId)} highlighted.` : 'Page highlight cleared.');
      }
    };
    button.addEventListener('click', activate);
    button.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); activate(); }
    });
    svg.append(button);

    buildIds.forEach((buildId) => {
      const groupValues = rows.filter((row) => groupForRow(row) === groupId && buildDescriptor(row).id === buildId && Number(row.valid)).map((row) => Number(row.php_wall_ms));
      const centre = median(groupValues);
      if (centre === null) return;
      const mark = document.createElementNS(ns, 'line');
      const centreX = groupCentre(groupId) + buildOffset(buildId);
      mark.setAttribute('x1', centreX - 7); mark.setAttribute('x2', centreX + 7);
      mark.setAttribute('y1', y(centre)); mark.setAttribute('y2', y(centre));
      mark.setAttribute('class', 'median'); mark.setAttribute('stroke', colourForIdentity(buildId));
      svg.append(mark);
    });

    if (!rows.some((row) => groupForRow(row) === groupId)) {
      const empty = document.createElementNS(ns, 'text');
      empty.setAttribute('x', groupCentre(groupId)); empty.setAttribute('y', y(max * .08));
      empty.setAttribute('text-anchor', 'middle'); empty.setAttribute('class', 'not-measured');
      empty.textContent = 'not measured'; svg.append(empty);
    }
  });

  const plottedRows = rows.filter((row) => Number.isFinite(Number(row.php_wall_ms))).map((row) => ({
    row,
    x: x(row),
    y: y(Number(row.php_wall_ms)),
  }));
  plottedRows.forEach(({ row, x: pointX, y: pointY }) => {
    const value = Number(row.php_wall_ms);
    const build = buildDescriptor(row);
    const group = document.createElementNS(ns, 'g');
    const selected = state.selectedSampleIds.includes(row.id);
    const contextDimmed = state.selectedFeatureId && state.focusedScenarioId && scenarioDescriptor(row).id !== state.focusedScenarioId;
    const buildDimmed = transientBuildHighlight && build.id !== transientBuildHighlight;
    group.setAttribute('class', `point-hit${selected ? ' selected' : ''}${contextDimmed || buildDimmed ? ' context-dimmed' : ''}`);
    group.setAttribute('role', 'button'); group.setAttribute('tabindex', '0');
    group.setAttribute('data-sample-id', row.id);
    group.setAttribute('data-feature-id', row.feature_id);
    group.setAttribute('data-build-id', build.id);
    group.setAttribute('data-point-state', pointState(row));
    group.setAttribute('aria-label', `${state.selectedFeatureId ? 'Select request' : 'Open feature'} ${featureLabel(row.feature_id)}, ${row.page_type}, ${build.label}, ${Number(value).toFixed(1)} milliseconds, ${stateLabels[pointState(row)]}`);
    const hit = document.createElementNS(ns, 'circle');
    hit.setAttribute('cx', pointX); hit.setAttribute('cy', pointY); hit.setAttribute('r', 12); hit.setAttribute('class', 'point-target');
    group.append(hit, pointElement(ns, row, pointX, pointY, colourForIdentity(build.id)));
    group.addEventListener('mouseenter', (event) => showTooltip(event, row));
    group.addEventListener('mousemove', (event) => showTooltip(event, row));
    group.addEventListener('mouseleave', () => { tooltip.style.display = 'none'; });
    group.addEventListener('click', (event) => {
      if (!state.selectedFeatureId) { activatePoint(row, event); return; }
      const overlapping = plottedRows
        .filter((item) => Math.hypot(item.x - pointX, item.y - pointY) <= 12)
        .map((item) => item.row);
      if (overlapping.length > 1) showPointChooser(event, overlapping);
      else activatePoint(row, event);
    });
    group.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); activatePoint(row, event); }
    });
    svg.append(group);
  });
  chart.append(svg);
}

function renderEvidence(filteredRows) {
  let scoped = [];
  let heading = 'Select a feature, page or request to inspect its evidence.';
  const areaBuildRows = samples.filter((row) => {
    if (state.areaFilter !== 'all' && row.area !== state.areaFilter) return false;
    if (state.buildFilter.length && !state.buildFilter.includes(buildDescriptor(row).id)) return false;
    return !state.selectedFeatureId || row.feature_id === state.selectedFeatureId;
  });
  if (state.selectedSampleIds.length) {
    scoped = evidenceRows(areaBuildRows, state.selectedSampleIds);
    const cellCount = new Set(scoped.map((row) => row.target_id)).size;
    heading = `${scoped.length} repeated requests from ${cellCount} selected page/build ${cellCount === 1 ? 'cell' : 'cells'}.`;
  } else if (state.selectedFeatureId && state.focusedScenarioId) {
    scoped = filteredRows.filter((row) => scenarioDescriptor(row).id === state.focusedScenarioId);
    heading = `${scoped.length} requests from the highlighted page.`;
  } else if (state.selectedFeatureId) {
    scoped = filteredRows;
    heading = `${scoped.length} requests for ${featureLabel(state.selectedFeatureId)}.`;
  } else if (state.buildFilter.length || state.pointStateFilter.length) {
    scoped = filteredRows;
    heading = `${scoped.length} requests match the active keys.`;
  }
  document.querySelector('#evidence-heading').textContent = heading;
  document.querySelector('#clear-selection').disabled = !state.selectedSampleIds.length && !state.focusedScenarioId;
  evidenceSort.value = state.evidenceSort;
  if (!scoped.length) {
    evidence.innerHTML = '<p class="empty-evidence">No request evidence in this scope.</p>';
    return;
  }
  const faultsBySample = new Map();
  faults.forEach((fault) => {
    if (!faultsBySample.has(fault.sample_id)) faultsBySample.set(fault.sample_id, []);
    faultsBySample.get(fault.sample_id).push(fault);
  });
  evidence.innerHTML = sortEvidence(scoped, state.evidenceSort).map((row) => {
    const build = buildDescriptor(row);
    const rowFaults = faultsBySample.get(row.id) || [];
    const selected = state.selectedSampleIds.includes(row.id);
    const status = pointState(row);
    const faultHtml = rowFaults.length
      ? `<div class="request-faults"><h4>Faults</h4>${rowFaults.map((fault) => `<p><span class="severity ${escapeHtml(fault.severity)}">${escapeHtml(fault.severity)}</span> ${escapeHtml(fault.message)}<br><small>${escapeHtml(fault.file || 'No file')}${fault.line ? `:${Number(fault.line)}` : ''} · ${escapeHtml(String(fault.fingerprint).slice(0, 12))}</small></p>`).join('')}</div>`
      : '';
    return `<article class="evidence-card state-${status}${selected ? ' selected' : ''}" data-evidence-sample="${escapeHtml(row.id)}">
      <header><span class="evidence-state">${escapeHtml(stateLabels[status])}</span>${selected ? '<strong class="selected-label">Selected request</strong>' : ''}<span>Request ${Number(row.sequence)}</span></header>
      <h3>${escapeHtml(row.page_type)} · ${escapeHtml(build.label)}</h3>
      ${faultHtml}
      <dl><div><dt>Feature</dt><dd>${escapeHtml(row.feature_id)}</dd></div><div><dt>Target</dt><dd>${escapeHtml(row.target_id)}</dd></div><div><dt>Site</dt><dd>${escapeHtml(row.site_id)}</dd></div><div><dt>Role</dt><dd>${escapeHtml(row.role || '?')}</dd></div><div><dt>PHP time</dt><dd>${Number(row.php_wall_ms).toFixed(1)} ms</dd></div><div><dt>Browser</dt><dd>${Number(row.browser_navigation_ms || 0).toFixed(1)} ms</dd></div><div><dt>Queries</dt><dd>${row.query_count ?? '?'}</dd></div><div><dt>HTTP</dt><dd>${row.http_status ?? '?'}</dd></div><div><dt>Ref</dt><dd>${escapeHtml(build.ref.slice(0, 12))}</dd></div><div><dt>Validity</dt><dd>${Number(row.valid) ? 'valid' : escapeHtml(row.invalid_reason || 'invalid')}</dd></div></dl>
    </article>`;
  }).join('');
}

function renderFaults(rows) {
  const container = document.querySelector('#faults');
  if (!rows.length) { container.innerHTML = '<p class="clean">No faults recorded in this execution.</p>'; return; }
  const groups = new Map();
  rows.forEach((fault) => {
    const key = fault.fingerprint;
    if (!groups.has(key)) groups.set(key, { ...fault, count: 0, targets: new Set(), features: new Set(), versions: new Set() });
    const group = groups.get(key);
    group.count += 1; group.targets.add(fault.target_id); group.features.add(fault.feature_id); group.versions.add(fault.plugin_version || '?');
  });
  container.innerHTML = [...groups.values()].map((fault) => `<details><summary><span class="severity ${escapeHtml(fault.severity)}">${escapeHtml(fault.severity)}</span> ${escapeHtml(fault.message)} <small>${fault.count} samples</small></summary><p>${escapeHtml(fault.file || 'No file')}${fault.line ? `:${fault.line}` : ''}</p><p>Versions: ${escapeHtml([...fault.versions].join(', '))}</p><p>Features: ${escapeHtml([...fault.features].join(', '))}</p><p>Targets: ${escapeHtml([...fault.targets].join(', '))}</p></details>`).join('');
}

function renderCurrent() {
  sanitizeState();
  renderAreaControls();
  renderKeys();
  renderBreadcrumb();
  const allAreaRows = samples.filter((row) => state.areaFilter === 'all' || row.area === state.areaFilter);
  const filtered = filterRows(samples, state);
  const rows = state.selectedFeatureId ? filtered.filter((row) => row.feature_id === state.selectedFeatureId) : filtered;
  let groups;
  let groupForRow;
  let labelForGroup;
  if (state.selectedFeatureId) {
    const applicable = allAreaRows.filter((row) => row.feature_id === state.selectedFeatureId);
    groups = uniqueInOrder(applicable.map((row) => scenarioDescriptor(row).id));
    const labels = new Map(applicable.map((row) => [scenarioDescriptor(row).id, scenarioDescriptor(row).label]));
    groupForRow = (row) => scenarioDescriptor(row).id;
    labelForGroup = (groupId) => labels.get(groupId) || groupId;
  } else {
    groups = declaredFeatures;
    groupForRow = (row) => row.feature_id;
    labelForGroup = featureLabel;
  }
  const buildIds = uniqueInOrder(rows.map((row) => buildDescriptor(row).id));
  renderSummary(rows, samples);
  renderChart(rows, groups, groupForRow, labelForGroup, buildIds);
  renderEvidence(rows);
  renderFaults(faults);
  syncHash();
}

async function loadRun(run) {
  const [samplesResult, faultsResult, featureResult] = await Promise.all([api('samples', run), api('faults', run), api('features', run)]);
  samples = samplesResult.rows;
  faults = faultsResult.rows;
  declaredFeatures = featureResult.rows.map((row) => row.feature_id);
  state.executionId = run;
  transientBuildHighlight = '';
  sanitizeState();
  renderCurrent();
}

evidenceSort.addEventListener('change', () => setState({ evidenceSort: evidenceSort.value }));
document.querySelector('#clear-selection').addEventListener('click', () => setState({ selectedSampleIds: [], focusedScenarioId: '' }, 'Request selection cleared.'));
document.addEventListener('pointerdown', (event) => {
  if (!pointChooser.hidden && !pointChooser.contains(event.target)) hidePointChooser();
});
document.addEventListener('keydown', (event) => {
  if (event.key !== 'Escape') return;
  if (!pointChooser.hidden) { hidePointChooser(); return; }
  if (state.selectedSampleIds.length) setState({ selectedSampleIds: [] }, 'Request selection cleared.');
  else if (state.focusedScenarioId) setState({ focusedScenarioId: '' }, 'Page highlight cleared.');
});

api('runs').then((runs) => {
  runSelect.innerHTML = runs.map((run) => `<option value="${escapeHtml(run.id)}">${escapeHtml(run.started_at)} · ${escapeHtml(run.status)}</option>`).join('');
  const requested = runs.some((run) => run.id === state.executionId) ? state.executionId : runs[0]?.id;
  if (!requested) throw new Error('No observatory executions exist yet.');
  runSelect.value = requested;
  runSelect.addEventListener('change', () => {
    Object.assign(state, { executionId: runSelect.value, selectedFeatureId: '', focusedScenarioId: '', selectedSampleIds: [] });
    loadRun(runSelect.value);
  });
  loadRun(requested);
}).catch((error) => { document.querySelector('main').innerHTML = `<p class="error">${escapeHtml(error.message)}</p>`; });
