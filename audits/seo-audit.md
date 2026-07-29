# SEO Audit (technical + on-page — report-first, then fix in phases)

Reusable SEO audit for a server-rendered web app. Read the project's `CLAUDE.md` first.

## Method
- `git checkout main && git pull`, branch `seo/audit-pass`. State the starting commit.
- INVENTORY what already exists first (code + rendered `<head>`) before proposing anything — some SEO may already be present; do not redo finished work or assume it's absent.
- Audit using the browser AND the code: read the rendered `<head>`, headings, and content per page, and the layout/meta mechanism, routes, sitemap, robots, and any existing structured-data handling.
- Respect the architecture: server-rendered output STAYS server-rendered (no moving meta to client JS); meta stays CMS-editable via a shared meta component with computed defaults; white-hat only (no stuffing/cloaking); **REAL data only** in structured data and meta — pull prices/dates/locations/contact from real models; NEVER invent an address, phone, price, or review count; flag placeholders.
- Cover EVERY public page including dynamic ones (audit how their meta is generated, not just one example).

## Step 1 — Report FIRST
Write `audits/reports/seo-audit.md`, `- [item]: [what's wrong] → [fix] → [why it matters]`, PHASE 1 / 2 / 3, each ending `Review:`. Commit before fixing.

### PHASE 1 — Indexability & technical foundation
Per-page unique titles + meta descriptions (incl. dynamic pages), canonical URLs, correct `noindex` on thin/private pages (payment-success, dev/admin routes), robots.txt, an XML sitemap covering dynamic URLs that updates on content change, one clean `<h1>` per page + logical heading order, server-rendered crawlable `<a href>` links, image alt text.

### PHASE 2 — Relevance, structure & local
LocalBusiness/Organization structured data + NAP consistency (if a local business), JSON-LD (Product/Offer with price+availability, Event for dated items, Review/AggregateRating from real data, BreadcrumbList with VALID positions, FAQPage where FAQs exist, Article for posts), Open Graph + Twitter cards per page with a default, keyword/location-aware titles, internal linking to money pages with descriptive anchors, clean readable slugs.

### PHASE 3 — Performance signals & enrichment
Image dimensions set (no CLS), lazy-load below the fold, preload hero/font for LCP, favicon/touch-icons/manifest, 404 returns a real 404 status, sitemap referenced from robots with accurate lastmod.

## Step 2 — Fix in phase order
Implement via a reusable shared meta mechanism, not copy-pasted tags. Validate the sitemap + sample JSON-LD parse. Add tests where sensible (unique title per page, sitemap includes published content, payment-success is noindex, 404 status). One commit per item, check gate green, never commit red.

## Separate: owner/account tasks
List Search Console verification, Google Business Profile, real backlinks, and the real postal address/NAP as OWNER tasks in their own section — not code, not faked.

## Finish
Update the report, full suite green, DECISIONS.md updated, push the branch, do not merge.
