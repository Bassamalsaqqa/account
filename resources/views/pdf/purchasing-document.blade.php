<!DOCTYPE html>
<html lang="{{ $data['locale'] }}" dir="{{ $data['locale'] === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('purchasing_documents.' . ($data['type'] ?? 'purchase')) }} {{ $data['document']['number'] ?? '' }}</title>
    <style>
        body {
            font-family: dejavusans, sans-serif;
            color: #17253c;
            font-size: 10pt;
            line-height: 1.4;
            padding: 16px;
            margin: 0;
            background: #ffffff;
        }
        h1 { font-size: 18pt; margin: 0 0 4px 0; color: #17253c; }
        h2 { font-size: 14pt; margin: 0 0 6px 0; color: #1e3a8a; }
        h3 { font-size: 12pt; margin: 12px 0 6px 0; color: #17253c; }
        h4 { font-size: 10pt; margin: 8px 0 4px 0; color: #475467; }
        p { margin: 2px 0; }

        .header-table { width: 100%; border-collapse: collapse; margin-bottom: 14px; table-layout: fixed; }
        .header-table td { border: none; padding: 2px 4px; vertical-align: top; }
        .company-logo { max-height: 52px; max-width: 170px; margin-bottom: 6px; }

        .status-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 8.5pt;
            font-weight: bold;
            margin: 4px 0;
        }
        .status-draft { background: #fff5df; color: #a15600; border: 1px solid #f9dfad; }
        .status-posted { background: #eaf8f0; color: #14804a; border: 1px solid #b7ebd0; }
        .status-void, .status-voided { background: #fff0ee; color: #b42318; border: 1px solid #fecdca; }
        .status-reversed { background: #f2f4f7; color: #475467; border: 1px solid #d0d5dd; }
        .status-quantity-only { background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }
        .status-default { background: #f2f4f7; color: #334155; border: 1px solid #e2e8f0; }

        .identity-box {
            background: #f8fafc;
            border: 1px solid #e4e9f1;
            border-radius: 6px;
            padding: 10px 12px;
            margin-bottom: 14px;
        }

        table.data-table {
            width: 100%;
            border-collapse: collapse;
            margin: 12px 0;
            table-layout: fixed;
        }
        table.data-table thead { display: table-header-group; }
        table.data-table th {
            background: #eef2f7;
            color: #334155;
            font-weight: bold;
            font-size: 9pt;
            padding: 7px 6px;
            border-bottom: 2px solid #cbd5e1;
            text-align: inherit;
        }
        table.data-table td {
            padding: 7px 6px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 9.5pt;
            vertical-align: top;
            overflow-wrap: anywhere;
        }
        table.data-table tr { page-break-inside: avoid; }

        .number, .money { direction: ltr; unicode-bidi: embed; text-align: right; }
        [dir="rtl"] .number, [dir="rtl"] .money { text-align: left; }
        .code, .date { direction: ltr; unicode-bidi: embed; }

        table.totals-table {
            width: 100%;
            border-collapse: collapse;
            margin: 12px 0;
            page-break-inside: avoid;
        }
        table.totals-table td {
            padding: 6px 8px;
            border-bottom: 1px solid #e2e8f0;
        }
        table.totals-table tr.grand-total-row td {
            border-top: 2px solid #17253c;
            border-bottom: 2px solid #17253c;
            font-size: 11pt;
            font-weight: bold;
            color: #17253c;
            background: #f8fafc;
        }
        table.totals-table tr.base-amount-row td {
            font-size: 8.5pt;
            color: #64748b;
            border-bottom: none;
        }

        .terms-container {
            margin-top: 16px;
            border-top: 1px solid #e2e8f0;
            padding-top: 10px;
        }
        .terms-block, .notes-block, .reversal-block {
            margin-bottom: 8px;
            font-size: 8.5pt;
            color: #475467;
        }
        .terms-block strong, .notes-block strong, .reversal-block strong {
            display: block;
            color: #17253c;
            margin-bottom: 2px;
            font-size: 9pt;
        }

        .decorative-footer {
            font-size: 8pt;
            color: #64748b;
            text-align: center;
            margin-top: 14px;
            border-top: 1px solid #f1f5f9;
            padding-top: 6px;
        }

        .later-applications-section {
            margin-top: 18px;
            padding: 10px;
            background: #fafbfc;
            border: 1px dashed #cbd5e1;
            border-radius: 6px;
            page-break-inside: avoid;
        }
        .later-applications-section h4 {
            margin: 0 0 6px 0;
            color: #475467;
            font-size: 9pt;
        }

        .lots-list {
            margin: 4px 0 0 0;
            padding-inline-start: 16px;
            font-size: 8.5pt;
            color: #475467;
        }

        @media print {
            body { padding: 0; }
            .print-controls { display: none; }
        }
        @media screen and (max-width: 600px) {
            body { font-size: 9pt; padding: 6px; }
            td, th { padding: 5px 3px; }
        }
    </style>
</head>
<body>
    @if($printControls ?? false)
        <div class="print-controls" style="margin-bottom:16px;">
            <button type="button" onclick="window.print()" style="min-height:44px;padding:8px 20px;font-size:11pt;cursor:pointer;">{{ __('purchasing_documents.print') }}</button>
        </div>
    @endif

    @php
        $status = $data['document']['status'] ?? null;
        $withCost = (bool) ($data['with_cost'] ?? false);
        $type = $data['type'] ?? 'purchase';
    @endphp

    {{-- Header: Company & Document Metadata --}}
    <table class="header-table">
        <tr>
            <td style="width: 55%;">
                @if(!empty($data['presentation']['logo']))
                    <div><img src="{{ $data['presentation']['logo'] }}" alt="Logo" class="company-logo"></div>
                @endif
                <h1>{{ $data['company']['name'] ?? '' }}</h1>
                @if(!empty($data['company']['business_name']))
                    <p>{{ $data['company']['business_name'] }}</p>
                @endif
                @if(!empty($data['company']['address']))
                    <p>{{ $data['company']['address'] }}</p>
                @endif
                <p>
                    @if(!empty($data['company']['phone']))<span dir="ltr">{{ $data['company']['phone'] }}</span>@endif
                    @if(!empty($data['company']['email'])) <span dir="ltr">{{ $data['company']['email'] }}</span>@endif
                </p>
                @if(!empty($data['company']['tax_number']))
                    <p>{{ __('purchasing_documents.tax_number') }}: <span class="code" dir="ltr">{{ $data['company']['tax_number'] }}</span></p>
                @endif
                @if(!empty($data['company']['registration_number']))
                    <p>{{ __('purchasing_documents.registration_number') }}: <span class="code" dir="ltr">{{ $data['company']['registration_number'] }}</span></p>
                @endif
            </td>
            <td style="width: 45%; text-align: {{ $data['locale'] === 'ar' ? 'left' : 'right' }};">
                <h2>{{ __('purchasing_documents.' . $type) }} <span class="code" dir="ltr">{{ $data['document']['number'] ?? '' }}</span></h2>

                {{-- Truthful quantity-only badge if costs/monetary projections are restricted --}}
                @if(! $withCost)
                    <div><span class="status-badge status-quantity-only">{{ __('purchasing_documents.quantity_only_badge') }}</span></div>
                @endif

                @if($status)
                    @php
                        $statusClass = match($status) {
                            'draft' => 'status-draft',
                            'posted' => 'status-posted',
                            'void', 'voided' => 'status-void',
                            'reversed' => 'status-reversed',
                            default => 'status-default',
                        };
                        $statusText = match($status) {
                            'draft' => __('purchasing_documents.status_draft'),
                            'posted' => __('purchasing_documents.status_posted'),
                            'void', 'voided' => __('purchasing_documents.status_void'),
                            'reversed' => __('purchasing_documents.status_reversed'),
                            default => $status,
                        };
                    @endphp
                    <div><span class="status-badge {{ $statusClass }}">{{ $statusText }}</span></div>
                @endif

                @if(!empty($data['document']['issue_date']))
                    <p>{{ __('purchasing_documents.date') }}: <span class="date" dir="ltr">{{ $data['document']['issue_date'] }}</span></p>
                @endif
                @if(!empty($data['document']['due_date']))
                    <p>{{ __('purchasing_documents.due_date') }}: <span class="date" dir="ltr">{{ $data['document']['due_date'] }}</span></p>
                @endif
                @if(!empty($data['document']['posted_at']))
                    <p>{{ __('purchasing_documents.posted_at') }}: <span class="date" dir="ltr">{{ $data['document']['posted_at'] }}</span></p>
                @endif
                @if(!empty($data['document']['from_date']))
                    <p>{{ __('purchasing_documents.from_date') }}: <span class="date" dir="ltr">{{ $data['document']['from_date'] }}</span></p>
                @endif
                @if(!empty($data['document']['to_date']))
                    <p>{{ __('purchasing_documents.to_date') }}: <span class="date" dir="ltr">{{ $data['document']['to_date'] }}</span></p>
                @endif
                @if(!empty($data['document']['vendor_invoice_number']))
                    <p>{{ __('purchasing_documents.vendor_invoice_number') }}: <span class="code" dir="ltr">{{ $data['document']['vendor_invoice_number'] }}</span></p>
                @endif
                @if(!empty($data['document']['original_reference']))
                    <p>{{ __('purchasing_documents.original_reference') }}: <span class="code" dir="ltr">{{ $data['document']['original_reference'] }}</span></p>
                @endif
                @if(!empty($data['document']['reference']))
                    <p>{{ __('purchasing_documents.reference') }}: <span class="code" dir="ltr">{{ $data['document']['reference'] }}</span></p>
                @endif
                @if(!empty($data['document']['warehouse_name']))
                    <p>{{ __('purchasing_documents.warehouse') }}: {{ $data['document']['warehouse_name'] }}</p>
                @endif
                @if(!empty($data['document']['payment_method']))
                    <p>{{ __('purchasing_documents.payment_method') }}: {{ __('purchasing_documents.method_' . $data['document']['payment_method']) }}</p>
                @endif
                @if(!empty($data['document']['check_number']))
                    <p>{{ __('purchasing_documents.check_number') }}: <span class="code" dir="ltr">{{ $data['document']['check_number'] }}</span></p>
                @endif
                @if(!empty($data['document']['money_account']))
                    <p>{{ __('purchasing_documents.money_account') }}: {{ $data['document']['money_account'] }}</p>
                @endif

                @if($withCost && !empty($data['document']['currency_code']))
                    <p>{{ __('purchasing_documents.currency') }}: <span dir="ltr">{{ $data['document']['currency_code'] }}</span></p>
                @endif
                @if($withCost && !empty($data['document']['base_currency_code']) && $data['document']['base_currency_code'] !== ($data['document']['currency_code'] ?? null))
                    <p>{{ __('purchasing_documents.base_currency') }}: <span dir="ltr">{{ $data['document']['base_currency_code'] }}</span>
                    @if(!empty($data['document']['exchange_rate']))
                        | {{ __('purchasing_documents.exchange_rate') }}: <span dir="ltr">{{ $data['document']['exchange_rate'] }}</span>
                    @endif
                    </p>
                @endif
            </td>
        </tr>
    </table>

    {{-- Party / Vendor Identity Box --}}
    <div class="identity-box">
        <strong>{{ $data['vendor']['name'] ?? '' }}</strong>
        @if(!empty($data['vendor']['business_name']))
            <p>{{ $data['vendor']['business_name'] }}</p>
        @endif
        @if(!empty($data['vendor']['address']))
            <p>{{ $data['vendor']['address'] }}</p>
        @endif
        <p>
            @if(!empty($data['vendor']['phone']))<span dir="ltr">{{ $data['vendor']['phone'] }}</span>@endif
            @if(!empty($data['vendor']['email'])) <span dir="ltr">{{ $data['vendor']['email'] }}</span>@endif
        </p>
        @if(!empty($data['vendor']['tax_number']))
            <p>{{ __('purchasing_documents.tax_number') }}: <span class="code" dir="ltr">{{ $data['vendor']['tax_number'] }}</span></p>
        @endif
        @if(!empty($data['vendor']['registration_number']))
            <p>{{ __('purchasing_documents.registration_number') }}: <span class="code" dir="ltr">{{ $data['vendor']['registration_number'] }}</span></p>
        @endif
    </div>

    {{-- Content Section: Statement vs Payment vs Commercial Document --}}
    @if($data['statement'] !== null)
        {{-- Vendor Statement Mode --}}
        @foreach($data['statement']['currencies'] ?? [] as $currency => $group)
            <h3>{{ $currency }}</h3>
            @if(isset($group['opening_balance']))
                <p><strong>{{ __('purchasing_documents.opening_balance') }}:</strong> <span class="number" dir="ltr">{{ $group['opening_balance'] }} {{ $currency }}</span></p>
            @endif
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 16%;">{{ __('purchasing_documents.date') }}</th>
                        <th style="width: 24%;">{{ __('purchasing_documents.number') }}</th>
                        <th style="width: 22%;">{{ __('purchasing_documents.product') }}</th>
                        <th style="width: 12%; text-align: right;" class="number">{{ __('purchasing_documents.debit') }}</th>
                        <th style="width: 12%; text-align: right;" class="number">{{ __('purchasing_documents.credit') }}</th>
                        <th style="width: 14%; text-align: right;" class="number">{{ __('purchasing_documents.running_balance') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($group['entries'] ?? [] as $entry)
                        <tr>
                            <td class="date" dir="ltr">{{ $entry['date'] }}</td>
                            <td class="code" dir="ltr">{{ $entry['number'] }}<br><span style="font-size: 8pt; color: #64748b;">{{ $entry['type'] }}</span></td>
                            <td>{{ $entry['description'] }}
                                @if(!empty($entry['reference']))<br><span class="code" dir="ltr" style="font-size: 8pt; color: #64748b;">{{ $entry['reference'] }}</span>@endif
                            </td>
                            <td class="number" dir="ltr">{{ $entry['debit'] }}</td>
                            <td class="number" dir="ltr">{{ $entry['credit'] }}</td>
                            <td class="number" dir="ltr">{{ $entry['balance'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <table class="totals-table">
                <tbody>
                    <tr>
                        <td style="width: 50%;">{{ __('purchasing_documents.total_debits') }}</td>
                        <td class="number" style="width: 50%;" dir="ltr">{{ $group['total_debits'] }} {{ $currency }}</td>
                    </tr>
                    <tr>
                        <td>{{ __('purchasing_documents.total_credits') }}</td>
                        <td class="number" dir="ltr">{{ $group['total_credits'] }} {{ $currency }}</td>
                    </tr>
                    <tr class="grand-total-row">
                        <td><strong>{{ __('purchasing_documents.closing_balance') }}</strong></td>
                        <td class="number" dir="ltr"><strong>{{ $group['closing_balance'] }} {{ $currency }}</strong></td>
                    </tr>
                </tbody>
            </table>

            @if(isset($group['aging']))
                <h4>{{ __('purchasing_documents.aging') }}</h4>
                <table class="data-table">
                    <tbody>
                        @foreach($group['aging'] as $bucket => $amount)
                            <tr>
                                <td style="width: 60%;">{{ \Illuminate\Support\Facades\Lang::has('purchasing_documents.' . $bucket) ? __('purchasing_documents.' . $bucket) : $bucket }}</td>
                                <td class="number" style="width: 40%;" dir="ltr">{{ $amount }} {{ $currency }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        @endforeach

    @elseif($type === 'vendor_payment')
        {{-- Vendor Payment Mode --}}
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 35%;">{{ __('purchasing_documents.invoice') }}</th>
                    <th style="width: 20%; text-align: right;" class="number">{{ __('purchasing_documents.purchase_principal') }}</th>
                    <th style="width: 22%; text-align: right;" class="number">{{ __('purchasing_documents.payment_applied') }}</th>
                    <th style="width: 23%; text-align: right;" class="number">{{ __('purchasing_documents.settlement_base_value') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($data['lines'] as $line)
                    @php
                        $principalCurr = $line['currency_code'] ?? ($data['document']['currency_code'] ?? '');
                        $paymentCurr = $line['payment_currency_code'] ?? ($data['document']['currency_code'] ?? '');
                        $baseCurr = $line['base_currency_code'] ?? ($data['document']['base_currency_code'] ?? '');
                    @endphp
                    <tr>
                        <td><strong>{{ $line['item_description'] }}</strong></td>
                        <td class="number" dir="ltr">{{ $line['allocated_amount'] }} {{ $principalCurr }}</td>
                        <td class="number" dir="ltr">{{ $line['payment_currency_amount'] }} {{ $paymentCurr }}</td>
                        <td class="number" dir="ltr">{{ $line['settlement_base_value'] }} {{ $baseCurr }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" style="text-align: center; color: #64748b;">—</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        {{-- Totals for Payment --}}
        <table class="totals-table">
            <tbody>
                <tr class="grand-total-row">
                    <td style="width: 60%;"><strong>{{ __('purchasing_documents.payment_amount') }}</strong></td>
                    <td class="number" style="width: 40%;" dir="ltr">
                        <strong>{{ $data['document']['amount'] ?? '' }} {{ $data['document']['currency_code'] ?? '' }}</strong>
                    </td>
                </tr>
                @if(!empty($data['document']['amount_base']) && !empty($data['document']['base_currency_code']) && $data['document']['base_currency_code'] !== ($data['document']['currency_code'] ?? null))
                    <tr class="base-amount-row">
                        <td>{{ __('purchasing_documents.amount_in_base_currency') }} ({{ $data['document']['base_currency_code'] }})</td>
                        <td class="number" dir="ltr">
                            {{ $data['document']['amount_base'] }} {{ $data['document']['base_currency_code'] }}
                        </td>
                    </tr>
                @endif
                @if(!empty($data['document']['unallocated_amount']) && $data['document']['unallocated_amount'] !== '0' && $data['document']['unallocated_amount'] !== '0.00' && $data['document']['unallocated_amount'] !== '0.000000')
                    <tr>
                        <td>{{ __('purchasing_documents.unallocated_amount') }}</td>
                        <td class="number" dir="ltr">{{ $data['document']['unallocated_amount'] }} {{ $data['document']['currency_code'] ?? '' }}</td>
                    </tr>
                @endif
            </tbody>
        </table>

        {{-- Distinct Later Applications Appendix --}}
        @if(!empty($data['applications']))
            <div class="later-applications-section">
                <h4>{{ __('purchasing_documents.later_applications_appendix') }}</h4>
                <table class="data-table" style="margin: 4px 0;">
                    <thead>
                        <tr>
                            <th style="width: 25%;">{{ __('purchasing_documents.invoice') }}</th>
                            <th style="width: 20%;">{{ __('purchasing_documents.application_date') }}</th>
                            <th style="width: 15%;">{{ __('purchasing_documents.status_posted') }}</th>
                            <th style="width: 20%; text-align: right;" class="number">{{ __('purchasing_documents.payment_applied') }}</th>
                            <th style="width: 20%; text-align: right;" class="number">{{ __('purchasing_documents.settlement_base_value') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($data['applications'] as $app)
                            @php
                                $appPayCurr = $app['payment_currency_code'] ?? ($data['document']['currency_code'] ?? '');
                                $appBaseCurr = $app['base_currency_code'] ?? ($data['document']['base_currency_code'] ?? '');
                            @endphp
                            <tr>
                                <td>{{ $app['item_description'] }}</td>
                                <td class="date" dir="ltr">{{ $app['application_date'] }}</td>
                                <td>
                                    {{ __('purchasing_documents.status_' . ($app['status'] ?? 'posted')) }}
                                    @if(!empty($app['reversed_at']))
                                        <br><span style="font-size: 7.5pt; color: #b42318;">{{ $app['reversed_at'] }}</span>
                                    @endif
                                </td>
                                <td class="number" dir="ltr">{{ $app['payment_currency_amount'] }} {{ $appPayCurr }}</td>
                                <td class="number" dir="ltr">{{ $app['settlement_base_value'] }} {{ $appBaseCurr }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

    @else
        {{-- Commercial Document: Purchase Bill or Purchase Return --}}
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 5%;">{{ __('purchasing_documents.line_number') }}</th>
                    <th style="width: {{ $withCost ? '35%' : '60%' }};">{{ __('purchasing_documents.product') }}</th>
                    <th style="width: {{ $withCost ? '15%' : '20%' }};">{{ __('purchasing_documents.sku') }}</th>
                    <th style="width: {{ $withCost ? '15%' : '15%' }}; text-align: right;" class="number">{{ __('purchasing_documents.quantity') }}</th>
                    @if($withCost)
                        <th style="width: 10%; text-align: right;" class="number">{{ __('purchasing_documents.unit_cost') }}</th>
                        <th style="width: 10%; text-align: right;" class="number">{{ __('purchasing_documents.discount') }}</th>
                        <th style="width: 10%; text-align: right;" class="number">{{ __('purchasing_documents.total') }}</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach($data['lines'] as $line)
                    <tr>
                        <td>{{ $line['line_number'] ?? $loop->iteration }}</td>
                        <td>
                            <strong>{{ $line['item_description'] }}</strong>
                            @if(!empty($line['lots']))
                                <ul class="lots-list">
                                    @foreach($line['lots'] as $lot)
                                        <li>
                                            {{ __('purchasing_documents.lot_number') }}: <span class="code" dir="ltr">{{ $lot['lot_number'] }}</span>
                                            @if(!empty($lot['expiry_date'])) | {{ __('purchasing_documents.expiry_date') }}: <span class="date" dir="ltr">{{ $lot['expiry_date'] }}</span>@endif
                                            | {{ __('purchasing_documents.quantity') }}: <span class="number" dir="ltr">{{ $lot['quantity'] }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                            @if(!empty($line['allocations']))
                                <ul class="lots-list">
                                    @foreach($line['allocations'] as $alloc)
                                        <li>
                                            {{ __('purchasing_documents.lot_number') }}: <span class="code" dir="ltr">{{ $alloc['lot_number'] }}</span>
                                            @if(!empty($alloc['expiry_date'])) | {{ __('purchasing_documents.expiry_date') }}: <span class="date" dir="ltr">{{ $alloc['expiry_date'] }}</span>@endif
                                            | {{ __('purchasing_documents.quantity') }}: <span class="number" dir="ltr">{{ $alloc['quantity'] }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </td>
                        <td class="code" dir="ltr">{{ $line['sku'] }}</td>
                        <td class="number" dir="ltr">
                            {{ $line['quantity'] }}
                            @if(!empty($line['unit_name']))
                                <span style="font-size: 8.5pt; color: #475467;">{{ $line['unit_name'] }}</span>
                            @endif
                        </td>
                        @if($withCost)
                            <td class="number" dir="ltr">{{ $line['unit_cost'] }}</td>
                            <td class="number" dir="ltr">{{ $line['discount'] }}</td>
                            <td class="number" dir="ltr">{{ $line['total'] }}</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>

        {{-- Totals Table (Only when with_cost is true) --}}
        @if($withCost)
            <table class="totals-table">
                <tbody>
                    @if(isset($data['document']['subtotal']))
                        <tr>
                            <td style="width: 60%;">{{ __('purchasing_documents.subtotal') }}</td>
                            <td class="number" style="width: 40%;" dir="ltr">
                                {{ $data['document']['subtotal'] }} {{ $data['document']['currency_code'] ?? '' }}
                            </td>
                        </tr>
                    @endif
                    @if(isset($data['document']['discount_total']) && $data['document']['discount_total'] !== '0' && $data['document']['discount_total'] !== '0.00' && $data['document']['discount_total'] !== '0.000000')
                        <tr>
                            <td>{{ __('purchasing_documents.discount') }}</td>
                            <td class="number" dir="ltr">
                                {{ $data['document']['discount_total'] }} {{ $data['document']['currency_code'] ?? '' }}
                            </td>
                        </tr>
                    @endif
                    @if(isset($data['document']['tax_total']) && $data['document']['tax_total'] !== '0' && $data['document']['tax_total'] !== '0.00' && $data['document']['tax_total'] !== '0.000000')
                        <tr>
                            <td>{{ __('purchasing_documents.tax') }}</td>
                            <td class="number" dir="ltr">
                                {{ $data['document']['tax_total'] }} {{ $data['document']['currency_code'] ?? '' }}
                            </td>
                        </tr>
                    @endif
                    @if(isset($data['document']['grand_total']))
                        <tr class="grand-total-row">
                            <td><strong>{{ __('purchasing_documents.grand_total') }}</strong></td>
                            <td class="number" dir="ltr">
                                <strong>{{ $data['document']['grand_total'] }} {{ $data['document']['currency_code'] ?? '' }}</strong>
                            </td>
                        </tr>
                    @endif
                    @if(!empty($data['document']['amount_base']) && !empty($data['document']['base_currency_code']) && $data['document']['base_currency_code'] !== ($data['document']['currency_code'] ?? null))
                        <tr class="base-amount-row">
                            <td>{{ __('purchasing_documents.amount_in_base_currency') }} ({{ $data['document']['base_currency_code'] }})</td>
                            <td class="number" dir="ltr">
                                {{ $data['document']['amount_base'] }} {{ $data['document']['base_currency_code'] }}
                            </td>
                        </tr>
                    @endif
                    @if(!empty($data['document']['total_landed_cost_base']) && $data['document']['total_landed_cost_base'] !== '0' && $data['document']['total_landed_cost_base'] !== '0.00' && $data['document']['total_landed_cost_base'] !== '0.000000')
                        <tr class="base-amount-row">
                            <td>{{ __('purchasing_documents.total_landed_cost_base') }}</td>
                            <td class="number" dir="ltr">
                                {{ $data['document']['total_landed_cost_base'] }} {{ $data['document']['base_currency_code'] ?? '' }}
                            </td>
                        </tr>
                    @endif
                </tbody>
            </table>
        @endif
    @endif

    {{-- Notes, Terms, Reversal Reason, Footer --}}
    @if(!empty($data['document']['notes']) || !empty($data['document']['terms']) || !empty($data['document']['reason']) || !empty($data['document']['reversal_reason']) || !empty($data['presentation']['footer']))
        <div class="terms-container">
            @if(!empty($data['document']['reason']))
                <div class="notes-block">
                    <strong>{{ __('purchasing_documents.reason') }}</strong>
                    <p>{{ $data['document']['reason'] }}</p>
                </div>
            @endif

            @if(!empty($data['document']['reversal_reason']))
                <div class="reversal-block">
                    <strong>{{ __('purchasing_documents.reversal_reason') }}</strong>
                    <p>{{ $data['document']['reversal_reason'] }}</p>
                </div>
            @endif

            @if(!empty($data['document']['notes']))
                <div class="notes-block">
                    <strong>{{ __('purchasing_documents.notes') }}</strong>
                    <p>{{ $data['document']['notes'] }}</p>
                </div>
            @endif

            @if(!empty($data['document']['terms']))
                <div class="terms-block">
                    <strong>{{ __('purchasing_documents.terms') }}</strong>
                    <p>{{ $data['document']['terms'] }}</p>
                </div>
            @endif

            @if(!empty($data['presentation']['footer']))
                <div class="decorative-footer">
                    <p>{{ $data['presentation']['footer'] }}</p>
                </div>
            @endif
        </div>
    @endif
</body>
</html>
