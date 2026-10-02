<!DOCTYPE html>
@php
    $locale = $result['status'] === 'success' ? ($result['data']['document_locale'] ?? 'ar') : app()->getLocale();
    $isRtl = $locale === 'ar';
@endphp
<html lang="{{ $locale }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('sales.public_share') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css'])
</head>
<body class="bg-slate-100 min-h-screen text-slate-800 font-sans p-4 sm:p-6 lg:p-10 flex flex-col justify-between">
    <div class="max-w-4xl mx-auto w-full space-y-6">
        @if ($result['status'] === 'password_required')
            <div class="bg-white rounded-xl shadow-md border border-slate-200 p-8 max-w-md mx-auto text-center space-y-4">
                <div class="w-12 h-12 bg-primary/10 text-primary rounded-full flex items-center justify-center mx-auto text-xl">
                    🔒
                </div>
                <h2 class="text-lg font-bold text-slate-900">
                    {{ __('sales.password_protected') }}
                </h2>
                <p class="text-xs text-slate-500">
                    {{ __('sales.enter_password') }}
                </p>

                @if (! empty($result['error']))
                    <div class="p-2.5 rounded-lg bg-red-50 text-red-600 text-xs font-bold">
                        {{ __('sales.incorrect_password') }}
                    </div>
                @endif

                <form method="POST" action="{{ url('/share/' . $token) }}" class="space-y-3">
                    @csrf
                    <input type="password"
                           name="password"
                           required
                           autofocus
                           placeholder="••••••••"
                           class="w-full h-10 px-3 rounded-lg border border-slate-300 text-xs text-center tracking-widest focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" />
                    <button type="submit"
                            class="w-full h-10 rounded-lg bg-primary text-white text-xs font-bold hover:bg-primary-hover transition-colors">
                        {{ __('sales.submit_password') }}
                    </button>
                </form>
            </div>

        @elseif ($result['status'] === 'expired')
            <div class="bg-white rounded-xl shadow-md border border-slate-200 p-8 max-w-md mx-auto text-center space-y-3">
                <div class="w-12 h-12 bg-amber-50 text-amber-600 rounded-full flex items-center justify-center mx-auto text-xl">
                    ⏱️
                </div>
                <h2 class="text-base font-bold text-slate-900">{{ __('sales.link_expired') }}</h2>
            </div>

        @elseif ($result['status'] === 'revoked')
            <div class="bg-white rounded-xl shadow-md border border-slate-200 p-8 max-w-md mx-auto text-center space-y-3">
                <div class="w-12 h-12 bg-red-50 text-red-600 rounded-full flex items-center justify-center mx-auto text-xl">
                    🚫
                </div>
                <h2 class="text-base font-bold text-slate-900">{{ __('sales.link_revoked') }}</h2>
            </div>

        @elseif ($result['status'] === 'not_found' || $result['status'] === 'invalid')
            <div class="bg-white rounded-xl shadow-md border border-slate-200 p-8 max-w-md mx-auto text-center space-y-3">
                <div class="w-12 h-12 bg-slate-100 text-slate-500 rounded-full flex items-center justify-center mx-auto text-xl">
                    🔍
                </div>
                <h2 class="text-base font-bold text-slate-900">{{ __('sales.link_invalid_or_revoked') }}</h2>
            </div>

        @elseif ($result['status'] === 'success')
            @php
                $data = $result['data'] ?? [];
                $docType = $data['type'] ?? ($result['type'] ?? 'document');
                $company = $data['company'] ?? [];
                $customer = $data['customer'] ?? [];
                $doc = $data['document'] ?? $data;
                $lines = $data['lines'] ?? [];
            @endphp
            <div class="bg-white rounded-2xl shadow-lg border border-slate-200 p-6 sm:p-10 space-y-8">
                <!-- Top Brand & QR Code -->
                <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-6 pb-6 border-b border-slate-200">
                    <div class="space-y-1">
                        <h1 class="text-2xl font-extrabold text-slate-900">
                            {{ $company['name'] ?? ($company['name_ar'] ?? ($company['name_en'] ?? '')) }}
                        </h1>
                        <p class="text-xs text-slate-500">
                            {{ $company['phone'] ?? '' }} | {{ $company['email'] ?? '' }}
                        </p>
                    </div>

                    <div class="flex items-center gap-4">
                        @if (! empty($result['qr_code_data_uri']))
                            <img src="{{ $result['qr_code_data_uri'] }}" alt="QR Code" class="w-20 h-20 border border-slate-200 rounded-lg p-1 bg-white" />
                        @endif
                        <div class="text-end">
                            <span class="inline-block px-3 py-1 rounded-full text-xs font-extrabold bg-blue-50 text-blue-700">
                                {{ __('sales.'.$docType) }}
                            </span>
                            <div class="font-mono font-bold text-base text-slate-900 mt-1" dir="ltr">
                                {{ $doc['number'] ?? ($doc['document_number'] ?? '') }}
                            </div>
                            <div class="text-xs text-slate-500" dir="ltr">
                                {{ $doc['issue_date'] ?? ($doc['date'] ?? '') }}
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Customer Details -->
                <div class="bg-slate-50 rounded-xl p-4 border border-slate-200/80 text-xs">
                    <div class="font-bold text-slate-900 text-sm mb-1">
                        {{ $customer['name'] ?? ($customer['name_ar'] ?? ($customer['name_en'] ?? __('sales.customer'))) }}
                    </div>
                    @if (! empty($customer['business_name']))
                        <div class="text-slate-600">{{ $customer['business_name'] }}</div>
                    @endif
                    <div class="text-slate-500 mt-1" dir="ltr">
                        {{ $customer['phone'] ?? '' }} {{ !empty($customer['email']) ? '| ' . $customer['email'] : '' }}
                    </div>
                </div>

                @if ($docType === 'customer_statement')
                    @foreach ($data['statement']['currencies'] as $currency => $group)
                        <h2 class="font-bold">{{ $currency }}</h2>
                        <div class="overflow-x-auto"><table class="w-full text-xs text-start"><thead><tr><th>{{ __('sales.date') }}</th><th>{{ __('sales.number') }}</th><th>{{ __('sales.debit') }}</th><th>{{ __('sales.credit') }}</th><th>{{ __('sales.running_balance') }}</th></tr></thead><tbody>
                        @foreach ($group['entries'] as $entry)<tr class="border-b border-slate-200"><td class="p-2">{{ $entry['date'] }}</td><td class="p-2">{{ $entry['number'] }}</td><td class="p-2" dir="ltr">{{ $entry['debit'] }}</td><td class="p-2" dir="ltr">{{ $entry['credit'] }}</td><td class="p-2" dir="ltr">{{ $entry['balance'] }}</td></tr>@endforeach
                        </tbody></table></div>
                        <p>{{ __('sales.closing_balance') }}: <span dir="ltr">{{ $group['closing_balance'] }} {{ $currency }}</span></p>
                    @endforeach
                @else
                <!-- Lines Table -->
                <div class="overflow-x-auto">
                    <table class="w-full text-start text-xs border-collapse">
                        <thead>
                            <tr class="border-b border-slate-200 bg-slate-50 text-slate-500 font-bold text-[11px] uppercase">
                                <th class="py-2.5 px-3 text-start">#</th>
                                <th class="py-2.5 px-3 text-start">{{ __('sales.product') }}</th>
                                <th class="py-2.5 px-3 text-start">{{ __('sales.quantity') }}</th>
                                <th class="py-2.5 px-3 text-start">{{ __('sales.unit_price') }}</th>
                                <th class="py-2.5 px-3 text-start">{{ __('sales.discount') }}</th>
                                <th class="py-2.5 px-3 text-start">{{ __('sales.tax') }}</th>
                                <th class="py-2.5 px-3 text-end">{{ __('sales.total') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($lines as $idx => $line)
                                <tr>
                                    <td class="py-2.5 px-3 text-slate-400">{{ $idx + 1 }}</td>
                                    <td class="py-2.5 px-3 font-bold text-slate-900">
                                        {{ $line['item_description'] }}
                                    </td>
                                    <td class="py-2.5 px-3 font-mono" dir="ltr">
                                        {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatQuantity($line['quantity']) }}
                                    </td>
                                    <td class="py-2.5 px-3 font-mono" dir="ltr">
                                        {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($line['unit_price'], $doc['currency_code'] ?? null) }}
                                    </td>
                                    <td class="py-2.5 px-3 font-mono text-red-600" dir="ltr">
                                        @php
                                            $disc = $line['discount'] ?? ($line['discount_amount'] ?? '0');
                                        @endphp
                                        {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::isPositive($disc) ? \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($disc, $doc['currency_code'] ?? null) : '—' }}
                                    </td>
                                    <td class="py-2.5 px-3 font-mono" dir="ltr">
                                        @php
                                            $tax = $line['tax'] ?? ($line['tax_amount'] ?? '0');
                                        @endphp
                                        {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::isPositive($tax) ? \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($tax, $doc['currency_code'] ?? null) : '—' }}
                                    </td>
                                    <td class="py-2.5 px-3 font-mono font-bold text-end" dir="ltr">
                                        {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($line['total'] ?? ($line['line_total'] ?? '0'), $doc['currency_code'] ?? null) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <!-- Totals Section -->
                <div class="flex justify-end pt-4 border-t border-slate-200">
                    <div class="w-full sm:w-64 space-y-2 text-xs">
                        <div class="flex justify-between py-1 border-b border-slate-100">
                            <span class="text-slate-500">{{ __('sales.subtotal') }}:</span>
                            <span class="font-mono font-bold" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($doc['subtotal'], $doc['currency_code']) }} {{ $doc['currency_code'] }}</span>
                        </div>
                        <div class="flex justify-between py-1 border-b border-slate-100">
                            <span class="text-slate-500">{{ __('sales.discount') }}:</span>
                            <span class="font-mono text-red-600 font-bold" dir="ltr">-{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($doc['discount_total'], $doc['currency_code']) }} {{ $doc['currency_code'] }}</span>
                        </div>
                        <div class="flex justify-between py-1 border-b border-slate-100">
                            <span class="text-slate-500">{{ __('sales.tax') }}:</span>
                            <span class="font-mono font-bold" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($doc['tax_total'], $doc['currency_code']) }} {{ $doc['currency_code'] }}</span>
                        </div>
                        <div class="flex justify-between py-2 border-t border-slate-200 text-base font-extrabold text-slate-900">
                            <span>{{ __('sales.grand_total') }}:</span>
                            <span class="font-mono text-blue-600" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($doc['grand_total'], $doc['currency_code']) }} {{ $doc['currency_code'] }}</span>
                        </div>
                    </div>
                </div>

                @endif

                @if (! empty($doc['terms']) || ! empty($doc['notes']))
                    <div class="p-4 bg-slate-50 rounded-xl border border-slate-200 text-xs space-y-2">
                        @if (! empty($doc['terms']))
                            <div>
                                <span class="font-bold text-slate-900 block">{{ __('sales.terms') }}:</span>
                                <p class="text-slate-600 whitespace-pre-line">{{ $doc['terms'] }}</p>
                            </div>
                        @endif
                        @if (! empty($doc['notes']))
                            <div>
                                <span class="font-bold text-slate-900 block">{{ __('sales.notes') }}:</span>
                                <p class="text-slate-600 whitespace-pre-line">{{ $doc['notes'] }}</p>
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        @endif
    </div>

    <!-- Public Footer -->
    <div class="text-center text-xs text-slate-400 mt-8">
        {{ __('app.app_name') }} &copy; {{ date('Y') }}
    </div>
</body>
</html>
