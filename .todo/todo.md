# Super Speedy Performance Analysis - todo

- [ ] **Compare timings with Query Monitor** (Dave): profile the same pages with our
      profiler and real Query Monitor (its actual QM_DB drop-in, installed from wp.org in
      the docker env) and compare SQL count / SQL time / row totals / page generation time.
      Confirms our numbers are trustworthy and quantifies our profiling overhead vs QM's.
      The automated version of this is the unticked "real-QM cross-check" item in
      `.docs/implementation-plan.md` Phase 1 / `.tests/README.md` "not yet covered".
