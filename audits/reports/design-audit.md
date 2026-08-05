# Design Audit — Phase C round

**Branch:** `design/audit-pass` off `main` @ `270088a`.
**Scope:** the admin panel's visual layer after the churn — the dashboard (rebuilt at 101/129/144, now on
`auto-fit minmax(13rem)`), hierarchy, responsiveness, consistency. Contrast + target-size are the accessibility
audit's ground and are not re-litigated here (run after it, per the work order). Method: screenshotted the REAL
logged-in dashboard at 1440 / 1280 / 1024 and judged the layout by eye — the question a harness cannot answer.

**Headline:** the dashboard is well-designed — clear two-column hierarchy (stat band + charts in the main
column, a summary rail alongside), genuinely designed empty states everywhere, consistent cards and palette. One
real finding: the stat cards drop to **2-up at 1280**, the most common laptop width, which is less dense than
the layout wants. One small, verified fix; the rest is confirmation.

---

## PHASE 1 — Critical

- Nothing. No broken layouts, no overflow, no clipped content, no off-brand colour, no inconsistent components.

Review:

## PHASE 2 — Refinement

- **Stat cards fall to 2-up at 1280 (they are 3-up at 1440).** 144's `auto-fit minmax(13rem, 1fr)` fixed the
  mid-word break, but 13rem (208px) is just too wide for three cards in the ~639px main column at 1280: three
  need 656px, so it drops to two — eight cards become four tall rows and push the charts well below the fold.
  At 1440 the column is ~748px and it correctly shows three. → **Tighten the floor to `12rem`** (192px): three
  fit at 1280 (608px) AND 1440, four only on genuinely wide screens. 144's own harness already proved labels
  stay clean down to 192px (chip-below), so this **keeps the mid-word-break fix intact** — it tunes 144's "floor
  for margin" (208→192, still 16px above the 176px clean threshold), it does not reverse the decision. Verified:
  re-ran the 144 range-rects harness at the new floor — zero mid-word breaks, 8 labels × es/en × light/dark.
  → **Why it matters:** the dashboard is the club's at-a-glance screen; three-up surfaces the charts and the
  rail relationship a row sooner on the width most staff actually use.

Review: One density fix, verified not to regress 144. Everything else is a genuinely good layout — kept as is.

## PHASE 3 — Polish

- Nothing to fix. Empty states are designed (icon + heading + one-line explanation, not blank boxes) across
  every chart and list; the period toggle, scope pill and rail cards are consistent; card radius/shadow/spacing
  are uniform. The 129/144 label-over-chip and 151 topbar work all read cleanly here.

Review:

---

## OWNER / OPS

- None. This is presentation; no owner content or infra decision is implicated.

## Discussion

- The `12rem` tuning is the only place this audit touches a documented value (144's 208px floor). It is included
  as a fix rather than a Discussion item because it does NOT undo 144's decision — the auto-fit + chip-below +
  break-word mechanism and the "min-width that keeps labels whole with margin" rationale are both preserved; only
  the specific rem value is tuned, and the label-safety is re-proven by 144's own harness. If the reviewer would
  rather freeze 144 exactly, revert this one line and accept 2-up at 1280.
