<table class="quotation-table">
    <caption class="sr-only">{{ $itemsCaption ?? 'รายการสินค้าและบริการ' }}</caption>
    <thead><tr>
        <th scope="col">รายการสินค้า / บริการ</th><th scope="col" class="text-right">จำนวน</th>
        <th scope="col" class="text-right">ราคา/หน่วย</th><th scope="col" class="text-right">ส่วนลด</th>
        <th scope="col" class="text-right">รวม (บาท)</th>
    </tr></thead>
    <tbody>
        @foreach ($items as $item)
            <tr>
                <td><strong>{{ $item['description'] }}</strong><small class="block text-slate-600">{{ $item['type_label'] ?? \App\Models\RepairQuotation::ITEM_TYPES[$item['type']] }}</small></td>
                <td class="text-right tabular-nums">{{ number_format($item['quantity']) }} @if (! empty($item['unit']))<small class="block text-slate-600">{{ $item['unit'] }}</small>@endif</td>
                <td class="text-right tabular-nums">{{ \App\Models\RepairQuotation::formatBaht($item['unit_satang']) }}</td>
                <td class="text-right tabular-nums">{{ ! empty($item['discount_satang']) ? \App\Models\RepairQuotation::formatBaht($item['discount_satang']) : '—' }}</td>
                <td class="text-right tabular-nums">{{ \App\Models\RepairQuotation::formatBaht($item['total_satang']) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
