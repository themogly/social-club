<?php

namespace App\Support;

/**
 * The member PWA's ONE form-field style (prompt 156). A single definition — a real border, padding, the
 * prompt-98 AA-contrast tokens, and a VISIBLE 2px focus ring — so every socio input/textarea/select looks the
 * same and the next screen inherits it instead of hand-copying an approximation. Copying is exactly how the
 * message form lost its `px-3 py-2.5` (collapsed to line-height), its `border` (colour without width = none) and
 * its `focus:ring-2` (colour without width = no visible focus ring). The `x-socio.input` / `x-socio.textarea`
 * components and the application/login forms all reference THIS.
 */
class SocioForm
{
    /**
     * `min-h-11` added by prompt 217. Measured at 390×844 with touch: `py-2.5` alone left the two `<select>`s
     * at **42px** — two short of the floor, and invisible to every other harness in this repo because they
     * all measure desktop or tablet. Text inputs were already tall enough; the floor makes it true of every
     * control that wears this class, including the next one.
     */
    public const FIELD = 'block min-h-11 w-full rounded-lg border border-line bg-surface px-3 py-2.5 text-ink outline-none focus:border-brand focus:ring-2 focus:ring-brand/30 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';
}
