@php
    $locale = $prepared['locale'];
    $isRtl = $locale === 'ar';
    $columns = $prepared['preset'] === 'a4-2x7' ? 2 : 3;
    $rows = $columns === 2 ? 7 : 8;
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head><meta charset="UTF-8"><title>{{ $locale === 'ar' ? 'ملصقات الباركود' : 'Barcode labels' }}</title>
<style>
    body { font-family: dejavusans; font-size: 8pt; color: #111111; }
    table { width: 198mm; border-collapse: collapse; margin: 4mm 6mm; }
    td { width: {{ $columns === 2 ? '99mm' : '66mm' }}; height: {{ $columns === 2 ? '38mm' : '33mm' }}; border: 0.2mm dashed #bbbbbb; text-align: center; vertical-align: middle; padding: 1mm; }
    .name { font-weight: bold; font-size: 8pt; }
    .caption { font-size: 7pt; color: #333333; }
    .code { font-family: dejavusansmono; font-size: 7pt; direction: ltr; }
</style></head>
<body>
@foreach(array_chunk($prepared['labels'], $columns * $rows) as $pageIndex => $labels)
    @if($pageIndex > 0)<pagebreak />@endif
    <table autosize="1">
    @for($row = 0; $row < $rows; $row++)
        <tr>
        @for($column = 0; $column < $columns; $column++)
            @php($label = $labels[$row * $columns + $column] ?? null)
            <td>
            @if($label)
                <span class="name">{{ $label['name'] }}</span><br>
                <span class="caption">{{ $label['sku'] }} · {{ $label['unit'] }}</span><br><br>
                <barcode code="{{ $label['encoded_code'] ?? $label['code'] }}" type="{{ $label['mpdf_type'] }}" size="0.7" height="0.55" /><br>
                <span class="code">{{ $label['code'] }}</span>
            @endif
            </td>
        @endfor
        </tr>
    @endfor
    </table>
@endforeach
</body></html>
