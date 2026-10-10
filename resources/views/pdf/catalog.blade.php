@php
    $locale = $data['locale'] ?? 'ar';
    $isRtl = $locale === 'ar';
    $dir = $isRtl ? 'rtl' : 'ltr';
    $isPriced = array_key_exists('currency_code', $data);
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <title>{{ $data['title'] ?? 'Product Catalog' }}</title>
    <style>
        body {
            font-family: 'dejavusans', sans-serif;
            font-size: 9pt;
            color: #1e293b;
            line-height: 1.4;
            margin: 0;
            padding: 0;
            direction: {{ $dir }};
        }
        .header-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 14px;
            padding-bottom: 10px;
            border-bottom: 2px solid #0284c7;
        }
        .company-name {
            font-size: 13pt;
            font-weight: bold;
            color: #0f172a;
        }
        .catalog-title {
            font-size: 11pt;
            font-weight: bold;
            color: #0284c7;
            margin-top: 3px;
        }
        .catalog-desc {
            font-size: 8pt;
            color: #475569;
            margin-top: 3px;
        }
        .contact-info {
            font-size: 7.5pt;
            color: #64748b;
            margin-top: 3px;
        }
        .revision-badge {
            font-size: 8pt;
            color: #64748b;
        }
        .tax-notice {
            margin-top: 5px;
            padding: 3px 6px;
            background-color: #f0f9ff;
            border: 1px solid #bae6fd;
            color: #0369a1;
            font-size: 7.5pt;
            border-radius: 3px;
        }
        .item-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
        }
        .item-table th {
            background-color: #f8fafc;
            border: 1px solid #cbd5e1;
            padding: 6px 8px;
            font-size: 8pt;
            font-weight: bold;
            color: #334155;
            text-align: {{ $isRtl ? 'right' : 'left' }};
        }
        .item-table td {
            border: 1px solid #e2e8f0;
            padding: 6px 8px;
            font-size: 8pt;
            vertical-align: top;
            text-align: {{ $isRtl ? 'right' : 'left' }};
        }
        .item-table tr:nth-child(even) td {
            background-color: #fbfcfe;
        }
        .thumb-cell {
            width: 48px;
            text-align: center;
        }
        .thumb-img {
            max-width: 42px;
            max-height: 42px;
            display: block;
            margin: 0 auto;
            border-radius: 2px;
        }
        .no-img {
            width: 42px;
            height: 42px;
            line-height: 42px;
            background-color: #f1f5f9;
            color: #94a3b8;
            font-size: 7pt;
            text-align: center;
            border: 1px dashed #cbd5e1;
            border-radius: 2px;
            margin: 0 auto;
        }
        .item-name {
            font-weight: bold;
            color: #0f172a;
        }
        .item-desc {
            font-size: 7pt;
            color: #64748b;
            margin-top: 2px;
        }
        .sku-tag {
            font-size: 7pt;
            color: #475569;
            font-family: monospace;
        }
        .unit-badge {
            display: inline-block;
            padding: 2px 4px;
            background-color: #e2e8f0;
            color: #334155;
            font-size: 7pt;
            border-radius: 2px;
        }
        .price-val {
            font-weight: bold;
            color: #0f172a;
            white-space: nowrap;
        }
        .price-on-req {
            font-style: italic;
            color: #64748b;
            font-size: 7pt;
        }
    </style>
</head>
<body>
    <table class="header-table">
        <tr>
            <td style="vertical-align: top;">
                <div class="company-name">{{ $data['company']['name'] ?? '' }}</div>
                <div class="catalog-title">{{ $data['title'] ?? '' }}</div>
                @if(!empty($data['description']))
                    <div class="catalog-desc">{{ $data['description'] }}</div>
                @endif
                @if(!empty($data['company']['phone']) || !empty($data['company']['email']))
                    <div class="contact-info">
                        @if(!empty($data['company']['phone']))
                            {{ $isRtl ? 'الهاتف: ' : 'Phone: ' }}{{ $data['company']['phone'] }}
                        @endif
                        @if(!empty($data['company']['phone']) && !empty($data['company']['email']))
                            &nbsp;|&nbsp;
                        @endif
                        @if(!empty($data['company']['email']))
                            {{ $isRtl ? 'البريد: ' : 'Email: ' }}{{ $data['company']['email'] }}
                        @endif
                    </div>
                @endif
            </td>
            <td style="vertical-align: top; text-align: {{ $isRtl ? 'left' : 'right' }}; width: 140px;">
                <div class="revision-badge">
                    {{ $isRtl ? 'الإصدار: ' : 'Revision: ' }}{{ $data['revision'] ?? 1 }}
                </div>
                @if($isPriced && !empty($data['tax_basis']))
                    <div class="tax-notice">
                        {{ $data['tax_basis'] }}
                    </div>
                @endif
            </td>
        </tr>
    </table>

    <table class="item-table">
        <thead>
            <tr>
                <th style="width: 26px; text-align: center;">#</th>
                <th class="thumb-cell">{{ $isRtl ? 'الصورة' : 'Photo' }}</th>
                <th>{{ $isRtl ? 'المنتج والمواصفات' : 'Product & Description' }}</th>
                <th style="width: 65px; text-align: center;">{{ $isRtl ? 'الوحدة' : 'Unit' }}</th>
                @if($isPriced)
                    <th style="width: 85px; text-align: {{ $isRtl ? 'left' : 'right' }};">{{ $isRtl ? 'السعر' : 'Price' }} ({{ $data['currency_code'] }})</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @forelse($data['items'] ?? [] as $index => $item)
                <tr>
                    <td style="text-align: center; color: #94a3b8; font-size: 7.5pt;">{{ $index + 1 }}</td>
                    <td class="thumb-cell">
                        @if(!empty($item['image']) && str_starts_with($item['image'], 'data:image/'))
                            <img src="{{ $item['image'] }}" class="thumb-img" alt="" />
                        @else
                            <div class="no-img">-</div>
                        @endif
                    </td>
                    <td>
                        <div class="item-name">{{ $item['name'] ?? '' }}</div>
                        @if(!empty($item['sku']))
                            <div class="sku-tag">SKU: {{ $item['sku'] }}</div>
                        @endif
                        @if(!empty($item['description']))
                            <div class="item-desc">{{ $item['description'] }}</div>
                        @endif
                    </td>
                    <td style="text-align: center;">
                        <span class="unit-badge">{{ $item['unit'] ?? '' }}</span>
                    </td>
                    @if($isPriced)
                        <td style="text-align: {{ $isRtl ? 'left' : 'right' }};">
                            @if(array_key_exists('price', $item))
                                @if($item['price'] !== null)
                                    <span class="price-val">{{ $item['price'] }}</span>
                                @else
                                    <span class="price-on-req">{{ $isRtl ? 'عند الطلب' : 'On request' }}</span>
                                @endif
                            @endif
                        </td>
                    @endif
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $isPriced ? 5 : 4 }}" style="text-align: center; padding: 18px; color: #94a3b8;">
                        {{ $isRtl ? 'لا توجد منتجات مدرجة' : 'No products listed' }}
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
