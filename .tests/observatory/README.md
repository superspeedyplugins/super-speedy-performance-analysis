# E2E observatory

The observatory records correlated PHP timings, query counts, HTTP status, browser correctness
failures, PHP faults and database faults from retained native parallel-dev WordPress sites. Its
SQLite database and request envelopes live under the plugin's gitignored `.data/e2e-observatory/`.

## Scalability Pro version matrix

From this directory:

```bash
npm ci
npm run observatory:prepare -- spro-version-matrix
npm run observatory:run -- spro-version-matrix
npm run observatory:view
```

Preparation creates any missing sites, installs the exact historical Git builds declared in
`spro-version-matrix.yml`, installs the permanent recorder and shared fixture, activates the
declared themes, seeds 25 machines, and verifies the installed plugin versions. It clears fixture
posts on entry and never tears a site down afterwards.

The matrix measures the same six journeys against:

- SPRO 5.71, the legacy control;
- SPRO 6.29.26, the current customer download despite the update feed offering 6.29.24;
- the current SPRO working tree.

Every run performs two warmups and seven recorded requests for each of 18 targets. The viewer is
available at `http://127.0.0.1:8791/` while `observatory:view` is running.

The viewer starts with one x-axis column per feature. Click a feature label or point to open the
key pages which exercised it. Build/version and point-state keys isolate a series or fault class.
In the page view, clicking one request lists every repetition from the same page/build cell and
highlights the selected request. Ctrl-click on Windows/Linux or Command-click on macOS adds another
page/build cell for comparison. The URL fragment retains the current local view without including
captured evidence values.

## Other manifests

Use the same three commands with `scalability-pro` for the original single-plugin scan or
`fleet-mvp` for the first cross-plugin scan. A manifest may give each site its own `plugin_slug`,
theme, release label and historical `plugin_ref`.

## Tests

```bash
npm test
npm run test:viewer
```

These validate the manifests, signed recorder envelope, invalid-signature rejection and fatal-error
recording, plus the viewer's filter/selection model. `test:viewer` creates a temporary database
through the real observatory schema and drives the feature drill-down, keys, repeated-request
evidence and keyboard navigation in Chromium. The repeated-request browser assertion was
mutation-tested: narrowing the list to the exact clicked sample made it fail `1 !== 2`.
