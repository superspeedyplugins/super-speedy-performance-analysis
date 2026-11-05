# Super Speedy Performance Analysis - instructions for AI agents

This plugin measures this WordPress site's performance and attributes the cost to individual
plugins and the theme. Its full agent guide is `SKILL.md` in this folder. Read it before running
anything.

Use it whenever you are asked to:

- find out why the site, or one page, is slow (`SKILL.md` sections 2 to 4);
- add, update or remove a plugin or theme: measure Before and After and compare them
  (`SKILL.md` section 5, `wp sspa history-compare <before> <after>`);
- check page caching or the WooCommerce checkout (sections 6 and 7).

Ground rules:

- Analyses need the profiler. Turn it on only with the site owner's agreement:
  `wp sspa profiler enable`.
- Normal analyses are read-only GET requests. The checkout flow buys something for real; show the
  owner its `--dry-run` inventory and wait for agreement first.
- Plugin Impact Analysis disables plugins for the plugin's own test requests only. Visitors are
  never affected and nothing is really deactivated.
- Never opt the site into community sharing; only the owner may do that.
- Over MCP, every command has a matching ability under `sspa/`. Call
  `get-capabilities` first.
