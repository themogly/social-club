{{-- Prompt 327 — a staging site says so on every page, so nobody mistakes it for the club. Only on APP_ENV=staging (a
     fixed fact of the deploy, not session state). Teal — deliberately OUTSIDE the palette (Ben asked for it): it must not
     read as training mode's striped amber (324, practising on the REAL club) nor as the brand blue. Inline styles, so it
     renders the same in the counter's and the panel's stylesheets. --}}
@if (app()->environment('staging'))
    <div data-staging-strip role="note"
         style="background:#0f766e;color:#ffffff;font-size:12px;font-weight:700;letter-spacing:.04em;line-height:1.2;padding:4px 16px;text-align:center;position:relative;z-index:60;">
        {{ __('ENTORNO DE PRUEBAS — no es el club real') }}
    </div>
@endif
