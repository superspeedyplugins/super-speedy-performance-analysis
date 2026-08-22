# Super Speedy Performance Analysis

Free performance analysis for WordPress and WooCommerce. It does not tell you your site is
slow. It tells you **which plugin is making it slow, on which page, by how many milliseconds**,
and it proves the number by measuring rather than guessing.

Install it, run one analysis, and you get a site score out of 100 and the five things costing
you the most, each naming the plugin or theme responsible and what to do about it.

**[Download the latest release](https://github.com/superspeedyplugins/super-speedy-performance-analysis/releases/latest)** ·
[[Installation-and-Requirements]] · [[Your-First-Analysis]]

## What you get to see

| | What it shows you | Why that is worth having |
|---|---|---|
| [[Per-Page-Analysis]] | For one exact URL: where the time went by request phase, every plugin's share of it, the slowest queries with the code that ran them, the outbound HTTP calls, and the individual PHP functions by self time | The blame is allocated correctly. A plugin is never charged for a shared library that another plugin called into, and you can switch between which code *ran* and which plugin *asked* for the work |
| [[Checkout-and-Order-Flow-Analysis]] | The server time your customer waits at every step of one real purchase, split at the moment the money is taken, then the shop owner's side: opening the order and marking it completed | A checkout is a chain of POSTs and redirects, not a page render, so it is the part of a shop nobody has ever watched end to end. When you can see it, you know which plugin to change |
| [[Update-and-Save-Analysis]] | The real Update/Save on a post, page, product or WooCommerce order, profiled as the write request itself | WordPress saves over ajax or REST and then reloads the editor, so a twenty-second `save_post` callback hides in a request nothing was watching. This one profiles the save in the background and captures all of it |
| [[Plugin-Impact-Analysis]] | Each plugin's true cost, per page, measured by disabling it and re-measuring - including plugins that turn out to make your site **faster** | Every other tool infers cost from attribution. This measures the difference. Nothing is ever really deactivated: suspects are disabled for the plugin's own test requests only, and visitors always get the full site |
| [[Cache-Optimisation-Analysis]] | What is stopping you serving a full-page cache to logged-in customers and to visitors with something in the basket: the visitor-specific cookies and nonces by name, your existing cache coverage, the genuinely private surfaces, and ranked candidates for the source of each | Turning on full-page caching for logged-in users is normally guesswork followed by a support ticket. This is the evidence you need before you do it |
| [[The-Community-Plugin-Database]] | Public per-plugin evidence profiles on [superspeedy.org](https://superspeedy.org/plugins/), built from anonymised results across real sites | "Is this plugin a problem?" has never had a measured, cross-site answer. Sharing is opt-in, the payload is shown to you before it is sent, and your site is a random ID |

## Free, and no account

GPLv3, no licence key, no gate on the download. Once installed it checks for its own updates,
so new versions appear on your Plugins screen like any other update.

Zero overhead on normal traffic: the capture layer stays completely inert unless a request
carries a signed profiling token, and the two helpers it installs (an mu-plugin loader and a
conditional `db.php` drop-in) are removed cleanly when you deactivate it.

## Drive it without the GUI

The whole plugin works from the command line and from an AI agent: `wp sspa run`,
`wp sspa findings`, `wp sspa impacts`, `wp sspa report` and more, JSON throughout, plus a
WordPress Abilities API surface on WordPress 6.9+.

[[WP-CLI-Reference]] · [[AI-Agents-and-MCP]]

## Where to go next

- Something specific is slow: [[Per-Page-Analysis]]
- The whole site is slow and you do not know why: [[Your-First-Analysis]], then [[Plugin-Impact-Analysis]]
- Your shop feels slow at the till: [[Checkout-and-Order-Flow-Analysis]]
- You want the deepest possible detail: [[Server-Capabilities]]
- Something is not working: [[Troubleshooting]]
- Where this is going, and how to influence it: [[Roadmap]]

Long-form guides, including how to read the numbers and the attribution trap every profiler
has, are in the
[knowledge base](https://www.superspeedyplugins.com/kb/super-speedy-performance-analysis/).
