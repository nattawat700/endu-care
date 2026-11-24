@php
    $items = old('items', $items);
    $items = is_array($items) ? array_slice(array_values(array_filter($items, 'is_array')), 0, 40) : [];
    $items = $items ?: [['quantity' => 1]];
@endphp

<h2 class="text-lg font-bold">{{ ($forQuotation ?? false) ? 'รายการสินค้าและบริการ' : 'รายการค่าแรงและอะไหล่' }}</h2>
<div data-price-items class="space-y-3">
    @foreach ($items as $index => $item)
        @include('repairs.price-item-fields')
    @endforeach
</div>
<template data-price-template>
    @include('repairs.price-item-fields', ['index' => 0, 'item' => ['quantity' => 1]])
</template>
<div class="flex flex-wrap items-center justify-between gap-3">
    <button class="button button-secondary" type="button" data-add-price-item><x-workspace-icon name="plus" /> เพิ่มรายการ</button>
    <p>{{ $totalLabel ?? 'ยอดประเมินรวม' }} <output class="ml-2 text-xl font-bold tabular-nums" data-price-preview aria-live="polite">0.00</output> บาท</p>
</div>
