{{-- Report-only additions on top of the shared .csc-dash tokens. Scoped under
     .csc-report and theme-aware via Filament's .dark class — no leakage to public CSS. --}}
<style>
    .csc-report .csc-exports { margin-inline-start: auto; display: inline-flex; gap: 0.4rem; }
    .csc-report .csc-scope { margin-inline-start: 0; }
    .csc-report .csc-select { border: 1px solid var(--bd); background: var(--s); color: var(--tx); border-radius: 0.5rem; padding: 0.3rem 0.5rem; font-size: 0.82rem; min-width: 12rem; }
    .csc-report .csc-select:focus-visible { outline: 2px solid var(--br); outline-offset: 1px; }

    .csc-report .csc-rep-context { font-size: 0.78rem; font-weight: 600; color: var(--mut); margin: -0.5rem 0 0; }

    /* Summary strip */
    .csc-summary { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.75rem; }
    @media (min-width: 640px) { .csc-summary { grid-template-columns: repeat(3, 1fr); } }
    @media (min-width: 1024px) { .csc-summary { grid-template-columns: repeat(5, 1fr); } }
    .csc-chip { display: flex; flex-direction: column; gap: 0.2rem; background: var(--s); border: 1px solid var(--bd); border-inline-start-width: 3px; border-radius: 0.7rem; padding: 0.7rem 0.85rem; box-shadow: var(--shadow); }
    .csc-chip-label { font-size: 0.72rem; font-weight: 600; color: var(--mut); }
    .csc-chip-value { font-size: 1.15rem; font-weight: 700; color: var(--tx); font-variant-numeric: tabular-nums; letter-spacing: -.01em; }
    .csc-chip-default { border-inline-start-color: var(--br); }
    .csc-chip-success { border-inline-start-color: var(--ok); }
    .csc-chip-warning { border-inline-start-color: var(--warn); }
    .csc-chip-error { border-inline-start-color: var(--err); }

    /* Sortable headers */
    .csc-rep-table th { vertical-align: bottom; }
    .csc-sort { appearance: none; border: 0; background: transparent; padding: 0; margin: 0; font: inherit; font-size: 0.72rem; font-weight: 600; text-transform: uppercase; letter-spacing: .03em; color: var(--mut); cursor: pointer; display: inline-flex; align-items: center; gap: 0.25rem; }
    .csc-num .csc-sort { flex-direction: row-reverse; }
    .csc-sort:hover { color: var(--tx); }
    .csc-sort:focus-visible { outline: 2px solid var(--br); outline-offset: 2px; border-radius: 3px; }
    .csc-sort-caret { font-size: 0.6rem; opacity: .5; }
    .csc-sort-on { color: var(--brtx); }
    .csc-sort-on .csc-sort-caret { opacity: 1; }

    /* Totals row */
    .csc-rep-table tfoot .csc-total-row td { border-top: 2px solid var(--bd); border-bottom: 0; font-weight: 700; color: var(--tx); background: var(--s2); padding-top: 0.55rem; padding-bottom: 0.55rem; }

    /* Notes + richer empty state */
    .csc-rep-note { font-size: 0.72rem; color: var(--mut); margin: 0.6rem 0 0; font-style: italic; }
    .csc-empty-hint { font-size: 0.76rem; color: var(--mut); margin: 0; max-width: 26rem; }
    .csc-empty-hero { padding: 2.6rem 1rem; }
    .csc-empty-hero .csc-empty-ico { width: 2.1rem; height: 2.1rem; }
    .csc-empty-hero .csc-empty-msg { font-size: 0.95rem; font-weight: 600; color: var(--tx); }
</style>
