# Implementation plan - parked remainder (future work)

Status: the parked remainder of the phased implementation plan. Phases 0-6 shipped
(0.1.0 through 0.7.x); this doc carries the items that did not.
Source doc: `implementation-plan.md`, archived at `.docs/archive/implementation-plan.md`.

## Phase 7 - Launch & later (verbatim from the source doc)

- [ ] Public repo polish: README with screenshots, CONTRIBUTING (rules-data PRs), licence,
      issue templates
- [ ] superspeedy.org promotion pages + methodology page (hub side)
- [ ] Hub: fast-ajax mu acceleration for hot routes, public plugin/category pages + charts,
      LLM classifier + review queue, anti-abuse reputation (companion doc sections 4-6)
- [ ] Parked: SMTP sink full-send mode, deliverability bucket (synthetic only), sector
      benchmark pages, wp.org listing decision, multisite

See also `.docs/superspeedy-org-hub-launch.md`, which tracks the hub-launch checklist in
detail and supersedes the hub bullets above where they overlap.

## Unticked items from completed phases (verbatim)

From Phase 1 (capture engine):

- [ ] Cross-check test: same page profiled by us and by QM (with its symlink) - sql count,
      sql time and row totals within tolerance

From Phase 5 (community):

- [ ] Settings-snapshot opt-in (feature detection data; allowlisted keys, bucketed values)

Context kept from the source doc: settings-snapshot opt-in was deferred because it needs the
hub classifier's settings_map (phase 7) to know which option keys are safe/useful; shipping
it without the map would collect nothing. Registry publication of SKILL.md and the "bare
Claude session" acceptance run were noted as launch-week actions for Dave (phase 7).
