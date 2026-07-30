{{-- Dashboard-only styles. Scoped under .csc-dash and theme-aware via Filament's
     .dark class, so nothing here leaks into the public or counter stylesheets. Loaded
     inline on this page only — no build step, legible in light and dark. --}}
<style>
    .csc-dash {
        --s: #ffffff; --s2: #f8fafc; --bd: #e2e8f0; --tx: #0f172a; --mut: #475569;
        --br: #2563eb; --brd: #1d4ed8; --brt: #eff6ff;
        --ok: #16a34a; --warn: #d97706; --err: #dc2626;
        --okbg: #dcfce7; --warnbg: #ffedd5; --errbg: #fee2e2;
        --okt: #15803d; --warnt: #b45309; --errt: #b91c1c;
        --shadow: 0 1px 2px rgba(15, 23, 42, .04), 0 4px 14px rgba(15, 23, 42, .06);
        --radius: 0.85rem;
        color: var(--tx);
        display: flex; flex-direction: column; gap: 1.5rem;
    }
    .dark .csc-dash {
        --s: #1e293b; --s2: #0f172a; --bd: #334155; --tx: #e2e8f0; --mut: #94a3b8;
        --br: #3b82f6; --brd: #60a5fa; --brt: rgba(59, 130, 246, .16);
        --ok: #22c55e; --warn: #f59e0b; --err: #f87171;
        --okbg: rgba(34, 197, 94, .16); --warnbg: rgba(245, 158, 11, .16); --errbg: rgba(248, 113, 113, .16);
        --okt: #4ade80; --warnt: #fbbf24; --errt: #fca5a5;
        --shadow: 0 1px 2px rgba(0, 0, 0, .3), 0 6px 18px rgba(0, 0, 0, .38);
    }

    /* Toolbar / period toggle */
    .csc-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 0.75rem; }
    .csc-segmented { display: inline-flex; background: var(--s2); border: 1px solid var(--bd); border-radius: 999px; padding: 0.2rem; gap: 0.15rem; }
    .csc-seg { appearance: none; border: 0; background: transparent; color: var(--mut); font-size: 0.82rem; font-weight: 600; padding: 0.42rem 0.9rem; border-radius: 999px; cursor: pointer; transition: background .15s, color .15s; }
    .csc-seg:hover { color: var(--tx); }
    .csc-seg-active { background: var(--br); color: #fff; box-shadow: 0 1px 2px rgba(37, 99, 235, .35); }
    .csc-seg:focus-visible { outline: 2px solid var(--br); outline-offset: 2px; }
    .csc-dates { display: inline-flex; gap: 0.6rem; }
    .csc-date { display: inline-flex; flex-direction: column; font-size: 0.7rem; color: var(--mut); gap: 0.2rem; font-weight: 600; }
    .csc-date input { border: 1px solid var(--bd); background: var(--s); color: var(--tx); border-radius: 0.5rem; padding: 0.3rem 0.5rem; font-size: 0.82rem; }
    .csc-scope-pill { margin-inline-start: auto; font-size: 0.72rem; font-weight: 700; letter-spacing: .02em; text-transform: uppercase; color: var(--br); background: var(--brt); border: 1px solid var(--bd); padding: 0.3rem 0.7rem; border-radius: 999px; }

    /* Layout */
    .csc-grid { display: grid; grid-template-columns: 1fr; gap: 1.5rem; align-items: start; }
    @media (min-width: 1024px) { .csc-grid { grid-template-columns: minmax(0, 2.15fr) minmax(280px, 1fr); } }
    .csc-main { display: flex; flex-direction: column; gap: 1.5rem; min-width: 0; }
    .csc-rail { display: flex; flex-direction: column; gap: 1.25rem; min-width: 0; }
    @media (min-width: 1024px) { .csc-rail { position: sticky; top: 1rem; } }
    .csc-cards { display: grid; grid-template-columns: repeat(1, 1fr); gap: 1rem; }
    @media (min-width: 640px) { .csc-cards { grid-template-columns: repeat(2, 1fr); } }
    @media (min-width: 1280px) { .csc-cards { grid-template-columns: repeat(4, 1fr); } }
    .csc-chart-grid { display: grid; grid-template-columns: 1fr; gap: 1.5rem; }
    @media (min-width: 768px) { .csc-chart-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    .csc-two { display: grid; grid-template-columns: 1fr; gap: 1.5rem; }
    @media (min-width: 768px) { .csc-two { grid-template-columns: repeat(2, minmax(0, 1fr)); } }

    /* Card */
    .csc-card { display: flex; flex-direction: column; gap: 0.75rem; background: var(--s); border: 1px solid var(--bd); border-radius: var(--radius); padding: 1rem 1.1rem; box-shadow: var(--shadow); position: relative; overflow: hidden; text-decoration: none; color: inherit; }
    .csc-card-link { transition: transform .15s, box-shadow .15s, border-color .15s; }
    .csc-card-link:hover { transform: translateY(-2px); border-color: var(--br); box-shadow: 0 8px 24px rgba(37, 99, 235, .14); }
    .csc-card-link:focus-visible { outline: 2px solid var(--br); outline-offset: 2px; }
    .csc-card[data-flag="true"] { border-color: var(--warn); }
    .csc-card-head { display: flex; align-items: center; gap: 0.5rem; }
    .csc-card-icon { display: inline-flex; align-items: center; justify-content: center; width: 1.9rem; height: 1.9rem; border-radius: 0.55rem; background: var(--brt); color: var(--br); flex: none; }
    .csc-ico { width: 1.05rem; height: 1.05rem; }
    .csc-card-label { font-size: 0.78rem; font-weight: 600; color: var(--mut); }
    .csc-card-body { display: flex; align-items: flex-end; justify-content: space-between; gap: 0.5rem; }
    .csc-card-value { font-size: 1.6rem; line-height: 1.15; font-weight: 700; letter-spacing: -.01em; color: var(--tx); }
    .csc-card-sub { font-size: 0.74rem; color: var(--mut); margin-top: 0.2rem; }
    .csc-card-spark { height: 32px; color: var(--br); }
    .csc-spark { width: 100%; height: 100%; display: block; }
    .csc-spark-line { fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; vector-effect: non-scaling-stroke; }
    .csc-spark-area { fill: var(--brt); stroke: none; }

    /* Delta chip */
    .csc-delta { margin-inline-start: auto; display: inline-flex; align-items: center; gap: 0.15rem; font-size: 0.72rem; font-weight: 700; padding: 0.12rem 0.45rem; border-radius: 999px; }
    .csc-delta-ico { width: 0.85rem; height: 0.85rem; }
    .csc-delta-success { color: var(--okt); background: var(--okbg); }
    .csc-delta-error { color: var(--errt); background: var(--errbg); }
    .csc-delta-muted { color: var(--mut); background: var(--s2); }

    /* Occupancy ring */
    .csc-ring { position: relative; width: 3.4rem; height: 3.4rem; flex: none; }
    .csc-ring-svg { width: 100%; height: 100%; transform: rotate(0deg); }
    .csc-ring-track { fill: none; stroke: var(--bd); stroke-width: 6; }
    .csc-ring-fill { fill: none; stroke-width: 6; stroke-linecap: round; transition: stroke-dashoffset .4s ease; }
    .csc-ring-ok .csc-ring-fill { stroke: var(--ok); }
    .csc-ring-warning .csc-ring-fill { stroke: var(--warn); }
    .csc-ring-error .csc-ring-fill { stroke: var(--err); }
    .csc-ring-muted .csc-ring-fill { stroke: var(--br); }
    .csc-ring-label { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-size: 0.72rem; font-weight: 700; color: var(--tx); }

    /* Section (card container) */
    .csc-section { background: var(--s); border: 1px solid var(--bd); border-radius: var(--radius); box-shadow: var(--shadow); padding: 1.1rem 1.15rem 1.2rem; display: flex; flex-direction: column; gap: 0.75rem; min-width: 0; }
    .csc-section-head { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; }
    .csc-section-heading { display: flex; align-items: center; gap: 0.5rem; }
    .csc-section-ico { width: 1.05rem; height: 1.05rem; color: var(--mut); }
    .csc-section-title { font-size: 0.9rem; font-weight: 700; color: var(--tx); margin: 0; }
    .csc-section-sub { font-size: 0.74rem; color: var(--mut); margin: -0.35rem 0 0; }
    .csc-section-link { font-size: 0.75rem; font-weight: 600; color: var(--br); text-decoration: none; }
    .csc-section-link:hover { text-decoration: underline; }
    .csc-section-body { min-width: 0; }
    .csc-count { font-size: 0.72rem; font-weight: 700; color: var(--tx); background: var(--s2); border: 1px solid var(--bd); border-radius: 999px; padding: 0.05rem 0.5rem; }

    /* Table */
    .csc-table-wrap { overflow-x: auto; }
    .csc-table { width: 100%; border-collapse: collapse; font-size: 0.82rem; }
    .csc-table th { text-align: left; font-weight: 600; color: var(--mut); font-size: 0.72rem; text-transform: uppercase; letter-spacing: .03em; padding: 0.3rem 0.55rem; border-bottom: 1px solid var(--bd); white-space: nowrap; }
    .csc-table td { padding: 0.5rem 0.55rem; border-bottom: 1px solid var(--bd); color: var(--tx); white-space: nowrap; }
    .csc-table tbody tr:last-child td { border-bottom: 0; }
    .csc-table tbody tr:hover td { background: var(--s2); }
    .csc-num { text-align: right; font-variant-numeric: tabular-nums; }
    .csc-tag { display: inline-flex; align-items: center; font-size: 0.72rem; font-weight: 600; padding: 0.1rem 0.5rem; border-radius: 999px; }
    .csc-tag-dispensacion { color: var(--br); background: var(--brt); }
    .csc-tag-barra { color: var(--okt); background: var(--okbg); }

    /* Readouts */
    .csc-readout { display: flex; flex-direction: column; margin: 0; }
    .csc-readout-row { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; padding: 0.5rem 0; border-bottom: 1px solid var(--bd); }
    .csc-readout-row:last-child { border-bottom: 0; }
    .csc-readout dt { font-size: 0.8rem; color: var(--mut); }
    .csc-readout dd { margin: 0; font-size: 0.88rem; font-weight: 700; color: var(--tx); font-variant-numeric: tabular-nums; }
    .csc-readout dd a { color: var(--tx); text-decoration: none; }
    .csc-readout dd a:hover { color: var(--br); text-decoration: underline; }

    /* Alerts */
    .csc-alerts { display: flex; flex-direction: column; gap: 0.55rem; }
    .csc-alert { display: flex; align-items: flex-start; gap: 0.6rem; padding: 0.65rem 0.7rem; border-radius: 0.6rem; border: 1px solid var(--bd); text-decoration: none; }
    .csc-alert-ico { width: 1.1rem; height: 1.1rem; flex: none; margin-top: 0.05rem; }
    .csc-alert-msg { font-size: 0.82rem; font-weight: 600; line-height: 1.3; }
    .csc-alert-info { background: var(--brt); border-color: color-mix(in srgb, var(--br) 30%, transparent); }
    .csc-alert-info .csc-alert-ico, .csc-alert-info .csc-alert-msg { color: var(--br); }
    .csc-alert-warning { background: var(--warnbg); border-color: color-mix(in srgb, var(--warn) 35%, transparent); }
    .csc-alert-warning .csc-alert-ico, .csc-alert-warning .csc-alert-msg { color: var(--warnt); }
    .csc-alert-error { background: var(--errbg); border-color: color-mix(in srgb, var(--err) 35%, transparent); }
    .csc-alert-error .csc-alert-ico, .csc-alert-error .csc-alert-msg { color: var(--errt); }
    .csc-alert:focus-visible { outline: 2px solid var(--br); outline-offset: 2px; }

    /* Empty state */
    .csc-empty { display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; gap: 0.5rem; padding: 1.6rem 1rem; color: var(--mut); }
    .csc-empty-ico { width: 1.6rem; height: 1.6rem; opacity: .55; }
    .csc-empty-msg { font-size: 0.82rem; margin: 0; max-width: 22rem; }

    /* Heatmap */
    .csc-heat-wrap { overflow-x: auto; }
    .csc-heat { display: grid; grid-template-columns: auto repeat(24, minmax(12px, 1fr)); gap: 3px; min-width: 460px; align-items: center; }
    .csc-heat-corner { }
    .csc-heat-hour { font-size: 0.6rem; color: var(--mut); text-align: center; }
    .csc-heat-day { font-size: 0.68rem; color: var(--mut); font-weight: 600; padding-inline-end: 0.35rem; }
    .csc-heat-cell { aspect-ratio: 1 / 1; border-radius: 3px; background: color-mix(in srgb, var(--br) calc(var(--op, 0) * 100%), var(--s2)); border: 1px solid var(--bd); }
</style>
