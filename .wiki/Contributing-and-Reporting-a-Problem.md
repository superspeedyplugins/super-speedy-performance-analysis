# Contributing and reporting a problem

## Where to post

**[Issues](https://github.com/superspeedyplugins/super-speedy-performance-analysis/issues)** for something broken: a wrong number, a fatal, a measurement that doesn't match reality, a page that can't be profiled.

**[Discussions](https://github.com/superspeedyplugins/super-speedy-performance-analysis/discussions)** for everything else: how do I interpret this, would you consider measuring X, here's what I found on my site.

If you'd rather talk it through, there's a [Discord](https://www.superspeedyplugins.com/super-speedy-discord/).

## What to attach

**The privacy-safe Markdown report.** Generate it from the results panel, the PA admin-bar menu, or by downloading it from the report. It carries findings, metrics, components and normalised HTTP behaviour, and it strips raw SQL literals, query values, variable identifiers and fulfilment identifiers.

That one file usually answers every question a maintainer would otherwise ask: WordPress and PHP versions, the plugin list with versions, what was measured and what the numbers were.

For a single page, the panel also exports a self-contained JSON diagnostic.

**Please don't paste customer data.** No order contents, no email addresses, no names, no raw SQL from your live database. The Markdown export exists so you don't have to.

## What makes a report easy to act on

The URL or page key, what you expected, what you got, and whether it reproduces. A number that looks wrong is far easier to chase with the report attached than with a screenshot of it.

If the plugin told you something you think is untrue, say which screen said it. Several findings are deliberately conservative, and "no measurable impact" is a real answer rather than a failure to measure.

## Contributing code

The repository is public and GPLv3. Before writing anything substantial, open a Discussion first: some things that look like gaps are deliberate, and a few are already in progress. See [[Roadmap]].

Anything you contribute stays yours under GPLv3, which is worth knowing because it means contributed code can't later be moved into one of the paid plugins.

## Contributing measurements

The most useful contribution isn't code. Opt in to sharing anonymised results and the community database gets better for everyone, including you. Nothing identifiable leaves your site. See [[The-Community-Plugin-Database]].

---

Related: [[Troubleshooting]] · [[Roadmap]] · [[The-Community-Plugin-Database]]
