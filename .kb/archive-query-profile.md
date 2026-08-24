# The Archive Query Profile

This is the definitive contract for the archive query profile: the structure Super Speedy Performance Analysis emits describing what your archive pages actually filter and sort by, and the database indexes that would make them fast. It is what Super Speedy Archives reads to configure itself, and it is stable enough to build against.

If you only want to know what the feature does, the short version is this: your category, tag, shop, product-category, custom-post-type and custom-taxonomy archives each run a database query, and the plugin records exactly how that query filters and sorts. From that it works out the one index that would serve both at once.

## Why an archive is slow in the first place

`wp_posts` is already indexed on `post_date`. So why is a category archive sorted by date slow?

Because the moment the query joins `term_relationships` to filter by term, that index stops being usable for the sort. The filter lives in one table and the sort lives in another, so the database finds every matching row first and *then* sorts them - every request, however many rows matched.

:::callout{variant="did-you-know"}
This is why an archive gets slower as your catalogue grows even though nothing about the page changed. Ten products sort instantly. Fifty thousand do not. The query plan was always doing the same wrong thing; there just were not enough rows for it to matter.
:::

An index carrying the term columns **and** the sort column together removes that second step:

```
(post_type, post_status, taxonomy, topterm_id, post_date)
```

Which index your site needs depends on what your theme and plugins actually sort by, and that is not knowable from any plugin's code. It has to be measured. That is what this profile is. [Methodology](https://www.superspeedyplugins.com/kb/super-speedy-performance-analysis/advanced/methodology/) covers how the measurement itself works.

## Getting it

```php
$profile = SSPA_Report::archive_profile();   // latest completed run
$profile = SSPA_Report::archive_profile( 412 );
```

Or as an agent ability, also available over MCP:

```
super-speedy-performance/get-archive-profile   { "run_id": 412 }
```

Returns a `WP_Error` when no completed run exists.

## Versioning

The profile carries its own `schema`, currently **1**, from `SSPA_Archive_Profile::SCHEMA`. It is independent of the report schema in `docs/agent-api.md`. New fields may be added without a bump; a field changing meaning or disappearing bumps it.

## The whole structure

```json
{
  "run_id": 35,
  "schema": 1,
  "complete": true,
  "pages_seen": 2,
  "predates_contract": 0,
  "archives_worth_indexing": 1,
  "thresholds": { "min_rows": 200, "slow_ms": 50 },
  "archives": [
    {
      "page_key": "product-cat",
      "main": true,
      "post_type": ["product"],
      "taxonomy": ["product_cat"],
      "taxonomy_excluded": ["product_visibility"],
      "found_rows": 34,
      "rows_returned": 12,
      "ms": 8.865,
      "cached": false,
      "qualifies": ["filesort"],
      "worth_indexing": false,
      "explain": {
        "scan": false,
        "filesort": true,
        "key": "ixAttributeTax",
        "est_rows": 67,
        "est_rows_total": 67
      },
      "orderby": [
        { "source": "posts_column", "table": null, "column": "menu_order",
          "meta_key": null, "order": "ASC", "cast": null },
        { "source": "posts_column", "table": null, "column": "post_title",
          "meta_key": null, "order": "ASC", "cast": null }
      ],
      "orderby_requested": [
        { "by": "menu_order", "order": "ASC", "meta_key": null },
        { "by": "post_title", "order": "ASC", "meta_key": null }
      ]
    }
  ],
  "candidate_composites": [
    {
      "columns": ["post_type", "post_status", "taxonomy", "topterm_id",
                  "menu_order ASC", "post_title ASC"],
      "taxonomy": ["product_cat"],
      "seen_on": ["product-cat"],
      "max_rows": 34
    }
  ],
  "materialise": {
    "postmeta": {
      "_price": { "sql_type": "decimal", "confidence": "sampled",
                  "sampled": 500, "nulls": 3, "used_for": ["order", "filter"] }
    },
    "other_table": {
      "wc_product_meta_lookup.total_sales": { "sql_type": "bigint",
                  "confidence": "schema", "used_for": ["order"] }
    },
    "posts_columns": ["menu_order", "post_title"]
  },
  "largest_archive_rows": 75,
  "unsupported": [ { "page_key": "search-many", "reason": "search" } ]
}
```

## Top level

| Field | Meaning |
|---|---|
| `run_id` | The analysis run this was derived from. |
| `schema` | Contract version. See above. |
| `complete` | `false` when any profiled page failed, was blocked, hit the per-request cap, or predates the contract. **Treat `false` as insufficient evidence, never as "nothing needed".** |
| `pages_seen` | Pages that contributed. |
| `predates_contract` | Profiles from before this feature existed, which carry no archive data. Counted separately because "nothing was measured" and "there was nothing there" are different answers. |
| `archives[]` | Every archive query that ran, whether or not it needs an index. |
| `candidate_composites[]` | The indexes to create. The actionable output. |
| `archives_worth_indexing` | How many of them clear both bars below. |
| `thresholds{}` | The size and time bars actually applied, so they are visible rather than implied. |
| `materialise{}` | Columns that must exist before those indexes can be built. |
| `largest_archive_rows` | The biggest archive measured. |
| `unsupported[]` | Archives that cannot be helped, and why. |

## archives[]

| Field | Meaning |
|---|---|
| `page_key` | Stable key for the page, comparable across runs. |
| `main` | Whether this was the page's main query. Widget and shortcode loops appear too. |
| `post_type[]` | Post types queried. |
| `taxonomy[]` | Taxonomies that **narrow** the query. |
| `taxonomy_excluded[]` | Taxonomies that only **exclude**. See below - the distinction matters. |
| `found_rows` | Total matching the archive, not the page size. `null` when WordPress did not compute it (`no_found_rows`). |
| `rows_returned` | Posts this query actually returned. Always present, so there is a size signal even when `found_rows` is `null`. |
| `ms` | Measured SQL time, or `null` when the query was answered from the object cache. |
| `cached` | `true` when it was served from the object cache and never reached the database. |
| `explain` | The query plan. `null` when no plan could be read. |
| `orderby[]` | The **resolved** ordering, in order. |
| `orderby_requested[]` | The ordering as the query asked for it, before plugins resolved it. |
| `qualifies[]` | Why this archive's plan is poor: `ms`, `filesort`, `scan`, `est_rows`. Empty is fine. |
| `worth_indexing` | Whether an index is worth building. **Only these feed the recommendations.** |

### Narrowing versus excluding taxonomies

`IN` narrows to a term and belongs in the index prefix. `NOT IN` excludes, and is served by a `NOT EXISTS` subquery rather than a leading index column.

This is not a nicety. WooCommerce attaches a `product_visibility NOT IN` clause to **every** product query. Treating that as a filter would prefix every index on a WooCommerce store with the one taxonomy that never narrows anything, producing an index that helps nothing.

### explain

| Field | Meaning |
|---|---|
| `scan` | The plan reads the table with no usable index. |
| `filesort` | The database sorts the matched rows. This is the thing a composite index removes. |
| `key` | The index actually chosen, or `null`. |
| `est_rows` | Rows the optimiser expects to examine on the worst table. An **estimate**, not a measurement. |
| `est_rows_total` | The same across all tables in the plan. |

The plan is read from the statement the query built, whether or not the query reached the database - so it is still available on a site with a persistent object cache, where a warm archive never touches MySQL at all.

### orderby[] - the ordering tuple

Reported in order, because **that order is the index's column order**.

| Field | Meaning |
|---|---|
| `source` | `posts_column`, `postmeta`, `other_table` or `expression`. |
| `table` | The joined table, for `other_table`. `null` otherwise. |
| `column` | The column being sorted on. |
| `meta_key` | The resolved meta key, for `postmeta`. |
| `order` | `ASC` or `DESC`, per term. |
| `cast` | The cast the query applied (`DECIMAL`, `UNSIGNED`, `NUMERIC`, …) or `null`. |

`order` is part of the index definition, not a detail: MySQL 8 serves a mixed `ASC`/`DESC` tuple only from an index declared with matching directions.

`other_table` is not an edge case. It is how every WooCommerce catalogue sort works:

| Requested | Resolves to |
|---|---|
| `price` | `wc_product_meta_lookup.min_price ASC, product_id ASC` |
| `popularity` | `wc_product_meta_lookup.total_sales DESC, product_id DESC` |
| `rating` | `wc_product_meta_lookup.average_rating DESC, rating_count DESC` |

:::callout{variant="did-you-know"}
WooCommerce applies catalogue ordering late, at `posts_clauses`, so `orderby=price` never appears in the query variables as a column at all. That is why both the requested and the resolved forms are recorded: the requested form tells you the intent, the resolved form tells you the column an index has to be built on. Neither alone is enough.
:::

## candidate_composites[]

The index to create: filter columns first, then the ordering columns, each carrying its direction.

| Field | Meaning |
|---|---|
| `columns[]` | Column list in index order. Ordering columns are suffixed ` ASC` or ` DESC`. |
| `taxonomy[]` | Which taxonomies this index serves. Empty means it carries no term columns. |
| `seen_on[]` | The page keys that would use it. |
| `max_rows` | The largest archive it serves. |

Meta columns appear under the mirror-table naming, `meta_` plus the sanitised key, so `_price` becomes `meta__price`.

## materialise{}

What must exist as a real, typed column before the composite can be built.

`postmeta{}` is keyed by meta key, `other_table{}` by `table.column`, and `posts_columns[]` lists the `wp_posts` columns used.

| Field | Meaning |
|---|---|
| `sql_type` | One of `varchar`, `bigint`, `decimal`, `float`, `int`, `datetime`, `date`, `bit`. |
| `confidence` | See below. |
| `sampled` / `nulls` | Sample size and empty values seen, for sampled verdicts. |
| `used_for[]` | `order`, `filter`, or both. |

### Why the type matters more than anything else here

**An index cannot serve an `ORDER BY` on a `CAST`.** Sorting `CAST(meta_value AS DECIMAL)` sorts the rows however good your index is, because the value being sorted does not exist until the query computes it. That is the whole reason a meta key has to become a real typed column.

Which makes the type the decision that breaks the optimisation when it is wrong. A price column declared `varchar` sorts `"100"` before `"99"`, so every price-ordered archive is quietly in the wrong order - worse than leaving the key unconfigured.

The type is not recoverable from the query. A cast in the SQL says what the query *asked for*, not what the rows *hold*. So it is measured by sampling the values actually stored, spread across the id range rather than taking the oldest rows.

| `confidence` | Meaning |
|---|---|
| `schema` | Read from `INFORMATION_SCHEMA`. Exact, not inferred. Applies to real table columns. |
| `sampled` | Every sampled value fitted the type. |
| `low` | Too few values to be sure. Reported as `varchar`. |
| `mixed` | Some rows parse as numbers and some do not. |
| `absent` | The key holds no values. |
| `skipped` | The sampling time budget ran out. |

:::callout{variant="note" title="Do not auto-apply"}
Never auto-apply a `mixed` or `low` verdict. `mixed` means the data itself disagrees - some rows numeric, some not - so either choice is wrong for part of your content. It is a data-quality finding in its own right and wants a human.
:::

## What gets recorded, and what gets recommended

These are two different questions and the profile answers both.

**Every** archive query that ran is in `archives[]`, with its size, its time and its plan - including WordPress's own. Nothing is hidden from you, and a consumer is entitled to sort them by time or impact and decide for itself.

**Recommendations** - `candidate_composites[]` and `materialise{}` - are fed only by archives where `worth_indexing` is `true`, which needs two things at once.

**A bad plan** (`qualifies[]` is non-empty):

| Reason | Meaning |
|---|---|
| `ms` | The query took 50ms or more. |
| `filesort` | The database sorts the matched rows. |
| `scan` | No usable index. |
| `est_rows` | The optimiser expects to examine 1,000 rows or more. |

**And enough rows to be worth an index**: 200 or more, taken as the largest of `found_rows`, `rows_returned` and `est_rows`.

Both bars matter, and they are doing different jobs. Rows returned is a poor measure of *cost* - the archive this feature exists for examines two million rows to return twelve, which is why a query is never skipped for being small in its results. But rows are the right measure of *whether an index is worth building*, because no index helps a table with one row in it.

:::callout{variant="did-you-know"}
That size bar is doing more work than it looks. WordPress stores its own editor and theme data as posts - `wp_global_styles`, `wp_template_part`, `wp_template` - each scoped by the `wp_theme` taxonomy, and it queries them on ordinary front-end requests whether or not you run a block theme. They are taxonomy-filtered archives that filesort, exactly like a category archive, and they hold one row each. Without the size bar they outnumber your real archives roughly nine to one in the recommendations.
:::

There is deliberately no list of post types to ignore. A site whose template-part table somehow grew to 50,000 rows would be reported like anything else that size, and nothing needs updating when WordPress adds its next internal post type.

## unsupported[]

Archives that cannot be helped, with a `reason`: `search`, `tax_relation_or` (an `OR` relation, built with LEFT JOINs), `no_ordering`, or `orderby_rand` and similar for sorts no index can serve. Reported so a consumer can decline honestly rather than promise a speed-up that will not arrive.

## What is never in here

Meta **values**, at any point. Only keys, types and counts. Meta values hold licence keys, customer addresses and order data, and none of it is needed to choose an index.

When a profile is shared with superspeedy.org, less again travels: row counts are banded, and a meta key is named only when it belongs to WordPress, WooCommerce or a widely installed plugin. Anything specific to your site is shared as `custom`.

## Rules for anything consuming this

1. `complete: false` means insufficient evidence, not "nothing needed". A run whose custom-post-type archive timed out has proved nothing about that archive.
2. Never auto-apply a `mixed` or `low` type.
3. Honour `taxonomy_excluded` - it is not a filter.
4. Honour per-column `order`; an index declared in the wrong direction will not be used.
5. Do not treat an empty `archives[]` as "this site has no archives" without checking `pages_seen` and `predates_contract`.
6. Configure from `worth_indexing` archives, not from every entry in `archives[]`. The rest are there so you can see the whole picture, not so you can act on all of it.

## Further reading

- [Methodology](https://www.superspeedyplugins.com/kb/super-speedy-performance-analysis/advanced/methodology/) - how the measurement behind the profile works
- [Understanding Your Results](https://www.superspeedyplugins.com/kb/super-speedy-performance-analysis/understanding-your-results/) - reading the rest of a run's findings
