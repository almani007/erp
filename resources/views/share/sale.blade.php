<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $sale->sale_no }} — {{ $company->legal_name ?? '' }}</title>
    <style>
        body { font: 15px/1.5 system-ui, sans-serif; color: #111827; background: #f3f4f6; margin: 0; padding: 16px; }
        .card { max-width: 640px; margin: 0 auto; background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
        h1 { font-size: 20px; margin: 0; color: #1e3a8a; } .muted { color: #6b7280; font-size: 13px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; } td, th { padding: 6px 4px; border-bottom: 1px solid #e5e7eb; text-align: left; }
        .r { text-align: right; } .total td { font-weight: 700; font-size: 17px; border-top: 2px solid #1e3a8a; }
        .badge { display: inline-block; padding: 2px 10px; border-radius: 10px; font-size: 13px; }
        .due { background: #fef3c7; color: #92400e; } .paid { background: #dcfce7; color: #166534; }
        @media print { body { background: #fff; } .card { box-shadow: none; } .noprint { display: none; } }
    </style>
</head>
<body>
<div class="card">
    <h1>{{ $company->legal_name ?? '' }}</h1>
    <div class="muted">{{ $branch->name ?? '' }}@if(!empty($branch->phone)) · {{ $branch->phone }}@endif @if(!empty($company->tax_registration_no)) · NTN {{ $company->tax_registration_no }}@endif</div>
    <hr>
    <div><b>{{ $sale->channel === 'invoice' ? 'Invoice' : 'Receipt' }} {{ $sale->sale_no }}</b> · {{ $sale->sold_at->format('d M Y h:i A') }}</div>
    @if($sale->customer)<div>{{ $sale->customer->name }}</div>@endif
    @if($sale->due_date)<div class="muted">Due {{ $sale->due_date->format('d M Y') }}</div>@endif
    @if($sale->fiscal_invoice_no)<div class="muted">FBR invoice no. {{ $sale->fiscal_invoice_no }}</div>@endif
    @if($sale->status === 'voided')<p><span class="badge due">This document was cancelled.</span></p>@endif
    <table>
        <tr><th>Item</th><th class="r">Qty</th><th class="r">Amount</th></tr>
        @foreach($sale->items as $item)
            <tr><td>{{ $item->product_name }}</td><td class="r">{{ rtrim(rtrim(number_format((float) $item->quantity, 3), '0'), '.') }}</td><td class="r">{{ number_format((float) $item->line_total, 2) }}</td></tr>
        @endforeach
        <tr><td colspan="2">Sales tax</td><td class="r">{{ number_format((float) $sale->tax_total, 2) }}</td></tr>
        <tr class="total"><td colspan="2">Total {{ $sale->currency }}</td><td class="r">{{ number_format((float) $sale->grand_total, 2) }}</td></tr>
    </table>
    @if((float) $sale->balance_due > 0)
        <p><span class="badge due">Balance due {{ $sale->currency }} {{ number_format((float) $sale->balance_due, 2) }}</span></p>
    @elseif($sale->status === 'completed')
        <p><span class="badge paid">Paid</span></p>
    @endif
    <p class="noprint"><button onclick="window.print()">Print / save as PDF</button></p>
</div>
</body>
</html>
