<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Receipt {{ sprintf('%06d', $batch->receipt_number) }}</title>
    <style>
        @page { margin: 42px; }
        body { color: #172033; font-family: DejaVu Sans, sans-serif; font-size: 11px; line-height: 1.5; }
        h1 { color: #0f766e; font-size: 24px; margin: 0; }
        .header { border-bottom: 2px solid #0f766e; margin-bottom: 22px; padding-bottom: 14px; }
        .receipt-number { color: #64748b; font-size: 10px; }
        .grid { margin-bottom: 20px; width: 100%; }
        .grid td { padding: 4px 0; vertical-align: top; width: 50%; }
        .label { color: #64748b; font-size: 9px; text-transform: uppercase; }
        .value { color: #0f172a; font-size: 12px; font-weight: bold; }
        .amount { background: #ecfdf5; border: 1px solid #99f6e4; color: #115e59; font-size: 22px; font-weight: bold; margin-bottom: 22px; padding: 14px; text-align: center; }
        .allocations { border-collapse: collapse; width: 100%; }
        .allocations th { background: #f1f5f9; padding: 8px; text-align: left; }
        .allocations td { border-bottom: 1px solid #e2e8f0; padding: 8px; }
        .right { text-align: right; }
        .footer { color: #64748b; font-size: 9px; margin-top: 28px; text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Payment Receipt</h1>
        <div>{{ $family->name }}</div>
        <div class="receipt-number">Receipt #{{ sprintf('%06d', $batch->receipt_number) }}</div>
    </div>

    <table class="grid">
        <tr>
            <td><div class="label">Received from</div><div class="value">{{ $batch->member_name }}</div></td>
            <td><div class="label">Payment date</div><div class="value">{{ $batch->paid_at->format('d M Y') }}</div></td>
        </tr>
        <tr>
            <td><div class="label">Method</div><div class="value">{{ $batch->method->label() }}</div></td>
            <td><div class="label">Reference</div><div class="value">{{ $batch->reference ?: '—' }}</div></td>
        </tr>
    </table>

    <div class="amount">{{ $family->currency }} {{ number_format($batch->total_amount, 2) }}</div>

    <table class="allocations">
        <thead><tr><th>Contribution Period</th><th class="right">Amount</th></tr></thead>
        <tbody>
        @foreach ($allocations as $allocation)
            <tr>
                <td>{{ $allocation->contribution?->period_label ?? 'Contribution' }}</td>
                <td class="right">{{ $family->currency }} {{ number_format($allocation->amount, 2) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <div class="footer">
        Generated {{ $generatedAt->format('d M Y, H:i T') }} · This receipt reflects the immutable posted payment batch.
    </div>
</body>
</html>
