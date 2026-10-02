<!DOCTYPE html>
<html lang="{{ $data->locale }}" dir="{{ $data->locale === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('sales.' . $data->type) }} {{ $data->document['number'] ?? '' }}</title>
    <style>
        body { font-family: dejavusans, sans-serif; color: #17253c; font-size: 11pt; padding: 12px; }
        h1 { font-size: 20pt; color: #255fd6; } h2 { font-size: 16pt; }
        table { width: 100%; border-collapse: collapse; margin: 18px 0; table-layout: fixed; }
        td, th { padding: 8px 5px; border-bottom: 1px solid #dce3ec; overflow-wrap: anywhere; }
        th { background: #eef2f7; } .number { direction: ltr; text-align: right; }
        .identity { background: #f5f7fa; padding: 12px; } .draft { color: #a15600; font-weight: bold; }
        @media print { body { padding: 0; } }
        @media(max-width: 480px) { body { font-size: 9pt; padding: 4px; } td, th { padding: 6px 2px; } }
    </style>
</head>
<body>
    <h1>{{ $data->company['name'] }}</h1>
    <p>{{ $data->company['phone'] ?? '' }} {{ $data->company['email'] ?? '' }}</p>
    <h2>{{ __('sales.' . $data->type) }} <span dir="ltr">{{ $data->document['number'] ?? '' }}</span></h2>
    @if(($data->document['status'] ?? null) === 'draft')<p class="draft">{{ __('sales.status_draft') }}</p>@endif
    <p dir="ltr">{{ $data->document['issue_date'] ?? '' }}</p>
    <div class="identity"><strong>{{ $data->customer['name'] }}</strong><p>{{ $data->customer['business_name'] ?? '' }}</p><p>{{ $data->customer['address'] ?? '' }}</p></div>
    @if($data->statement !== null)
        @foreach($data->statement['currencies'] as $currency => $group)
            <h3>{{ $currency }}</h3>
            <table><thead><tr><th>{{ __('sales.date') }}</th><th>{{ __('sales.number') }}</th><th>{{ __('sales.debit') }}</th><th>{{ __('sales.credit') }}</th><th>{{ __('sales.running_balance') }}</th></tr></thead><tbody>
                @foreach($group['entries'] as $entry)<tr><td>{{ $entry['date'] }}</td><td>{{ $entry['number'] }}</td><td dir="ltr">{{ $entry['debit'] }}</td><td dir="ltr">{{ $entry['credit'] }}</td><td dir="ltr">{{ $entry['balance'] }}</td></tr>@endforeach
            </tbody></table>
            <p>{{ __('sales.running_balance') }}: <span dir="ltr">{{ $group['closing_balance'] }} {{ $currency }}</span></p>
        @endforeach
    @else
        <table><thead><tr><th>{{ __('sales.product') }}</th><th>{{ __('sales.quantity') }}</th><th>{{ __('sales.unit_price') }}</th><th>{{ __('sales.total') }}</th></tr></thead><tbody>
            @foreach($data->lines as $line)<tr><td>@if(isset($line['image']))<img src="{{ $line['image'] }}" alt="" width="60" height="60">@endif {{ $line['item_description'] }}<br><span dir="ltr">{{ $line['sku'] ?? '' }}</span></td><td dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatQuantity($line['quantity']) }} {{ $line['unit_name'] ?? '' }}</td><td class="number">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($line['unit_price'], $data->document['currency_code']) }}</td><td class="number">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($line['total'], $data->document['currency_code']) }}</td></tr>@endforeach
        </tbody></table>
        <table><tbody>@foreach(['subtotal', 'discount_total', 'tax_total', 'grand_total'] as $field)<tr><td>{{ __('sales.' . (['discount_total' => 'discount', 'tax_total' => 'tax'][$field] ?? $field)) }}</td><td class="number">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($data->document[$field], $data->document['currency_code']) }} {{ $data->document['currency_code'] }}</td></tr>@endforeach</tbody></table>
    @endif
    @if($qrDataUri)<img src="{{ $qrDataUri }}" alt="QR" width="90" height="90">@endif
    <p>{{ $data->document['terms'] ?? '' }}</p><p>{{ $data->document['notes'] ?? '' }}</p>
</body>
</html>
