# .update-server - the plugin's update-server metadata seed

**What this folder is:** the source of `<slug>.json`, the Plugin Update Checker metadata
file served from `https://www.superspeedyplugins.com/assets/plugins/<slug>.json`. It is
what customers' sites read to decide whether an update exists, and its
`sections.description` is the text people see in the update modal on their Plugins
screen.

**Lifecycle:** this file is uploaded ONCE by hand to seed the update server. From then
on Dave's deployment system owns `version` and `last_updated` - which is why `version`
stays at the placeholder `0.1` here (always lower than any real release, so the
deployment always rewrites it and can never no-op). If the update-modal text needs
changing, edit `sections.description` here and re-upload; the deployment keeps
rewriting the version fields on top.

**What this folder is NOT:**

- NOT a build folder (that is `.build/` in plugins that have one, e.g.
  super-speedy-imports). No zips are built here and none belong here.
- NOT where deployment runs from. The deployment system reads the server's copy, not
  this folder.
- NOT release artefacts. Nothing in here ships inside the plugin zip (dot-folders are
  stripped from zips and from lazyprod deploys).

Convention decided 2026-08-05: every plugin repo carries its seed here, named
`.update-server/` - deliberately NOT `.release`/`.releases` (sounds like artefacts) and
NOT `.build` (is something else entirely).
