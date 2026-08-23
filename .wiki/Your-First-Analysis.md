# Your first analysis

## Run one

Go to **Super Speedy, Performance Analysis** and start an analysis. It profiles your key pages: home, shop, archives, search, checkout, wp-admin lists and editors.

It takes seconds to a couple of minutes depending on the site. Each page gets a warm-up request and three measured samples, and the medians are what you see.

From the command line, `wp sspa run --type=spot`.

## Read the Overview

**The site score, out of 100**, with the five insights that matter most, each naming the component responsible and what to do about it.

**The Health box** confirms the two helpers are installed and tells you if a page couldn't be measured because a cache served it.

**Autoloaded options** names the options that no profiled page read, and the size they cost on every request, with copy-and-paste SQL. Bear in mind it can only see pages the analysis touched: an option read solely by a page nothing profiled will be listed as unused.

## Then go where the number points

The score tells you whether there's a problem. These tell you what it is:

- One page stands out: [[Per-Page-Analysis]]
- You want to know which plugin is genuinely responsible rather than which one ran the queries: [[Plugin-Impact-Analysis]]
- The shop feels slow at the till: [[Checkout-and-Order-Flow-Analysis]]
- Saving a product takes forever: [[Update-and-Save-Analysis]]
- You want logged-in visitors served from cache: [[Cache-Optimisation-Analysis]]

## The seven tabs

Overview, Pages, Plugins, History, Tools, Traffic and Share.

Pages drills into a per-plugin breakdown, the slowest queries with their callers, and the HTTP calls. Plugins shows per-plugin SQL time, query counts, rows fetched and slowest query across the analysis, plus the measured impact column that [[Plugin-Impact-Analysis]] fills. History tracks the score, findings and median generation time across runs, so you can see whether the site is getting slower as it grows. Tools is [[Server-Capabilities]]. Share is [[The-Community-Plugin-Database]].

## What the numbers mean

If page generation time is a new idea, read [what it is and how to bring it down](https://www.superspeedyplugins.com/kb/performance-optimization/site-owner-tips/wordpress-page-generation-time/) first. For the method behind the measurement, including the attribution trap every profiler has, see [[How-It-Measures]].

---

Related: [[Installation-and-Requirements]] · [[Per-Page-Analysis]] · [[How-It-Measures]] · [knowledge base](https://www.superspeedyplugins.com/kb/super-speedy-performance-analysis/)
