# Run Analysis review (24 Jul 2026) - open questions (future work)

Status: the parked remainder of the July 2026 Run Analysis walkthrough. Every fix in that
review shipped in 0.8.0 / 0.9.1; these are the questions it left for Dave.
Source doc: `run-analysis-review-2026-07.md`, archived at
`.docs/archive/run-analysis-review-2026-07.md`.

## Open questions for Dave (verbatim)

1. **Hub ranking semantics**: should the community submission payload flag
   replacement-category plugins (search/filter) so superspeedy.org ranks them by measured
   impact rather than attributed SQL? Otherwise the hub will reproduce the same distortion
   at scale. (Context: the attribution trap - a search replacement plugin "owns" the entire
   search SQL time precisely because it is doing the site's heaviest job faster than core
   would. Blocked on the hub existing; see `.docs/superspeedy-org-hub-launch.md`.)
2. **Baseline sample count**: 3 samples with a 1-request warm-up is thin for wp-admin
   pages on busy sites. An adaptive scheme (keep sampling until spread stabilises, cap
   at ~7) would tighten gates at modest request cost. Worth it?

Question 3 of the original (bisection ignoring savings) was resolved on 24 Jul 2026 -
savings became first-class results, then the 0.8.0 sweep redesign retired bisection
entirely.
