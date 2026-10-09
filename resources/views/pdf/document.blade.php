<!DOCTYPE html>
<html lang="{{ $data->locale }}" dir="{{ $data->locale === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ \Illuminate\Support\Facades\Lang::has('documents.' . $data->type) ? __('documents.' . $data->type) : (\Illuminate\Support\Facades\Lang::has('sales.' . $data->type) ? __('sales.' . $data->type) : $data->type) }} {{ $data->document['number'] ?? '' }}</title>
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
        h2 { font-size: 14pt; margin: 0 0 6px 0; color: #255fd6; }
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
        .terms-block, .notes-block {
            margin-bottom: 8px;
            font-size: 8.5pt;
            color: #475467;
        }
        .terms-block strong, .notes-block strong {
            display: block;
            color: #17253c;
            margin-bottom: 2px;
            font-size: 9pt;
        }

        .footer-qr-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 14px;
            page-break-inside: avoid;
        }
        .footer-qr-table td {
            border: none;
            padding: 4px;
            vertical-align: middle;
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
            <button type="button" onclick="window.print()" style="min-height:44px;padding:8px 20px;">{{ __('documents.print') }}</button>
        </div>
    @endif
    @php
        $transDoc = function(string $key) {
            if (\Illuminate\Support\Facades\Lang::has('documents.' . $key)) {
                return __('documents.' . $key);
            }
            if (\Illuminate\Support\Facades\Lang::has('sales.' . $key)) {
                return __('sales.' . $key);
            }
            return $key;
        };
        $status = $data->document['status'] ?? null;
        $showQr = isset($data->presentation['show_qr']) ? (bool) $data->presentation['show_qr'] : !empty($qrDataUri);
    @endphp

    {{-- Header: Company & Document Info --}}
    <table class="header-table">
        <tr>
            <td style="width: 55%;">
                @if(!empty($data->presentation['logo']))
                    <div><img src="{{ $data->presentation['logo'] }}" alt="Logo" class="company-logo"></div>
                @endif
                <h1>{{ $data->company['name'] }}</h1>
                @if(!empty($data->company['business_name']))
                    <p>{{ $data->company['business_name'] }}</p>
                @endif
                @if(!empty($data->company['address']))
                    <p>{{ $data->company['address'] }}</p>
                @endif
                <p>
                    @if(!empty($data->company['phone']))<span dir="ltr">{{ $data->company['phone'] }}</span>@endif
                    @if(!empty($data->company['email'])) <span dir="ltr">{{ $data->company['email'] }}</span>@endif
                </p>
                @if(!empty($data->company['tax_number']))
                    <p>{{ $transDoc('tax_number') }}: <span class="code" dir="ltr">{{ $data->company['tax_number'] }}</span></p>
                @endif
                @if(!empty($data->company['registration_number']))
                    <p>{{ $transDoc('registration_number') }}: <span class="code" dir="ltr">{{ $data->company['registration_number'] }}</span></p>
                @endif
            </td>
            <td style="width: 45%; text-align: {{ $data->locale === 'ar' ? 'left' : 'right' }};">
                <h2>{{ $transDoc($data->type) }} <span class="code" dir="ltr">{{ $data->document['number'] ?? '' }}</span></h2>
                @if(!empty($data->document['currency_code']))
                    <p>{{ $transDoc('currency') }}: <span dir="ltr">{{ $data->document['currency_code'] }}</span></p>
                @endif
                @foreach(['from_date', 'to_date'] as $dateField)
                    @if(!empty($data->document[$dateField]))<p>{{ $transDoc($dateField) }}: <span dir="ltr">{{ $data->document[$dateField] }}</span></p>@endif
                @endforeach
                @if($status)
                    @php
                        $statusClass = match($status) {
                            'draft' => 'status-draft',
                            'posted' => 'status-posted',
                            'void', 'voided' => 'status-void',
                            'reversed' => 'status-reversed',
                            default => 'status-default',
                        };
                        $statusKey = 'status_' . $status;
                        $statusText = match($status) {
                            'draft' => $transDoc('status_draft'),
                            'posted' => $transDoc('status_posted'),
                            'void', 'voided' => $transDoc('status_void'),
                            'reversed' => $transDoc('status_reversed'),
                            default => $status,
                        };
                    @endphp
                    <div><span class="status-badge {{ $statusClass }}">{{ $statusText }}</span></div>
                @endif
                @if(!empty($data->document['issue_date']))
                    <p>{{ $transDoc('issue_date') }}: <span class="date" dir="ltr">{{ $data->document['issue_date'] }}</span></p>
                @endif
                @if(!empty($data->document['due_date']))
                    <p>{{ $transDoc('due_date') }}: <span class="date" dir="ltr">{{ $data->document['due_date'] }}</span></p>
                @endif
                @if(!empty($data->document['valid_until']))
                    <p>{{ $transDoc('valid_until') }}: <span class="date" dir="ltr">{{ $data->document['valid_until'] }}</span></p>
                @endif
                @if(!empty($data->document['original_reference']))
                    <p>{{ $transDoc('original_reference') }}: <span class="code" dir="ltr">{{ $data->document['original_reference'] }}</span></p>
                @elseif(!empty($data->document['reference']))
                    <p>{{ $transDoc('reference') }}: <span class="code" dir="ltr">{{ $data->document['reference'] }}</span></p>
                @endif
                @if(!empty($data->document['payment_method']))
                    <p>{{ $transDoc('payment_method') }}: {{ $transDoc('method_' . $data->document['payment_method']) }}</p>
                @endif
                @if(!empty($data->document['payment_status']))
                    <p>{{ $transDoc('current_payment_status') }}: {{ $transDoc($data->document['payment_status']) }}</p>
                @endif
                @if(!empty($data->document['money_account']))
                    <p>{{ $transDoc('money_account') }}: {{ $data->document['money_account'] }}</p>
                @endif
                @if(!empty($data->document['base_currency_code']) && $data->document['base_currency_code'] !== ($data->document['currency_code'] ?? null))
                    <p>{{ $transDoc('base_currency') }}: <span dir="ltr">{{ $data->document['base_currency_code'] }}</span>
                    @if(!empty($data->document['exchange_rate']))
                        | {{ $transDoc('exchange_rate') }}: <span dir="ltr">{{ $data->document['exchange_rate'] }}</span>
                    @endif
                    </p>
                @endif
            </td>
        </tr>
    </table>

    {{-- Party / Customer Details --}}
    <div class="identity-box">
        <strong>{{ $data->customer['name'] }}</strong>
        @if(!empty($data->customer['business_name']))
            <p>{{ $data->customer['business_name'] }}</p>
        @endif
        @if(!empty($data->customer['address']))
            <p>{{ $data->customer['address'] }}</p>
        @endif
        <p>
            @if(!empty($data->customer['phone']))<span dir="ltr">{{ $data->customer['phone'] }}</span>@endif
            @if(!empty($data->customer['email'])) <span dir="ltr">{{ $data->customer['email'] }}</span>@endif
        </p>
        @if(!empty($data->customer['tax_number']))
            <p>{{ $transDoc('tax_number') }}: <span class="code" dir="ltr">{{ $data->customer['tax_number'] }}</span></p>
        @endif
        @if(!empty($data->customer['registration_number']))
            <p>{{ $transDoc('registration_number') }}: <span class="code" dir="ltr">{{ $data->customer['registration_number'] }}</span></p>
        @endif
    </div>

    {{-- Content Section: Statement vs Receipt vs Standard Documents --}}
    @if($data->statement !== null)
        {{-- Statement Mode --}}
        @foreach($data->statement['currencies'] as $currency => $group)
            <h3>{{ $currency }}</h3>
            @if(isset($group['opening_balance']))
                <p><strong>{{ $transDoc('opening_balance') }}:</strong> <span class="number" dir="ltr">{{ $group['opening_balance'] }} {{ $currency }}</span></p>
            @endif
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 18%;">{{ $transDoc('date') }}</th>
                        <th style="width: 26%;">{{ $transDoc('number') }}</th>
                        <th style="width: 18%; text-align: right;" class="number">{{ $transDoc('debit') }}</th>
                        <th style="width: 18%; text-align: right;" class="number">{{ $transDoc('credit') }}</th>
                        <th style="width: 20%; text-align: right;" class="number">{{ $transDoc('running_balance') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($group['entries'] as $entry)
                        <tr>
                            <td class="date" dir="ltr">{{ $entry['date'] }}</td>
                            <td class="code" dir="ltr">{{ $entry['number'] }}<br>{{ $transDoc($entry['type'] ?? '') }}</td>
                            <td class="number" dir="ltr">{{ $entry['debit'] }}</td>
                            <td class="number" dir="ltr">{{ $entry['credit'] }}</td>
                            <td class="number" dir="ltr">{{ $entry['balance'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p><strong>{{ $transDoc('closing_balance') }}:</strong> <span class="number" dir="ltr">{{ $group['closing_balance'] }} {{ $currency }}</span></p>
            @if(isset($group['aging']))
                <h4>{{ $transDoc('aging') }}</h4>
                <table class="data-table"><tbody>
                @foreach($group['aging'] as $bucket => $amount)
                    <tr><td>{{ $transDoc($bucket) }}</td><td class="number" dir="ltr">{{ $amount }} {{ $currency }}</td></tr>
                @endforeach
                </tbody></table>
            @endif
        @endforeach
    @elseif($data->type === 'customer_payment')
        {{-- Customer Receipt Mode --}}
        @php
            $publicLines = [];
            $laterLines = $data->applications;
            foreach ($data->lines as $line) {
                if (!empty($line['is_later_application']) || !empty($line['is_private'])) {
                    $laterLines[] = $line;
                } else {
                    $publicLines[] = $line;
                }
            }
            $hasDualCurrency = false;
            foreach ($publicLines as $line) {
                if (isset($line['payment_currency_amount']) || isset($line['settlement_base_value'])) {
                    $hasDualCurrency = true;
                    break;
                }
            }
        @endphp

        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: {{ $hasDualCurrency ? '35%' : '60%' }};">{{ $transDoc('invoice') }}</th>
                    @if($hasDualCurrency)
                        <th style="width: 25%; text-align: right;" class="number">{{ $transDoc('invoice_principal') }}</th>
                        <th style="width: 20%; text-align: right;" class="number">{{ $transDoc('payment_applied') }}</th>
                        <th style="width: 20%; text-align: right;" class="number">{{ $transDoc('settlement_base_value') }}</th>
                    @else
                        <th style="width: 40%; text-align: right;" class="number">{{ $transDoc('payment_applied') }}</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach($publicLines as $line)
                    @php
                        $principalCurr = $line['currency_code'] ?? $data->document['currency_code'];
                        $principalAmt = $line['total'] ?? $line['unit_price'] ?? '0';
                        $paymentCurr = $line['payment_currency_code'] ?? $data->document['currency_code'];
                        $paymentAmt = $line['payment_currency_amount'] ?? ($line['total'] ?? $line['unit_price'] ?? '0');
                        $baseCurr = $line['base_currency_code'] ?? ($data->document['base_currency_code'] ?? null);
                    @endphp
                    <tr>
                        <td>
                            <strong>{{ $line['item_description'] }}</strong>
                        </td>
                        @if($hasDualCurrency)
                            <td class="number">
                                {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($principalAmt, $principalCurr) }}
                                <span dir="ltr">{{ $principalCurr }}</span>
                            </td>
                            <td class="number">
                                {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($paymentAmt, $paymentCurr) }}
                                <span dir="ltr">{{ $paymentCurr }}</span>
                            </td>
                            <td class="number">
                                @if(isset($line['settlement_base_value']))
                                    {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($line['settlement_base_value'], $baseCurr) }}
                                    @if($baseCurr)<span dir="ltr">{{ $baseCurr }}</span>@endif
                                @else
                                    —
                                @endif
                            </td>
                        @else
                            <td class="number">
                                {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($paymentAmt, $paymentCurr) }}
                                <span dir="ltr">{{ $paymentCurr }}</span>
                            </td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>

        {{-- Totals for Receipt --}}
        <table class="totals-table">
            <tbody>
                <tr class="grand-total-row">
                    <td><strong>{{ $transDoc('payment_amount') }}</strong></td>
                    <td class="number">
                        <strong>
                            {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($data->document['grand_total'] ?? '0', $data->document['currency_code']) }}
                            {{ $data->document['currency_code'] }}
                        </strong>
                    </td>
                </tr>
                @if(!empty($data->document['amount_base']) && !empty($data->document['base_currency_code']) && $data->document['base_currency_code'] !== ($data->document['currency_code'] ?? null))
                    <tr class="base-amount-row">
                        <td>{{ $transDoc('amount_in_base_currency') }} ({{ $data->document['base_currency_code'] }})</td>
                        <td class="number">
                            {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($data->document['amount_base'], $data->document['base_currency_code']) }}
                            {{ $data->document['base_currency_code'] }}
                        </td>
                    </tr>
                @endif
            </tbody>
        </table>

        {{-- Distinct Later Applications Section (Separated & Labelled) --}}
        @if(!empty($laterLines))
            <div class="later-applications-section">
                <h4>{{ $transDoc('later_applications_private') }}</h4>
                <table class="data-table" style="margin: 4px 0;">
                    <thead>
                        <tr>
                            <th>{{ $transDoc('invoice') }}</th>
                            <th class="number">{{ $transDoc('invoice_principal') }}</th>
                            <th class="number">{{ $transDoc('payment_applied') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($laterLines as $lLine)
                            @php
                                $lPrincipalCurr = $lLine['currency_code'] ?? $data->document['currency_code'];
                                $lPrincipalAmt = $lLine['total'] ?? $lLine['unit_price'] ?? '0';
                                $lPaymentCurr = $lLine['payment_currency_code'] ?? $data->document['currency_code'];
                                $lPaymentAmt = $lLine['payment_currency_amount'] ?? ($lLine['total'] ?? $lLine['unit_price'] ?? '0');
                            @endphp
                            <tr>
                                <td>{{ $lLine['item_description'] }}
                                    @if(isset($lLine['application_date']))<br><span dir="ltr">{{ $lLine['application_date'] }}</span>@endif
                                    @if(isset($lLine['status']))<br>{{ $transDoc($lLine['status']) }}@endif
                                </td>
                                <td class="number">
                                    {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($lPrincipalAmt, $lPrincipalCurr) }}
                                    <span dir="ltr">{{ $lPrincipalCurr }}</span>
                                </td>
                                <td class="number">
                                    {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($lPaymentAmt, $lPaymentCurr) }}
                                    <span dir="ltr">{{ $lPaymentCurr }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @else
        {{-- Standard Documents: Quotation, Sales Invoice, Sales Return, Purchase, etc. --}}
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 48%;">{{ $transDoc('product') }}</th>
                    <th style="width: 16%; text-align: right;" class="number">{{ $transDoc('quantity') }}</th>
                    <th style="width: 18%; text-align: right;" class="number">{{ $transDoc('unit_price') }}</th>
                    <th style="width: 18%; text-align: right;" class="number">{{ $transDoc('total') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($data->lines as $line)
                    <tr>
                        <td>
                            @if(isset($line['image']))
                                <img src="{{ $line['image'] }}" alt="" width="50" height="50" style="vertical-align: middle; margin-inline-end: 6px;">
                            @endif
                            <strong>{{ $line['item_description'] }}</strong>
                            @if(!empty($line['sku']))
                                <br><span class="code" dir="ltr" style="font-size: 8.5pt; color: #64748b;">{{ $line['sku'] }}</span>
                            @endif
                        </td>
                        <td class="number" dir="ltr">
                            {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatQuantity($line['quantity']) }}
                            @if(!empty($line['unit_name']))
                                <span style="font-size: 8.5pt; color: #475467;">{{ $line['unit_name'] }}</span>
                            @endif
                        </td>
                        <td class="number">
                            {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($line['unit_price'], $data->document['currency_code']) }}
                        </td>
                        <td class="number">
                            {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($line['total'], $data->document['currency_code']) }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        {{-- Totals Table --}}
        <table class="totals-table">
            <tbody>
                @if(isset($data->document['subtotal']))
                    <tr>
                        <td style="width: 60%;">{{ $transDoc('subtotal') }}</td>
                        <td class="number" style="width: 40%;">
                            {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($data->document['subtotal'], $data->document['currency_code']) }}
                            {{ $data->document['currency_code'] }}
                        </td>
                    </tr>
                @endif
                @if(isset($data->document['discount_total']) && $data->document['discount_total'] !== '0' && $data->document['discount_total'] !== '0.000000')
                    <tr>
                        <td>{{ $transDoc('discount') }}</td>
                        <td class="number">
                            {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($data->document['discount_total'], $data->document['currency_code']) }}
                            {{ $data->document['currency_code'] }}
                        </td>
                    </tr>
                @endif
                @if(isset($data->document['tax_total']) && $data->document['tax_total'] !== '0' && $data->document['tax_total'] !== '0.000000')
                    <tr>
                        <td>{{ $transDoc('tax') }}</td>
                        <td class="number">
                            {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($data->document['tax_total'], $data->document['currency_code']) }}
                            {{ $data->document['currency_code'] }}
                        </td>
                    </tr>
                @endif
                @if(isset($data->document['grand_total']))
                    <tr class="grand-total-row">
                        <td><strong>{{ $transDoc('grand_total') }}</strong></td>
                        <td class="number">
                            <strong>
                                {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($data->document['grand_total'], $data->document['currency_code']) }}
                                {{ $data->document['currency_code'] }}
                            </strong>
                        </td>
                    </tr>
                @endif
                @if(!empty($data->document['amount_base']) && !empty($data->document['base_currency_code']) && $data->document['base_currency_code'] !== ($data->document['currency_code'] ?? null))
                    <tr class="base-amount-row">
                        <td>{{ $transDoc('amount_in_base_currency') }} ({{ $data->document['base_currency_code'] }})</td>
                        <td class="number">
                            {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($data->document['amount_base'], $data->document['base_currency_code']) }}
                            {{ $data->document['base_currency_code'] }}
                        </td>
                    </tr>
                @endif
            </tbody>
        </table>
    @endif

    {{-- Terms, Notes & Footer Container --}}
    @if(!empty($data->document['terms']) || !empty($data->document['notes']) || (!empty($qrDataUri) && $showQr) || !empty($data->presentation['footer']))
        <div class="terms-container">
            @if(!empty($data->document['terms']))
                <div class="terms-block">
                    <strong>{{ $transDoc('terms') }}</strong>
                    <p>{{ $data->document['terms'] }}</p>
                </div>
            @endif

            @if(!empty($data->document['notes']))
                <div class="notes-block">
                    <strong>{{ $transDoc('notes') }}</strong>
                    <p>{{ $data->document['notes'] }}</p>
                </div>
            @endif

            @if(!empty($qrDataUri) && $showQr)
                <table class="footer-qr-table">
                    <tr>
                        <td style="text-align: {{ $data->locale === 'ar' ? 'right' : 'left' }};">
                            <img src="{{ $qrDataUri }}" alt="QR" width="85" height="85">
                        </td>
                    </tr>
                </table>
            @endif

            @if(!empty($data->presentation['footer']))
                <div class="decorative-footer">
                    <p>{{ $data->presentation['footer'] }}</p>
                </div>
            @endif
        </div>
    @endif
</body>
</html>
