# Optional capability matrix

This matrix needs Dave's direct approval under the workspace system-dependent testing exception.
It builds actual XHProf 2.3.10, SPX 0.4.22 and OpenTelemetry 1.4.1 extensions in PHP 8.3,
without installing packages on the host. OpenTelemetry is tested as a detected optional agent;
no telemetry exporter or external account is configured. MariaDB 10.11 runs separately with
performance_schema both on and off, and accounts with and without permission to read it.

Build the Dockerfile in this directory as `sspa-capability-matrix:20260915`. Then run `setup.sh`
with `SSPA_MATRIX_CORE` set to pristine WordPress and `SSPA_MATRIX_WOO` to local WooCommerce
source. `SSPA_MATRIX_DOCKER` selects the Docker executable if it is not `docker` on PATH.
Run `run.sh` with the same Docker setting. Both scripts exit nonzero on failures.

Three retained containers exercise absent extensions/off schema, absent extensions/blocked
schema, and installed extensions/readable schema. `probe.php` loads in actual WordPress via
WP-CLI, renders the actual Tools template, and checks each status and installation-guidance row.
It checks HPOS, optional agent detection and executable installation calls. It writes HTML and
JSON plus assertion logs under the invoking plugin's ignored `.data/capability-matrix`.

Containers named `sspa-capability-*`, their databases and evidence remain after execution.
Setup refreshes source on re-entry. Generated credentials remain in the ignored data directory.
Ordinary regression suites and package smoke tests continue to use native parallel-dev sites.
