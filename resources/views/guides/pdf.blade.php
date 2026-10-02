{{-- Prompt 353 — a guide as an A4 PDF (dompdf), from the same rendered Markdown as its page. The footer on every page
     carries the title, the updated date and the page number. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $guide->title }}</title>
    <style>
        @page { margin: 26mm 18mm 20mm 18mm; }
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; color: #0f172a; font-size: 10.5px; line-height: 1.5; }
        footer { position: fixed; bottom: -12mm; left: 0; right: 0; height: 8mm; border-top: 1px solid #e2e8f0; padding-top: 2mm; font-size: 8.5px; color: #475569; }
        footer .page { float: right; }
        footer .page:after { content: counter(page) " / " counter(pages); }
        h1 { font-size: 20px; margin: 0 0 2px; }
        .meta { color: #475569; font-size: 10px; margin-bottom: 14px; padding-bottom: 8px; border-bottom: 2px solid #2563eb; }
        h2 { font-size: 14px; color: #1d4ed8; margin: 18px 0 6px; padding-bottom: 3px; border-bottom: 1px solid #e2e8f0; page-break-after: avoid; }
        h3 { font-size: 12px; margin: 12px 0 4px; page-break-after: avoid; }
        p { margin: 6px 0; }
        ul, ol { margin: 6px 0 6px 16px; padding: 0; }
        li { margin: 2px 0; }
        blockquote { margin: 8px 0; padding: 6px 10px; background: #eff6ff; border-left: 3px solid #2563eb; }
        table { width: 100%; border-collapse: collapse; margin: 8px 0; page-break-inside: auto; }
        th { text-align: left; background: #f8fafc; font-weight: bold; }
        th, td { padding: 4px 6px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        tr { page-break-inside: avoid; }
        img { width: 100%; border: 1px solid #e2e8f0; margin-top: 6px; }
        p:has(img) { page-break-inside: avoid; }
        em { color: #475569; }
        code { font-family: DejaVu Sans Mono, monospace; background: #f8fafc; }
    </style>
</head>
<body>
    <footer>{{ $guide->title }} · {{ __('Actualizada el :date', ['date' => $guide->updated->locale(app()->getLocale())->isoFormat('LL')]) }}<span class="page"></span></footer>
    <h1>{{ $guide->title }}</h1>
    <div class="meta">{{ config('app.name') }} · {{ __('Actualizada el :date', ['date' => $guide->updated->locale(app()->getLocale())->isoFormat('LL')]) }}</div>
    {!! $html !!}
</body>
</html>
