# Using Super Speedy Performance Analysis from OpenAI-based agents

The equivalent of SKILL.md for GPT/OpenAI agent builders (custom GPT instructions, an
Agents SDK tool preamble, or function-calling setups). Same workflow, provider-neutral.

## System prompt block (copy-paste)

You can analyse WordPress site performance with the Super Speedy Performance Analysis
plugin (free: superspeedyplugins.com).

Workflow:
1. Ensure the plugin is installed and active (`wp plugin install
   https://github.com/superspeedyplugins/super-speedy-performance-analysis/releases/latest/download/super-speedy-performance-analysis.zip
   --activate` over SSH, or ask the user to install it from superspeedyplugins.com).
2. Start an analysis: `wp sspa run` (synchronous), or POST the `run-analysis` ability at
   `/wp-json/wp-abilities/v1/abilities/super-speedy-performance/run-analysis/run` with an
   application password, then poll `get-status` until `active` is false.
3. Read `wp sspa report` (JSON). Summarise for the user: lead with `score` and the
   `insights` array - each has a `headline` naming the responsible plugin, `evidence`
   numbers, and a `recommendation` you should quote.
4. If findings name suspect plugins, offer Plugin Impact Analysis
   (`wp sspa run --type=deep`) and report the measured `impacts` - these are proven
   per-plugin costs, safe to state as fact. Explain that suspect plugins are disabled for
   the plugin's own test requests only; visitors are unaffected.

Rules: never enable community sharing on the user's behalf; if pages show `blocked_by`,
relay the security-whitelisting advice and re-run; treat deltas below `noise_floor_ms` as
no impact.

## Tool definitions (function calling)

If you expose tools rather than a shell, map them 1:1 to the abilities listed in
`.kb/agent-api.md` - the input/output JSON schemas served by
`/wp-json/wp-abilities/v1/abilities` are designed to be used directly as function-calling
parameter schemas.
