# Performance Analysis hand-off: site cohorts and commerce flows

Status: client implementation COMPLETE in Super Speedy Performance Analysis 0.18.0 (12th August
2026). Receiver and superspeedy.org support are still outstanding and remain the
superspeedy.org repository's work, as section 7 sets out.

What the client now emits, against the contract below:

- `sspa/site-snapshot@2` - classification (primary + secondary purposes, confidence, taxonomy
  version, coarse signals), banded sizes with `banding_version`, primary content class, and the
  environment block. Emitted as a SUPERSET of version 1 rather than a replacement, because the
  same array is also the payload's top-level `site_snapshot`, which a receiver that predates
  this change still reads; every v1 key keeps its name and meaning.
- `sspa/order-management-flow@1` - view order and mark completed, with their page-profile
  relationships, the management total, the status transition and an honest
  complete/partial/blocked/failed outcome. Only emitted when the run's own rows prove the steps
  were attempted.
- `sspa/checkout-flow@2` - unchanged contract, still customer-only totals. Its segment
  membership is now an explicit map rather than `in_array()` over whole step arrays.
- Payload schema minor 1.1 -> 1.2. Purely additive; the receiver should accept 1.2 before the
  first payload arrives.

Two deviations from the shape sketched in section 4, both deliberate:

- the size ladder is the existing deterministic one (`<10`, `<100`, `<1k` ... `1b+`), continued
  rather than replaced, and stamped `banding_version: 1`. It is decimal throughout, so
  `database_bytes: "<10b"` means under ten billion bytes, not the `<10g` of the example;
- `checkout_type` uses the values the checkout-flow evidence already uses (`block`, `classic`,
  `unknown`) rather than `blocks`, so the two records can be joined without a translation table.

This document is a bounded hand-off for the agent working in the public
`super-speedy-performance-analysis` repository. It records the client-side evidence that the hub
needs next. The client remains responsible for privacy transformation and anonymisation before a
payload leaves WordPress.

## 1. Outcomes

Implement two related additions:

1. a richer, versioned site-characteristics snapshot for honest cohort analysis; and
2. explicit submitted evidence for both the customer checkout flow and the shop owner's
   order-management flow.

The receiver deliberately archives evidence versions it does not understand. Client work can
therefore ship first: new payloads remain recoverable and will be reprocessed after receiver
support is added.

## 2. Terminology

Use **site characteristics** for the user-facing concept and **site cohort dimensions** for the
fields used to group evidence. Avoid `demographics` in new public contracts because these are
characteristics of a website, not people.

Separate dimensions into:

- purpose/vertical;
- scale bands;
- technical environment;
- stack profile;
- measured workload/surfaces.

Purpose must support a primary label plus secondary labels. A WooCommerce publisher, membership
store or jobs board must not be forced into one mutually exclusive description.

## 3. Current client gaps

`SSPA_Demographics::snapshot()` already records all public post-type counts, orders in the last
30 days, users, comments, terms, several table-size estimates, database/autoload size, theme,
active plugins, WordPress/PHP/database versions, object-cache state, memory limit, multisite and
locale.

The schema 1.1 community exporter currently submits only:

- one inferred `sector` label;
- bucketed posts, products, postmeta rows, users and database bytes;
- WordPress, PHP and database versions;
- object-cache boolean;
- theme plus the separate component inventory.

Consequences:

- order activity and order-store scale cannot be reconstructed by the receiver;
- counts for jobs, listings, courses, events and other primary content types are discarded;
- `publisher` currently means only more than 100 posts and cannot distinguish news from a blog;
- multi-purpose sites collapse to one label;
- the inference has no taxonomy version, confidence or safe signal list;
- the receiver can reclassify from plugin inventory later, but cannot recover discarded size and
  content-shape signals.

The local checkout waterfall now has separate `at_risk`, `secured`, `management` and `excluded`
groups. `SSPA_Community_Exporter::add_checkout()` currently merges only `at_risk`, `secured` and
`excluded`, so `flow-view-order` and `flow-complete-order` are not present in the submitted flow
evidence. The local measurement exists; the community contract loses it.

## 4. `sspa/site-snapshot@2`

Bump the evidence version from 1 to 2. Do not change historical payloads.

The exact PHP structure may follow existing conventions, but the submitted JSON contract must
represent the following safe information.

```json
{
  "classification": {
    "primary_purpose": "ecommerce",
    "secondary_purposes": ["publishing"],
    "confidence": "high",
    "taxonomy_version": 1,
    "signals": ["product-content", "woocommerce-stack", "post-content"]
  },
  "sizes": {
    "posts": "<1k",
    "pages": "<100",
    "primary_content_items": "<10k",
    "products": "<10k",
    "orders_total": "<100k",
    "orders_30d": "<1k",
    "users": "<10k",
    "comments": "<100k",
    "postmeta": "<10m",
    "database_bytes": "<10g",
    "active_plugins": "<100",
    "banding_version": 1
  },
  "primary_content": {
    "class": "product",
    "count": "<10k"
  },
  "environment": {
    "wordpress_version": "...",
    "php_version": "...",
    "database_family": "mysql",
    "database_version": "...",
    "object_cache": true,
    "object_cache_category": "redis",
    "page_cache": null,
    "multisite": false,
    "locale_family": "en",
    "woocommerce_hpos": true,
    "checkout_type": "blocks"
  }
}
```

This is a target shape, not permission to emit unavailable guesses. Use `null` or omit an optional
field when it cannot be determined safely and cheaply.

### 4.1 Purpose taxonomy

Start with stable canonical labels that can grow without changing their meaning:

- `ecommerce`;
- `publishing`;
- `jobs`;
- `directory`;
- `real-estate`;
- `events`;
- `elearning`;
- `membership-community`;
- `digital-products`;
- `portfolio-agency`;
- `general`.

The rules file may map plugin slugs and public content types to those labels. Version the mapping.
Do not submit an arbitrary private CPT slug. Map it to a canonical content class or `other` before
export. The primary purpose should be deterministic; secondary labels preserve genuine hybrid
sites.

Keep the underlying signals coarse and canonical so the receiver can apply an improved,
versioned classifier later. Never submit titles, URLs, customer/order data, term names or free
text.

### 4.2 Size measurement

- Bucket every submitted count in the client.
- Continue the existing deterministic bucket contract or introduce an explicitly versioned
  replacement.
- Measure WooCommerce order counts through HPOS-compatible APIs/data stores.
- Avoid unbounded `COUNT(*)` scans on large customer tables. Use safe maintained counts,
  estimates or bounded queries and disclose the meaning of each field in code/docs.
- `orders_30d` is activity, while `orders_total` is store scale. Keep them distinct.
- `primary_content_items` must use the mapped primary content class, not expose a private CPT.
- Preserve exact local metrics where they are useful to the local report; only the community
  projection must be bucketed.

### 4.3 Environment and stack

Retain the measurement-time component inventory as the authoritative plugin/theme stack. Add
only safe, useful cohort facts to the site snapshot. Technology categories must be generic and
deterministic. Do not submit hostnames, IPs, paths, account identifiers or hosting-company data.

## 5. Commerce-flow evidence

Customer checkout and post-sale order management are related but semantically different:

- checkout measures customer wait and conversion risk;
- order management measures the shop owner's administrative workload after the sale;
- harness/cleanup work belongs to neither and must remain excluded.

Never add management time to `at_risk_ms`, `secured_ms` or checkout `total_ms`.

### 5.1 Retain `sspa/checkout-flow@2`

Keep the existing customer-flow evidence contract for compatibility. It should contain the
ordered customer-facing `at_risk` and `secured` steps, payment-boundary status, checkout type,
mail/HTTP summaries and customer-flow totals. Cleanup and preflight steps may remain explicitly
labelled `harness`, but do not enter customer totals.

Review the current exporter membership tests. They compare entire step arrays with `in_array()`;
prefer an explicit map from page class to segment so duplicated/split place-order rows and future
steps cannot be assigned by structural equality accidentally.

### 5.2 Add `sspa/order-management-flow@1`

Add a separate evidence record when the run contains post-sale management measurements. Its safe
contract should include:

```json
{
  "outcome": "complete",
  "checkout_type": "blocks",
  "order_storage": "hpos",
  "steps": [
    {
      "page_profile_uuid": "uuid",
      "page_class": "flow-view-order",
      "method": "GET",
      "generation_ms": 123.4,
      "sql_ms": 12.3,
      "query_count": 45,
      "http_ms": 0,
      "response_code": 200,
      "blocked": false
    },
    {
      "page_profile_uuid": "uuid",
      "page_class": "flow-complete-order",
      "method": "GET",
      "generation_ms": 234.5,
      "sql_ms": 34.5,
      "query_count": 67,
      "http_ms": 10,
      "response_code": 200,
      "blocked": false
    }
  ],
  "management_ms": 357.9,
  "slowest_step": "flow-complete-order",
  "from_status": "processing",
  "to_status": "completed",
  "methodology_version": 1
}
```

Use the existing page-profile UUID relationships. Status values must be a narrow allow-list of
standard safe status classes or canonicalised to `other`; never export order IDs, URLs, notes,
customer information or integration payloads.

If the management steps were skipped or blocked, preserve that honest outcome rather than
silently omitting the evidence. Do not create this evidence for historical runs whose local rows
do not prove that the steps were attempted.

Register the new type in the community schema and manifest. The current receiver will archive it
as unsupported until its processor capability is upgraded; that is expected behaviour.

## 6. Tests required in Performance Analysis

- exact `site-snapshot@2` fixture and evidence-manifest version assertion;
- every size bucket boundary;
- hybrid purpose classification with deterministic primary and secondary ordering;
- jobs, ecommerce, publishing and general classification examples;
- private/unknown CPTs never leave as raw slugs;
- HPOS and legacy WooCommerce order-size paths;
- absence/`null` behaviour when WooCommerce or optional signals do not exist;
- final community privacy scan over the richer snapshot;
- checkout export contains customer steps but no management or harness time in its total;
- order-management export contains view/complete steps, their page-profile relationships and
  management total;
- blocked/skipped/partial management flows remain explicit;
- no order ID, product ID, user ID, URL, address, email, cookie, nonce or request/response body;
- old archived evidence fixtures remain valid and are not rewritten.

Update the public Share-tab disclosure to name bucketed site characteristics and separate
checkout and order-management flow evidence accurately.

## 7. Superspeedy.org follow-on

After client payloads arrive, the receiver project will:

1. add processor capabilities for `sspa/site-snapshot@2` and
   `sspa/order-management-flow@1`;
2. reprocess only payloads whose evidence manifest contains those versions/types;
3. extract versioned cohort dimensions into derived tables while retaining the immutable JSON;
4. build install-weighted, privacy-suppressed flow aggregates;
5. expose separate customer-checkout and order-management documents through the public read API.

The website must visualise them separately:

- a checkout waterfall split into pre-payment/at-risk and post-payment/secured customer time;
- an order-management sequence for view order and complete order;
- median, p75 and p95 per step when thresholds permit;
- measurement and distinct-installation counts;
- cohort controls such as checkout type, order storage, site purpose and scale band;
- slowest steps and attributed component summaries where the evidence class supports them;
- coverage-only/insufficient-evidence states before a cohort qualifies.

No chart may expose an installation timeline or let a rare combination identify a site. Flow
comparisons use the same installation weighting, privacy suppression and methodology-version
rules as other evidence.

## 8. Definition of done for this hand-off

- Performance Analysis emits the two new evidence contracts with the documented privacy rules;
- its community, privacy and regression tests pass;
- current checkout totals remain customer-only;
- order-management time is no longer discarded during export;
- evidence and classification/banding versions are explicit;
- the public implementation document is accurate and contains no credentials;
- receiver work is explicitly left for the superspeedy.org repository rather than coupled into
  the client change.
