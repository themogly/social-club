{{-- Prompt 361 — the full link as TEXT under a single-action email's button. Ben's video: in the Gmail app on an iPhone the
     button did nothing and a long press selected its words. Gmail disables links in a message it has filtered to Spam
     or flagged (an ops/deliverability matter — SPF/DKIM/DMARC — that code cannot fix), so the URL is also here, plain,
     selectable and wrapping on a phone. Every mail view with exactly one link includes this (guarded by
     SignatureHandoverAndKeptUploadsTest). --}}
<p style="margin:20px 0 4px;color:#475569;font-size:13px;">{{ __('¿El botón no funciona? Copia y pega este enlace en tu navegador:') }}</p>
<p data-plain-link style="margin:0;color:#0f172a;font-size:13px;word-break:break-all;overflow-wrap:anywhere;">{{ $url }}</p>
