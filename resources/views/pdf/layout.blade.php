<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>@yield('title')</title>
    <style>
        @page { margin: 28px 32px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #1f2937; }
        h1, h2, h3 { margin: 0; }
        .header { width: 100%; border-bottom: 2px solid #0d6efd; padding-bottom: 10px; margin-bottom: 14px; }
        .header td { vertical-align: top; }
        .shop { font-size: 18px; font-weight: bold; color: #0d6efd; }
        .doc-title { font-size: 15px; font-weight: bold; text-align: right; text-transform: uppercase; letter-spacing: 1px; }
        .muted { color: #6b7280; }
        .small { font-size: 9px; }
        .right { text-align: right; }
        .center { text-align: center; }
        .bold { font-weight: bold; }
        table { border-collapse: collapse; width: 100%; }
        .grid th { background: #f1f5f9; color: #475569; font-size: 9px; text-transform: uppercase; letter-spacing: .4px; text-align: left; padding: 6px; border-bottom: 1px solid #cbd5e1; }
        .grid td { padding: 6px; border-bottom: 1px solid #e5e7eb; }
        .grid tr.total td { font-weight: bold; background: #f8fafc; border-top: 1px solid #94a3b8; }
        .box { border: 1px solid #e5e7eb; border-radius: 6px; padding: 8px 10px; }
        .info td { padding: 2px 0; }
        .badge { display: inline-block; padding: 2px 6px; border-radius: 4px; font-size: 9px; background: #e2e8f0; }
        .badge-paid { background: #dcfce7; color: #166534; }
        .badge-partial { background: #fef9c3; color: #854d0e; }
        .badge-unpaid { background: #fee2e2; color: #991b1b; }
        .summary td { padding: 4px 6px; }
        .summary tr.grand td { font-size: 13px; font-weight: bold; border-top: 2px solid #0d6efd; }
        .footer { position: fixed; bottom: -12px; left: 0; right: 0; text-align: center; font-size: 8.5px; color: #94a3b8; }
        .mt { margin-top: 14px; }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td>
                <div class="shop">{{ $tenant->name }}</div>
                @if ($tenant->address)<div class="muted">{{ $tenant->address }}</div>@endif
                @if ($tenant->phone)<div class="muted">Phone: {{ $tenant->phone }}</div>@endif
            </td>
            <td class="doc-title">@yield('title')<div class="muted small" style="text-transform:none;letter-spacing:0;font-weight:normal">Generated {{ now()->format('d M Y h:i A') }}</div></td>
        </tr>
    </table>

    @yield('content')

    <div class="footer">{{ $tenant->name }} · {{ config('app.name') }} · This is a computer generated document.</div>
</body>
</html>
