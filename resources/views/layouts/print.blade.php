<!doctype html>
{{-- Official letterhead for every printed document: A4, the company paper repeated on each page,
     content kept between the printed header and footer. The paper is always light. --}}
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title')</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        @page { size: A4; margin: 0; }
        :root { color-scheme: light; --ink: #1d1f24; --muted: #5d6068; --line: #d9d4cc; --accent: #9b7a62; --soft: #f6f2ee; }
        * { box-sizing: border-box; }
        html, body { margin: 0; color: var(--ink); }
        html { background: #fff; }
        body { font-family: "IBM Plex Sans Arabic", "Segoe UI", Tahoma, sans-serif; font-size: 11pt; line-height: 1.55;
               -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        /* The letterhead: fixed at the page origin (page margin 0), so the browser repeats it on every
           printed page. The header/footer spacers of .frame (thead/tfoot repeat per page) keep the
           content between the printed header and footer. */
        .letterhead { position: fixed; top: 0; left: 0; width: 210mm; height: 297mm; z-index: 0; }
        table.frame { width: 100%; border-collapse: collapse; }
        table.frame > thead > tr > td { height: 46mm; padding: 0; }
        table.frame > tfoot > tr > td { height: 58mm; padding: 0; }
        table.frame > tbody > tr > td { padding: 0 16mm; }
        .letterhead img { width: 210mm; height: 297mm; display: block; }
        .doc { width: 100%; position: relative; z-index: 1; }
        .doc-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 12mm; margin-bottom: 6mm; }
        .doc-title { font-size: 20pt; font-weight: 700; margin: 0; letter-spacing: .5px; }
        .doc-no { font-size: 11pt; color: var(--muted); direction: ltr; text-align: start; }
        .meta { border-collapse: collapse; }
        .meta td { padding: 1mm 0; vertical-align: top; }
        .meta td:first-child { color: var(--muted); padding-inline-end: 6mm; white-space: nowrap; }
        table.lines { width: 100%; border-collapse: collapse; margin-top: 4mm; }
        table.lines th { background: var(--soft); color: var(--ink); font-weight: 600; font-size: 10pt; text-align: start; padding: 2.2mm 2mm; border-bottom: 1.5px solid var(--accent); }
        table.lines td { padding: 2.2mm 2mm; border-bottom: 1px solid var(--line); vertical-align: top; }
        table.lines tr { break-inside: avoid; }
        .num { text-align: end; direction: ltr; font-variant-numeric: tabular-nums; white-space: nowrap; }
        th.num { text-align: end; }
        .totals { width: 100mm; margin-inline-start: auto; margin-top: 4mm; border-collapse: collapse; break-inside: avoid; }
        .totals td { padding: 1.6mm 2mm; }
        .totals tr.grand td { border-top: 1.5px solid var(--accent); font-weight: 700; font-size: 12pt; }
        .notes { margin-top: 6mm; break-inside: avoid; }
        .notes h3 { font-size: 11pt; margin: 0 0 1mm; color: var(--accent); }
        .line-img { width: 22mm; height: 16mm; object-fit: cover; border-radius: 1.5mm; display: block; }
        .stamp { display: inline-block; border: 1.5px solid #b3261e; color: #b3261e; padding: .5mm 3mm; border-radius: 1.5mm; font-weight: 700; font-size: 10pt; }
        .sign { display: flex; justify-content: space-between; margin-top: 12mm; break-inside: avoid; }
        .sign div { width: 60mm; border-top: 1px solid var(--line); padding-top: 1.5mm; color: var(--muted); font-size: 9.5pt; text-align: center; }
        .toolbar { position: fixed; top: 8px; inset-inline-end: 8px; display: flex; gap: 6px; z-index: 10; }
        .toolbar button, .toolbar a { font: inherit; font-size: 13px; padding: 6px 14px; border-radius: 6px; border: 1px solid #7a4b2a; background: #7a4b2a; color: #fff; cursor: pointer; text-decoration: none; }
        .toolbar a { background: #fff; color: #7a4b2a; }
        /* On screen: show the sheet as a page. */
        @media screen {
            body { background: #e9e6e1; padding: 24px 0; }
            .sheet { width: 210mm; min-height: 297mm; margin: 0 auto; background: #fff; position: relative; box-shadow: 0 6px 24px rgba(0,0,0,.15); }
            /* A long document does not break into pages on screen: show the letterhead header at the
               top and its footer at the bottom of the sheet instead of one page image under everything. */
            .letterhead { display: none; }
            .sheet::before, .sheet::after { content: ""; position: absolute; left: 0; right: 0; background-image: var(--letterhead);
                background-size: 210mm 297mm; background-repeat: no-repeat; pointer-events: none; }
            .sheet::before { top: 0; height: 46mm; background-position: top center; }
            .sheet::after { bottom: 0; height: 66mm; background-position: bottom center; }
        }
        @media print { .toolbar { display: none; } }
    </style>
</head>
<body>
<div class="toolbar">
    <button type="button" onclick="window.print()">{{ __('طباعة') }}</button>
    <a href="{{ url()->previous() }}">{{ __('رجوع') }}</a>
</div>
<div class="sheet" style="--letterhead: url('{{ \App\Support\Asset::url('img/letterhead-a4.png') }}')">
    <div class="letterhead"><img src="{{ \App\Support\Asset::url('img/letterhead-a4.png') }}" alt=""></div>
    <table class="frame">
        <thead><tr><td></td></tr></thead>
        <tfoot><tr><td></td></tr></tfoot>
        <tbody><tr><td><div class="doc">@yield('content')</div></td></tr></tbody>
    </table>
</div>
@if(request()->boolean('autoprint'))<script>window.addEventListener('load', () => setTimeout(() => window.print(), 300));</script>@endif
</body>
</html>
