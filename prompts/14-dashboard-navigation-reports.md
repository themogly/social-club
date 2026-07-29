# 14 — Dashboard, navigation & the report suite

One branch, one task. Read `CLAUDE.md` + `DECISIONS.md`, and the kit's **`admin-design`** and
**`frontend-design`** skills before starting. Requires 01–13 merged (it renders their data).

`git checkout main && git pull` → `git checkout -b feat/dashboard-reports`.

> **This is the prompt that decides whether the product looks finished.** Everything before it is
> correct plumbing; this is the screen the owner opens every morning and the one a prospective club
> judges in ten seconds. Budget real time here.
>
> **Reference bar:** the Doob System admin home (the incumbent in this market). Its shape is worth
> matching — persistent left module nav, a breadcrumbed club header with a Today / This Week /
> This Month period toggle, a wide chart-and-table main column, and a right-hand rail of grouped
> stat readouts (Financial Stats, Member Stats, Dispense & Bar). **Match that information density,
> then beat it on craft**: it has flat single-value readouts with no trend, no deltas, no alerting,
> no drill-through, and no dark mode. Those four things are the whole opportunity.

---

## Part 1 — Root route and navigation

**`/` is the dashboard.** There is no public site and no landing page (see prompt 00 — this is a
legal constraint, not a preference). Requirements:

- The Filament panel is mounted at `/`; an authenticated staff user landing on `/` sees the
  dashboard immediately, with no redirect hop and no intermediate page.
- An unauthenticated request to `/` goes to the login screen; after login it returns to `/`.
- A member (prompt 15's guard) hitting `/` is routed to the member PWA home instead.
- Remove Filament's stock dashboard furniture entirely — no docs card, no version widget. The
  dashboard shows the club's data or nothing.
- `robots.txt` disallows everything and `X-Robots-Tag: noindex` is set globally.

**Sidebar navigation — grouped, not a flat list of twenty items.** Collapsible, icon + label,
persistent, with the active item clearly marked. Groups:

- **Overview** — Dashboard
- **Socios** — Members · Applications · Memberships · Check-in · Sanctions
- **Dispensario** — Dispensary POS · Genetics · Batches · Stock · Stock takes
- **Barra** — Bar POS · Articles
- **Caja** — Till sessions · Cash movements · Expenses · Purchases · Suppliers
- **Informes** — Dashboard reports · Members · Consumption · Stock · Financial · Attendance · Tills
- **Documentos** — Libro de socios · Actas · Generated documents
- **Sistema** — Users · Roles · Locations · Settings · Audit log

Nav items are **permission-filtered** — a staff user simply doesn't see Sistema. The **location
switcher** sits in the header next to the club name, with "All locations" for owners only.

> **Some of those groups point at prompts that haven't run yet** — Documentos (prompt 16), Audit log
> (17), and Comunicaciones (15, not listed above). Build the nav *structure* now, but **register each
> item only when its resource actually exists**; those prompts add their own. A sidebar full of dead
> links is worse than a shorter sidebar, and "I'll wire it up later" is how dead links reach
> production.

---

## Part 2 — The dashboard

**Header row:** club logo + name, breadcrumb, and a **period toggle — Today / This Week / This Month
/ Custom range** — that drives *every* widget on the page. One date control, not one per card.

**Layout:** a two-column body on desktop — a wide main column (charts and tables) and a right rail
(grouped stats and alerts) — collapsing to a single column below 1024. Use the established spacing
scale; group with proximity and whitespace before borders.

### Stat cards (top of the main column)

Each card: a large value, a plain-language label, a **delta vs the previous equivalent period** with
direction and colour, and a **sparkline** of the trend. Every card is **clickable through to the
report that explains it** — a number the owner can't interrogate is a number they won't trust.

1. **Aportaciones hoy** — today's contributions, with the cash / wallet split
2. **Dispensado** — grams dispensed today, and month-to-date
3. **Socios dentro** — currently checked in, as a **progress ring against the aforo capacity**
4. **Transacciones** — count today, plus average contribution value
5. **Socios activos** — active members, and new this month
6. **Valor del stock** — stock on hand at cost, plus **days of inventory remaining**
7. **Saldo de socios** — total wallet float held (a **liability** — label it as one) and total debt
8. **Caja** — variance on the open/last session, and a flag if any session is unreconciled

### Charts (main column)

Follow the kit's `frontend-design` rules and these mappings — no 3D, no many-slice pies, no chart
that needs a hover to be understood:

- **Ingresos por período** — line or grouped bar over the selected period, split **Aportaciones /
  Barra / Cuotas** (the three income types must never be merged into one number).
- **Ingresos vs gastos** with a running **superávit** line — the non-profit story in one chart.
- **Dispensado por genética** — horizontal bar, top 10, grams and euros toggle.
- **Aforo / footfall heatmap** — hour × day-of-week. This is the staffing chart and almost nobody
  in this market has it.
- **Distribución de consumo** — histogram of members by percentage of their declared monthly limit
  used. A compliance instrument as much as an analytics one: the tail is visible at a glance.
- **Niveles de stock** — by genetic, low stock highlighted, against the premises ceiling line.

### Tables (main column)

- **Top dispensado** — genetic, transactions, grams, total. (Doob's equivalent, plus a trend column.)
- **Últimas transacciones** — live-ish recent activity across dispensary and bar, with the operator.

### Right rail — grouped stat readouts and alerts

Mirror Doob's grouping, with each figure a link through to its report:

- **Finanzas** — Aportaciones · Cuotas · Barra · Monedero (top-ups) · Compras · Gastos
- **Socios** — Check-ins · Nuevas altas · Renovaciones · Pre-registros pendientes
- **Dispensario y barra** — grams dispensed · dispensary € · bar €

**Alerts panel — the thing that makes it a working tool rather than a wallchart.** Each alert is a
row with a severity, a plain-language sentence and a direct action link:

- Members **over or near** their monthly limit
- **Limit overrides** used in the period (with who authorised)
- **Aforo at or near capacity**
- **Unreconciled or still-open till sessions**, and variances beyond tolerance
- **Low stock** by genetic, and **batches nearing expiry**
- **Premises stock ceiling exceeded** — on-site grams vs `active members × daily limit × ceiling
  days` (see NOTES). Flag this one prominently; it is the compliance alert that matters most.
- Memberships **expiring in the next 30 days**; members with **unpaid fees**
- **Pending applications** awaiting review

### Role-aware dashboards

- **OWNER** — everything, plus the **org-wide rollup** across locations and a per-location comparison.
- **MANAGER** — their location(s): operations, stock, till, members, alerts. No org rollup.
- **STAFF** — a deliberately small view: who's inside, aforo, today's takings for their till, low
  stock, their own session. Not a finance dashboard.

Do not build a drag-and-drop widget editor. Ship three well-judged fixed layouts; revisit only if
the owner asks.

---

## Part 3 — The report suite

Every report: the shared period picker, location scope (owner may select All), filters, sortable
columns, totals row, and **CSV / Excel / PDF export**. Numbers right-aligned, rows given breathing
room, scannable. Every report has a designed **empty state** that says what to do, never a blank chart.

- **Socios** — directory export, counts by status, new joins, churn, expiring cards, sanctions,
  avalador chains, **libro de socios** (prompt 16 formalises this one).
- **Consumo** — grams by member / genetic / period; over-limit members; override log; declared
  forecast vs actual; **aggregate declared forecast** (the authorised cultivation volume).
- **Stock** — on hand, movements, intake, **merma**, valuation, turnover, days on hand, stock-take
  variances, batch traceability (everything dispensed from a batch, and vice versa).
- **Financiero** — daily / weekly / monthly takings by income type and payment method; income vs
  expenses; **where the money goes** category breakdown; surplus; supplier balances; purchase-vs-
  withdrawal reconciliation.
- **Asistencia** — daily entry–exit sheet, footfall by hour, occupancy peaks, dwell time, visits per
  member.
- **Cajas** — session Z-reports, variances by session and by operator, cash movements.
- **Comité / AGM pack** — an owner export bundling member counts, total contributions, outgoings and
  surplus for the assembly.

---

## Rules

- **Never cache transactional data.** Takings, stock, balances, occupancy and limits are queried
  live, every time. A cached revenue widget is a wrong revenue widget.
- Dashboard queries must not N+1. Aggregate in SQL, assemble in an `App\ViewModels` page class, keep
  the controller and the Livewire component thin. Add the indexes the aggregates need.
- Money in integer cents, weight in centigrams, formatted only at the edge. Grams to 2 dp, euros in
  the configured Spanish format.
- Colour carries meaning consistently (success / warning / error) and **never alone** — always a
  number, label or icon too. AA contrast throughout, including chart series against their background.
- **Dark mode is a first-class requirement**, not an afterthought — this is a dim room at 10pm.
  Charts and stat cards must be legible in both, and admin theming must not leak into any other
  stylesheet.
- Reuse: one stat-card component, one chart wrapper, one table component, parameterised. If two
  dashboards render "the same" card differently, consolidate rather than editing both.
- Vocabulary: *aportación*, *socio*, *dispensación*, *superávit*. Never *venta*, *cliente*, *beneficio*.

## Tests (required)

- Route: `/` authenticated as staff renders the dashboard (200, no redirect chain); unauthenticated
  redirects to login and returns to `/` after; a member is routed to the PWA.
- Each stat card's figure equals a directly-computed control query over the same seeded period —
  one test per card, because a plausible-but-wrong dashboard number is the worst kind of bug.
- The period toggle changes every widget consistently; a custom range is honoured everywhere.
- Role scoping: a manager's dashboard contains no org-wide rollup; a staff dashboard contains no
  finance figures — assert on the payload, not the rendered HTML.
- Location scoping: with location A active, no figure includes B's data; owner "All locations" sums
  both exactly.
- Alerts fire on the right conditions: seed an over-limit member, an unreconciled till, an expiring
  batch and a stock-ceiling breach, and assert each appears with the right severity.
- Exports contain the same totals as the on-screen report.
- Empty state: a brand-new location renders instructive empty states, not blank charts or errors.
- Performance: the dashboard issues a bounded number of queries on a seeded dataset (assert a query
  count) and does not N+1.

## Finish

`composer check` green. **Screenshot the dashboard and two reports at 1440 / 1280 / 1024 / 390 and a
short laptop height, in light and dark, motion reduced and allowed** — then look at them properly
before calling this done. Record the dashboard composition and the role-view differences in
DECISIONS.md. Push the branch; **do not merge**.
