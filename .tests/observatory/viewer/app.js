const colours = ['#66d9ef', '#a6e22e', '#fd971f', '#ae81ff', '#f92672', '#e6db74', '#7aa2f7', '#bb9af7'];
const runSelect = document.querySelector('#run');
const tooltip = document.querySelector('#tooltip');

async function api(operation, run = '') {
  const response = await fetch(`?api=${operation}${run ? `&run=${encodeURIComponent(run)}` : ''}`);
  if (!response.ok) throw new Error((await response.json()).error || `HTTP ${response.status}`);
  return response.json();
}

function featureColours(rows) {
  const map = new Map();
  [...new Set(rows.map((row) => row.feature_id))].sort().forEach((feature, index) => map.set(feature, colours[index % colours.length]));
  return map;
}

function median(values) {
  const sorted = values.filter(Number.isFinite).sort((a, b) => a - b);
  if (!sorted.length) return null;
  const middle = Math.floor(sorted.length / 2);
  return sorted.length % 2 ? sorted[middle] : (sorted[middle - 1] + sorted[middle]) / 2;
}

function scatter(element, rows, groupKey, colourMap, declaredGroups = null) {
  element.replaceChildren();
  const width = Math.max(760, element.clientWidth || 760);
  const height = 390;
  const margin = { top: 24, right: 24, bottom: 105, left: 72 };
  const groups = declaredGroups || [...new Set(rows.map((row) => row[groupKey]))];
  const values = rows.map((row) => Number(row.php_wall_ms)).filter(Number.isFinite);
  const max = Math.max(10, ...values) * 1.12;
  const ns = 'http://www.w3.org/2000/svg';
  const svg = document.createElementNS(ns, 'svg');
  svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
  svg.setAttribute('aria-label', 'PHP request-time scatter plot');
  const x = (group, sequence = 0) => margin.left + ((groups.indexOf(group) + 0.5) / Math.max(1, groups.length)) * (width - margin.left - margin.right) + ((sequence % 7) - 3) * 3;
  const y = (value) => margin.top + (1 - Number(value) / max) * (height - margin.top - margin.bottom);

  for (let tick = 0; tick <= 4; tick += 1) {
    const value = max * tick / 4;
    const line = document.createElementNS(ns, 'line');
    line.setAttribute('x1', margin.left); line.setAttribute('x2', width - margin.right);
    line.setAttribute('y1', y(value)); line.setAttribute('y2', y(value)); line.setAttribute('class', 'grid');
    svg.append(line);
    const label = document.createElementNS(ns, 'text');
    label.setAttribute('x', margin.left - 10); label.setAttribute('y', y(value) + 4); label.setAttribute('text-anchor', 'end');
    label.textContent = `${Math.round(value)} ms`; svg.append(label);
  }

  groups.forEach((group) => {
    const label = document.createElementNS(ns, 'text');
    label.setAttribute('x', x(group)); label.setAttribute('y', height - margin.bottom + 18);
    label.setAttribute('transform', `rotate(35 ${x(group)} ${height - margin.bottom + 18})`);
    label.setAttribute('class', 'x-label'); label.textContent = group; svg.append(label);
    const groupValues = rows.filter((row) => row[groupKey] === group && Number(row.valid)).map((row) => Number(row.php_wall_ms));
    const centre = median(groupValues);
    if (centre !== null) {
      const mark = document.createElementNS(ns, 'line');
      mark.setAttribute('x1', x(group) - 13); mark.setAttribute('x2', x(group) + 13);
      mark.setAttribute('y1', y(centre)); mark.setAttribute('y2', y(centre)); mark.setAttribute('class', 'median'); svg.append(mark);
    }
  });

  rows.forEach((row) => {
    const value = Number(row.php_wall_ms);
    if (!Number.isFinite(value)) return;
    const point = document.createElementNS(ns, Number(row.fault_error_count) ? 'rect' : 'circle');
    if (point.tagName === 'rect') {
      point.setAttribute('x', x(row[groupKey], Number(row.sequence)) - 5); point.setAttribute('y', y(value) - 5);
      point.setAttribute('width', 10); point.setAttribute('height', 10);
    } else {
      point.setAttribute('cx', x(row[groupKey], Number(row.sequence))); point.setAttribute('cy', y(value)); point.setAttribute('r', 5);
    }
    point.setAttribute('fill', Number(row.valid) ? colourMap.get(row.feature_id) || '#66d9ef' : '#73778c');
    point.setAttribute('class', Number(row.fault_error_count) ? 'point fault-error' : Number(row.fault_warning_count) || Number(row.fault_notice_count) ? 'point fault-warning' : 'point');
    point.addEventListener('mouseenter', (event) => showTooltip(event, row));
    point.addEventListener('mouseleave', () => { tooltip.style.display = 'none'; });
    svg.append(point);
  });
  element.append(svg);
}

function showTooltip(event, row) {
  let theme = { slug: '?', version: '?', type: '?' };
  let site = {};
  try { theme = JSON.parse(row.theme_json || '{}'); } catch { /* Old runs stored only a slug. */ }
  try { site = JSON.parse(row.characteristics_json || '{}'); } catch { /* Old runs have no characteristics. */ }
  const feature = String(row.feature_id || '').replaceAll('-', ' ');
  tooltip.innerHTML = `<strong>${escapeHtml(row.page_type || row.feature_id)}</strong><br>Feature: ${escapeHtml(feature)}<br>${escapeHtml(row.site_id)} · plugin ${escapeHtml(row.plugin_version || '?')}<br>${escapeHtml(site.site_type || 'site')} · ${escapeHtml(site.dataset_tier || 'unclassified size')} · ${Number(site.database_mb || 0).toLocaleString()} MB database<br>${Number(site.products || 0).toLocaleString()} products · ${Number(site.variations || 0).toLocaleString()} variations · ${Number(site.orders || 0).toLocaleString()} orders<br>${Number(site.posts || 0).toLocaleString()} posts · ${Number(site.pages || 0).toLocaleString()} pages · ${Number(site.users || 0).toLocaleString()} users<br>${Number(site.taxonomy_relationships || 0).toLocaleString()} taxonomy relationships<br>Theme ${escapeHtml(theme.slug || row.theme_json || '?')} ${escapeHtml(theme.version || '')} · ${escapeHtml(theme.type || 'unclassified')}<br>PHP ${Number(row.php_wall_ms).toFixed(1)} ms · ${row.query_count ?? '?'} queries<br>${row.fault_error_count} errors · ${row.fault_warning_count} warnings · ${row.fault_notice_count} notices`;
  tooltip.style.left = `${event.clientX + 12}px`; tooltip.style.top = `${event.clientY + 12}px`; tooltip.style.display = 'block';
}

function escapeHtml(value) {
  const node = document.createElement('span'); node.textContent = String(value); return node.innerHTML;
}

function renderFaults(rows) {
  const container = document.querySelector('#faults');
  if (!rows.length) { container.innerHTML = '<p class="clean">No faults recorded in this run.</p>'; return; }
  const groups = new Map();
  rows.forEach((fault) => {
    const key = fault.fingerprint;
    if (!groups.has(key)) groups.set(key, { ...fault, count: 0, targets: new Set(), features: new Set() });
    const group = groups.get(key); group.count += 1; group.targets.add(fault.target_id); group.features.add(fault.feature_id);
  });
  container.innerHTML = [...groups.values()].map((fault) => `<details><summary><span class="severity ${escapeHtml(fault.severity)}">${escapeHtml(fault.severity)}</span> ${escapeHtml(fault.message)} <small>${fault.count} samples</small></summary><p>${escapeHtml(fault.file || 'No file')}${fault.line ? `:${fault.line}` : ''}</p><p>Features: ${escapeHtml([...fault.features].join(', '))}</p><p>Targets: ${escapeHtml([...fault.targets].join(', '))}</p></details>`).join('');
}

async function render(run) {
  const [samplesResult, faultsResult, featureResult] = await Promise.all([api('samples', run), api('faults', run), api('features', run)]);
  const samples = samplesResult.rows;
  const map = featureColours(samples);
  scatter(document.querySelector('#frontend-chart'), samples.filter((row) => row.area === 'frontend'), 'page_type', map);
  scatter(document.querySelector('#backend-chart'), samples.filter((row) => row.area === 'backend'), 'target_id', map);
  scatter(document.querySelector('#feature-chart'), samples, 'feature_id', map, featureResult.rows.map((row) => row.feature_id));
  renderFaults(faultsResult.rows);
  const valid = samples.filter((row) => Number(row.valid)).length;
  const errors = samples.reduce((sum, row) => sum + Number(row.fault_error_count), 0);
  const warnings = samples.reduce((sum, row) => sum + Number(row.fault_warning_count) + Number(row.fault_notice_count), 0);
  const measuredFeatures = featureResult.rows.filter((row) => Number(row.attempts) > 0).length;
  document.querySelector('#summary').innerHTML = `<div><strong>${samples.length}</strong><span>samples</span></div><div><strong>${valid}</strong><span>valid</span></div><div><strong>${errors}</strong><span>errors</span></div><div><strong>${warnings}</strong><span>warnings/notices</span></div><div><strong>${measuredFeatures}/${featureResult.rows.length}</strong><span>features measured</span></div>`;
}

api('runs').then((runs) => {
  runSelect.innerHTML = runs.map((run) => `<option value="${escapeHtml(run.id)}">${escapeHtml(run.started_at)} · ${escapeHtml(run.status)}</option>`).join('');
  runSelect.addEventListener('change', () => render(runSelect.value));
  if (runs.length) render(runs[0].id);
}).catch((error) => { document.querySelector('main').innerHTML = `<p class="error">${escapeHtml(error.message)}</p>`; });
