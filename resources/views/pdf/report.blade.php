<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $report['title'] }}</title>
    <style>
        @page { margin: 30px 28px; }
        body { color: #172033; font-family: DejaVu Sans, sans-serif; font-size: 9px; line-height: 1.4; }
        h1 { color: #0f172a; font-size: 20px; margin: 0 0 4px; }
        .meta { color: #64748b; margin-bottom: 18px; }
        .filters, .totals { background: #f8fafc; border: 1px solid #dbe3ee; margin-bottom: 14px; padding: 9px; }
        .filters strong, .totals strong { color: #334155; }
        table { border-collapse: collapse; table-layout: fixed; width: 100%; }
        th { background: #0f766e; color: white; font-size: 8px; padding: 6px 4px; text-align: left; }
        td { border-bottom: 1px solid #e2e8f0; overflow-wrap: break-word; padding: 6px 4px; vertical-align: top; }
        tr:nth-child(even) td { background: #f8fafc; }
        .empty { border: 1px solid #dbe3ee; color: #64748b; padding: 24px; text-align: center; }
        .footer { color: #94a3b8; font-size: 8px; margin-top: 16px; text-align: right; }
    </style>
</head>
<body>
    <h1>{{ $report['title'] }}</h1>
    <div class="meta">{{ $family->name }} · Generated {{ $generatedAt->format('d M Y, H:i T') }}</div>

    <div class="filters">
        <strong>Period:</strong>
        {{ $report['filters']['date_from'] ?? 'Beginning' }} to {{ $report['filters']['date_to'] ?? 'Today' }}
        @if (filled($report['filters']['category'] ?? null)) · <strong>Category:</strong> {{ $report['filters']['category'] }} @endif
        @if (filled($report['filters']['status'] ?? null)) · <strong>Status:</strong> {{ $report['filters']['status'] }} @endif
    </div>

    @if ($report['totals'] !== [])
        <div class="totals">
            @foreach ($report['totals'] as $label => $value)
                <strong>{{ str($label)->headline() }}:</strong> {{ is_numeric($value) ? number_format((float) $value, 2) : $value }}@unless ($loop->last) · @endunless
            @endforeach
        </div>
    @endif

    @if ($report['rows'] === [])
        <div class="empty">No records matched the selected filters.</div>
    @else
        <table>
            <thead>
            <tr>
                @foreach ($report['columns'] as $label)
                    <th>{{ $label }}</th>
                @endforeach
            </tr>
            </thead>
            <tbody>
            @foreach ($report['rows'] as $row)
                <tr>
                    @foreach ($report['columns'] as $key => $label)
                        <td>{{ is_array($row[$key] ?? null) ? json_encode($row[$key]) : ($row[$key] ?? '—') }}</td>
                    @endforeach
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    <div class="footer">Private financial record · {{ $family->currency }}</div>
</body>
</html>
