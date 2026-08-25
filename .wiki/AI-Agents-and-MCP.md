# AI agents and MCP

The whole plugin is drivable without the GUI. That's deliberate: the useful shape of this tool is an agent that measures the site, reads the findings, changes something and measures again.

## Three surfaces, one set of behaviour

**WP-CLI.** Everything in [[WP-CLI-Reference]], JSON throughout.

**The WordPress Abilities API**, on WordPress 6.9 and later. Category `super-speedy-performance`, with abilities including `get-status`, `get-report`, `get-findings`, `get-plugin-impacts`, `get-site-metrics`, `run-analysis`, `run-deep-analysis`, `run-checkout-flow`, `get-checkout-flow` and `submit-results`.

**MCP**, through the one shared Super Speedy bridge. No AI Engine and no separate WordPress MCP adapter plugin required.

## Instructions your agent can read

Two files ship in the repository:

- [`SKILL.md`](https://github.com/superspeedyplugins/super-speedy-performance-analysis/blob/main/SKILL.md) teaches a Claude-based agent the install, run and interpret workflow.
- [`.kb/agent-instructions-openai.md`](https://github.com/superspeedyplugins/super-speedy-performance-analysis/blob/main/.kb/agent-instructions-openai.md) is the OpenAI equivalent.
- [`.kb/agent-api.md`](https://github.com/superspeedyplugins/super-speedy-performance-analysis/blob/main/.kb/agent-api.md) documents the report schema, including the delta sign convention.

Point your agent at the one that matches it rather than describing the plugin to it yourself.

## A worked loop

1. `wp sspa run --type=spot` to get a current picture.
2. `wp sspa findings --format=json` and act on the highest-severity finding.
3. `wp sspa run --type=deep --suspects=<slug> --pages=<key>` to measure whether the suspect is really the cost, before changing anything.
4. Make the change.
5. Repeat step 1 and compare on the History tab.

Step 3 is the one agents skip and shouldn't. Attribution is inference; measured impact is the number that survives an argument.

## Guard rails

Abilities that change the site are separated from abilities that read it, and the read set is the default. Running a checkout flow over MCP requires explicit consent, because it buys something and sends email.

Ability permissions use two levels rather than one, so an agent can be given the read surface without the run surface.

## Handing a result to something else

`sspa/llm-report@1` is a privacy-safe Markdown document of a page, site or checkout result. It strips raw SQL literals, query values, variable identifiers and fulfilment identifiers, and keeps findings, metrics, components and normalised HTTP behaviour. Generate it from the results panel, the PA admin-bar menu, or by downloading it from the report.

That's the document to paste into an LLM, send to your host, or attach to a support thread. See [[Contributing-and-Reporting-a-Problem]].

## Requirements and limits

The Abilities API surface needs WordPress 6.9+. WP-CLI works on any supported version.

An agent can measure and report without a licence key. Installing the rest of the Super Speedy range from the agent needs a free key, which is the only thing the key does. See [[Installation-and-Requirements]].

---

Related: [[WP-CLI-Reference]] · [[The-Community-Plugin-Database]] · [knowledge base: controlling Scalability Pro with MCP](https://www.superspeedyplugins.com/kb/scalability-pro/mcp-ai-agent-control/)
