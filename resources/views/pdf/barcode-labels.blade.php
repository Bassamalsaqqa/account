@php
    $locale = $prepared['locale'] ?? 'ar';
    $isRtl = $locale === 'ar';
    $dir = $isRtl ? 'rtl' : 'ltr';
    $preset = $prepared['preset'] ?? 'a4-3x8';
    $is2x7 = $preset === 'a4-2x7';
    $columns = $is2x7 ? 2 : 3;
    $rows = $is2x7 ? 7 : 8;
    $perPage = $columns * $rows;
    $cellWidth = $is2x7 ? '99mm' : '66mm';
    $cellHeight = $is2x7 ? '38mm' : '33mm';
    $labels = $prepared['labels'] ?? [];
    $pages = array_chunk($labels, $perPage);
    if (empty($pages)) {
        $pages = [[]];
    }
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $dir }}">
<head>
    <meta charset="UTF-8">
    <title>Barcode Labels ({{ strtoupper($preset) }})</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        body {
            font-family: 'DejaVu Sans', 'Arial', sans-serif;
            background: {{ !empty($forPdf) ? '#ffffff' : '#f3f4f6' }};
            direction: {{ $dir }};
            color: #111827;
        }
        @page {
            size: A4 portrait;
            margin: 0;
        }
        @media print {
            .no-print {
                display: none !important;
            }
            body {
                background: #ffffff !important;
            }
            .label-page {
                page-break-after: always;
                box-shadow: none !important;
                margin: 0 auto !important;
            }
        }
        .print-toolbar {
            position: sticky;
            top: 0;
            z-index: 50;
            background: #ffffff;
            border-bottom: 1px solid #e5e7eb;
            padding: 12px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        .print-btn {
            background-color: #2563eb;
            color: #ffffff;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            min-height: 44px;
        }
        .print-btn:hover {
            background-color: #1d4ed8;
        }
        .page-wrapper {
            padding: {{ !empty($forPdf) ? '0' : '20px 0' }};
        }
        .label-page {
            width: 198mm;
            margin: {{ !empty($forPdf) ? '0 auto' : '0 auto 20px auto' }};
            padding: 4mm 0;
            background: #ffffff;
            {{ !empty($forPdf) ? '' : 'box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);' }}
            page-break-after: avoid;
        }
        .label-page:last-child {
            page-break-after: avoid;
        }
        .labels-table {
            width: 198mm;
            border-collapse: collapse;
            table-layout: fixed;
        }
        .label-cell {
            width: {{ $cellWidth }};
            height: {{ $cellHeight }};
            max-width: {{ $cellWidth }};
            max-height: {{ $cellHeight }};
            border: 1px dashed #cbd5e1;
            border-radius: 3px;
            padding: 2mm;
            text-align: center;
            vertical-align: middle;
            overflow: hidden;
            background: #ffffff;
        }
        .label-cell-empty {
            border: 1px dashed #f1f5f9;
            background: transparent;
        }
        .product-name {
            font-size: 8.5pt;
            font-weight: 700;
            line-height: 1.2;
            max-height: 2.4em;
            overflow: hidden;
            word-wrap: break-word;
            color: #0f172a;
            margin-bottom: 1mm;
        }
        .product-caption {
            font-size: 7pt;
            font-weight: 500;
            color: #475569;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-bottom: 1.5mm;
        }
        .barcode-graphic {
            width: 100%;
            text-align: center;
            margin: 0 auto;
        }
        .barcode-code {
            direction: ltr !important;
            unicode-bidi: isolate;
            font-family: 'DejaVu Sans Mono', monospace, 'Courier New', Courier;
            font-size: 7.5pt;
            font-weight: 600;
            color: #000000;
            letter-spacing: 1px;
            margin-top: 1mm;
        }
    </style>
</head>
<body>

@if(!empty($printControls) && empty($forPdf))
    <div class="print-toolbar no-print">
        <div>
            <strong>{{ $locale === 'ar' ? 'معاينة ملصقات الباركود' : 'Barcode Labels Preview' }}</strong>
            <span style="color: #6b7280; margin: 0 8px;">&bull;</span>
            <span style="color: #4b5563;">{{ count($labels) }} {{ $locale === 'ar' ? 'ملصق' : 'labels' }} ({{ count($pages) }} {{ $locale === 'ar' ? 'صفحة' : 'pages' }})</span>
        </div>
        <button type="button" class="print-btn" onclick="window.print();">
            {{ $locale === 'ar' ? 'طباعة الملصقات' : 'Print Labels' }}
        </button>
    </div>
@endif

<div class="page-wrapper">
@foreach($pages as $pageIndex => $pageLabels)
    @if($pageIndex > 0)
        @if(!empty($forPdf)) <pagebreak /> @else <div style="page-break-before: always;"></div> @endif
    @endif
    <div class="label-page">
        <table class="labels-table">
            @php
                $rowsData = array_chunk($pageLabels, $columns);
            @endphp
            @for($r = 0; $r < $rows; $r++)
                <tr>
                    @for($c = 0; $c < $columns; $c++)
                        @php
                            $label = $rowsData[$r][$c] ?? null;
                        @endphp
                        @if($label)
                            <td class="label-cell">
                                <div class="product-name">{{ $label['name'] }}</div>
                                <div class="product-caption">
                                    <span>{{ $label['sku'] }}</span>
                                    @if(!empty($label['unit']))
                                        <span>&bull; {{ $label['unit'] }}</span>
                                    @endif
                                </div>
                                <div class="barcode-graphic">
                                    @if(!empty($forPdf))
                                        <barcode code="{{ $label['encoded_code'] ?? $label['code'] }}" type="{{ $label['mpdf_type'] }}" size="0.7" height="0.55" />
                                    @else
                                        <div style="height: {{ $is2x7 ? '34px' : '28px' }}; max-width: 95%; margin: 0 auto;">
                                            {!! $label['svg'] !!}
                                        </div>
                                    @endif
                                </div>
                                <div class="barcode-code">{{ $label['code'] }}</div>
                            </td>
                        @else
                            <td class="label-cell label-cell-empty"></td>
                        @endif
                    @endfor
                </tr>
            @endfor
        </table>
    </div>
@endforeach
</div>

</body>
</html>
