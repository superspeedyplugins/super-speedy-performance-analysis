# One page view everywhere, and plugin impact per page

Status: **design, nothing built**, 2026-08-10. Written from Dave's brief.

Two related complaints:

1. "Analyse this page" renders a rich result panel that exists nowhere else. Expanding a row on
   the Pages tab shows far less, for the same underlying data.
2. Plugin impact is a whole-site operation, when the question a user actually has is usually
   about one page.

## 1. What exists today

The data is identical; only the presentation differs.

`adhoc` runs go through the **same** pipeline as `baseline`/`spot` - same crawler, same capture,
same 1 warm-up + 3 samples, same `SSPA_Profile_Store::save()`, and the same completion path at
`SSPA_Run_Controller::finish()` (line 944 falls through to the shared `else`). The only divergence
in `start()` is how many jobs get queued.

What differs is downstream:

| | Analyse this page | Pages tab drill-down |
|---|---|---|
| Renderer | `SSPA_Adhoc::ajax_result()`, bespoke | `ajax_page_detail()` + JS in `sspa-admin.js` |
| Shows | top 5 slow queries, boot phases, Excimer, Cloudflare state | components, slow queries, HTTP calls, boot |
| Reached from | admin bar, any page | Pages tab row click |

So there are two renderers over one dataset, and each shows a slightly different subset. That is
the whole defect: nobody decided they should differ.

## 2. Unify on one renderer

One profile view, used by both entry points, taking a `profile_id`:

- request phases and the PHP floor breakdown (already in `boot`)
- per-component attribution for that page, both attribution modes
- slowest queries with their `EXPLAIN` note
- blocking HTTP calls
- Excimer function detail when present
- **measured plugin impact for this page** (see section 3), when it exists
- the page's object-cache figures

`SSPA_Adhoc::ajax_result()` then becomes a thin wrapper that resolves a URL to its newest
`profile_id` and calls the same renderer. The Pages tab row click calls it directly.

Consequences worth taking deliberately:

- The "see this in Performance Analysis" link in the adhoc panel becomes redundant and should go.
  With a unified panel there is nothing extra to go and see.
- The renderer must be a **PHP** partial returning HTML, not more JS string-building. The current
  drill-down builds its markup in `sspa-admin.js` with `html +=` concatenation, which is why the
  two views drifted. One template, called from both AJAX handlers, cannot drift.

## 3. Plugin impact for one page

Currently plugin impact is only reachable as a whole-site sweep. The user's real question is
usually "what is making *this* page slow", and they are already looking at that page's results.

**Proposal:** on the unified panel, next to the existing re-run control, add
**Measure plugin impact on this page**. It starts a sweep scoped to that page's `page_key`.

This is now cheap. `build_sweep_jobs()` honours `page_keys` (0.12.7), so one page costs
`6 x plugins` measurements rather than `6 x plugins x 36 pages`. For a single suspect that is 6
measurements; for every eligible plugin on a page it is roughly 6 x N.

Requirements:

- Show the estimate before starting: "24 plugins x 1 page = 144 measurements, about 5 minutes."
  The 72 -> 216 surprise was a missing estimate, not a wrong number.
- Offer both "just this page" and "every plugin on this page".
- Results land in the same panel, using the newest-per-plugin-per-page selection added in 0.13.0,
  so a page-scoped sweep updates that page and leaves every other page's verdict standing.

## 4. Checkout, and the other admin flows

Checkout is not a page, it is a stateful journey, and Dave has already named the next two:
**edit-product flow** and **edit-order flow** - the wp-admin tasks a shop owner actually spends
their day in. So "flow" is a category, not a special case, and the UI should stop treating
checkout as a one-off.

A flow is: an ordered list of steps, each a request, sharing state (cart, nonces, an order id),
with a defined setup and teardown. `SSPA_Checkout_Flow` already implements exactly that shape;
the work is generalising it rather than inventing anything.

Plugin impact across a flow is the interesting and expensive case:

- **Value:** "deactivating this plugin makes placing an order 900ms faster" is the single most
  saleable number the plugin could produce.
- **Cost:** each cell is a whole journey, not a request. A flow of 10 steps re-run per plugin per
  cache mode is an order of magnitude more work than a page sweep.
- **Risk:** each journey creates and deletes a real order. Doing that per plugin multiplies the
  side effects, even though they are cleaned up.

Recommendation: **do not** offer per-plugin flow impact in the first cut. Offer it for a
**chosen shortlist** of plugins (the ones the flow's own attribution already blames), never "all
plugins". The scoping work in section 3 is the prerequisite.

## 5. Naming

`Deep Analysis` becomes **Plugin Impact Analysis**, matching the "Measured impact" column it
fills. Keep `run_type = 'deep'` in the database - the rename is a label, and changing stored run
types would break every historical row and the community payload's `run_type` field.

The taxonomy this leaves:

| Action | Question it answers |
|---|---|
| Analyse (this page / key pages) | Where did the time go? |
| Analyse a flow (checkout, edit product, edit order) | Where did the time go across a journey? |
| Plugin Impact Analysis (page / site / flow) | What would change without this plugin? |

The first two are the same operation at different scopes and should look like it. The third is the
only one that alters the site to measure a counterfactual, and should be visibly separate.

## 6. Open questions

1. **Un-quarantining adhoc.** Overview, Pages and the Plugins table filter to
   `run_type IN ('baseline','spot')`, so a one-page check never becomes the site score. With
   catalogue-matched page keys (0.13.0) an adhoc run of the shop page now files under `shop`, so
   its *per-page* data could legitimately merge into the Pages tab. The **site score** must still
   come from a full run - a score computed over one page is not a site score. Suggested split:
   per-page views merge on newest-per-page; score and component totals stay baseline/spot only.
2. Does the unified panel need both attribution modes, or does one page make caller mode the
   obvious default?
3. For flows, is the unit of impact the whole journey, or per step? Per step is more useful and
   much more expensive to render.
