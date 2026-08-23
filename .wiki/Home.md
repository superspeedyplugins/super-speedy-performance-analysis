# Super Speedy Performance Analysis

Free performance analysis for WordPress and WooCommerce. It doesn't tell you your site is slow. It tells you which plugin is making it slow, on which page, by how many milliseconds, and it proves the number by measuring rather than guessing.

Install it, run one analysis, and you get a site score out of 100 plus the five things costing you the most, each naming the plugin or theme responsible and what to do about it.

**[Download the latest release](https://github.com/superspeedyplugins/super-speedy-performance-analysis/releases/latest)** · [[Installation-and-Requirements]] · [[Your-First-Analysis]]

## What you get to see

| | What it shows you | Why that's worth having |
|---|---|---|
| [[Per-Page-Analysis]] | For one exact URL: where the time went by request phase, every plugin's share of it, the slowest queries with the code that ran them, the outbound HTTP calls, and the individual PHP functions by self time | The blame is allocated correctly. A plugin is never charged for a shared library another plugin called into, and you can switch between which code *ran* and which plugin *asked* for the work |
| [[Checkout-and-Order-Flow-Analysis]] | The server time your customer waits at every step of one real purchase, split at the moment the money is taken, then the shop owner's side: opening the order and marking it completed | A checkout is a chain of POSTs and redirects, not a page render, so it's the part of a shop nobody watches end to end. When you can see it, you know which plugin to change |
| [[Update-and-Save-Analysis]] | The real Update or Save on a post, page, product or WooCommerce order, profiled as the write request itself | WordPress saves over ajax or REST and then reloads the editor, so a 20 second `save_post` callback hides in a request nothing was watching |
| [[Plugin-Impact-Analysis]] | Each plugin's true cost, per page, measured by disabling it and re-measuring, including plugins that turn out to make your site **faster** | Every other tool infers cost from attribution. This measures the difference. Nothing is ever really deactivated: suspects are disabled for the analysis's own test requests only, and visitors always get the full site |
| [[Cache-Optimisation-Analysis]] | What stops you serving a full-page cache to logged-in customers and to visitors with something in the basket: the visitor-specific cookies and nonces by name, your existing cache coverage, the private surfaces, and ranked candidates for the source of each | Switching full-page caching on for logged-in users is otherwise guesswork followed by a support ticket |
| [[The-Community-Plugin-Database]] | Per-plugin evidence profiles on [superspeedy.org](https://superspeedy.org/plugins/), built from anonymised results across real sites | "Is this plugin a problem?" has no measured, cross-site answer anywhere else. Sharing is opt-in, the payload is shown to you before it's sent, and your site is a random ID |

## Free, and no licence key

GPLv3, no key, no gate on the download. Once it's installed it checks for its own updates, so new versions appear on your Plugins screen like any other update.

Zero overhead on normal traffic. The capture layer stays inert unless a request carries a signed profiling token, and the two helpers it installs (an mu-plugin loader and a conditional `db.php` drop-in) are removed cleanly when you deactivate it.

## Drive it without the GUI

Everything works from the command line and from an AI agent: `wp sspa run`, `wp sspa findings`, `wp sspa impacts`, `wp sspa report` and more, JSON throughout, plus a WordPress Abilities API surface on WordPress 6.9+.

[[WP-CLI-Reference]] · [[AI-Agents-and-MCP]]

## Where to go next

- Something specific is slow: [[Per-Page-Analysis]]
- The whole site is slow and you don't know why: [[Your-First-Analysis]], then [[Plugin-Impact-Analysis]]
- Your shop feels slow at the till: [[Checkout-and-Order-Flow-Analysis]]
- You want to cache pages for logged-in customers and basket holders: [[Cache-Optimisation-Analysis]]
- Editing a post or product takes forever to save: [[Update-and-Save-Analysis]]
- You want the deepest detail there is: [[Server-Capabilities]]
- Something isn't working: [[Troubleshooting]]
- Where this is going, and how to influence it: [[Roadmap]]

Longer guides live in the [knowledge base](https://www.superspeedyplugins.com/kb/super-speedy-performance-analysis/). If you're new to any of this, start with the [performance quick start guide](https://www.superspeedyplugins.com/kb/performance-optimization/solving-wordpress-performance-problems/) and [what page generation time actually is](https://www.superspeedyplugins.com/kb/performance-optimization/site-owner-tips/wordpress-page-generation-time/).
