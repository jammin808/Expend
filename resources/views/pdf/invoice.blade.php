@php
    $fmt = fn ($minor) => \App\Support\Money::format($minor, $invoice->currency);
@endphp
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 10.5pt; color: #111; }
    h1 { font-size: 20pt; margin: 0 0 4pt; }
    .muted { color: #555; }
    table { width: 100%; border-collapse: collapse; }
    .lines th { text-align: left; border-bottom: 1px solid #999; padding: 6pt 4pt; font-size: 9pt; color: #444; }
    .lines td { border-bottom: 1px solid #e5e5e5; padding: 6pt 4pt; vertical-align: top; }
    .num { text-align: right; white-space: nowrap; }
    .total td { font-weight: bold; font-size: 12pt; border-top: 2px solid #111; padding-top: 8pt; }
    .box { margin-top: 18pt; }
    /* The dates and PO number: labels stay on one line, and a long PO number (up to 100 characters) wraps,
       mid-word if it has to, so it never pushes the dates off the page. */
    .facts td { vertical-align: top; }
    .facts .muted { white-space: nowrap; }
    .facts .po { white-space: normal; overflow-wrap: anywhere; padding-left: 8pt; }
</style>
</head>
<body>
<table>
    <tr>
        <td style="vertical-align: top">
            <h1>Invoice</h1>
            <div class="muted">{{ $invoice->number }}</div>
        </td>
        <td style="text-align: right; vertical-align: top">
            <strong>{{ $business['name'] }}</strong><br>
            {!! nl2br(e($business['address'])) !!}<br>
            {{ $business['email'] }}
        </td>
    </tr>
</table>

<table class="box">
    <tr>
        <td style="vertical-align: top; width: 55%">
            <div class="muted">Bill to</div>
            <strong>{{ $invoice->bill_to_name }}</strong><br>
            {!! nl2br(e($invoice->bill_to_address)) !!}
        </td>
        <td style="vertical-align: top">
            <table class="facts">
                <tr><td class="muted">Issue date</td><td class="num">{{ $invoice->issue_date?->format('j M Y') }}</td></tr>
                <tr><td class="muted">Due date</td><td class="num">{{ $invoice->due_date?->format('j M Y') }}</td></tr>
                @if ($invoice->po_number)
                    <tr><td class="muted">PO number</td><td class="num po">{{ $invoice->po_number }}</td></tr>
                @endif
            </table>
        </td>
    </tr>
</table>

<table class="lines box">
    <thead><tr><th>Description</th><th class="num">Qty</th><th class="num">Unit price</th><th class="num">Amount</th></tr></thead>
    <tbody>
    @foreach ($invoice->lines as $line)
        <tr>
            <td>{{ $line->description }}</td>
            <td class="num">{{ rtrim(rtrim(number_format((float) $line->quantity, 2), '0'), '.') }}</td>
            <td class="num">{{ $fmt($line->unit_price_minor) }}</td>
            <td class="num">{{ $fmt($line->amount_minor) }}</td>
        </tr>
    @endforeach
        <tr class="total"><td colspan="3">Total due</td><td class="num">{{ $fmt($invoice->total_minor) }}</td></tr>
    </tbody>
</table>

@if ($business['bank'])
    <div class="box"><div class="muted">Payment details</div>{!! nl2br(e($business['bank'])) !!}</div>
@endif
@if ($invoice->notes)
    <div class="box muted">{!! nl2br(e($invoice->notes)) !!}</div>
@endif
</body>
</html>
