@php($value = fn (string $field) => is_scalar($item[$field] ?? null) ? $item[$field] : '')

<fieldset @class(['grid min-w-0 gap-3 rounded-lg border border-slate-200 p-3 sm:items-end',
    'sm:grid-cols-[8rem_minmax(0,1fr)_5rem_8rem_auto]' => ! ($forQuotation ?? false),
    'sm:grid-cols-2 xl:grid-cols-[6.5rem_minmax(0,1fr)_4.5rem_5rem_7rem_6rem_auto]' => $forQuotation ?? false,
]) data-price-item>
    <legend class="px-1 text-xs font-semibold text-slate-600" data-price-number>รายการ {{ $index + 1 }}</legend>
    <label class="block">
        <span class="field-label">ประเภท</span>
        <select class="field-input" name="items[{{ $index }}][type]" data-price-field="type" required>
            @foreach (\App\Models\RepairQuotation::ITEM_TYPES as $type => $label)
                    @continue($type === 'product' && ! ($forQuotation ?? false))
                <option value="{{ $type }}" @selected($value('type') === $type)>{{ $label }}</option>
            @endforeach
        </select>
    </label>
    <label class="block">
        <span class="field-label">รายละเอียด</span>
        <input class="field-input" name="items[{{ $index }}][description]" data-price-field="description" value="{{ $value('description') }}" maxlength="180" required>
    </label>
    <label class="block">
        <span class="field-label">จำนวน</span>
        <input class="field-input" name="items[{{ $index }}][quantity]" data-price-field="quantity" value="{{ $value('quantity') }}" type="number" min="1" max="1000" step="1" required>
    </label>
    @if ($forQuotation ?? false)
        <label class="block">
            <span class="field-label">หน่วย</span>
            <input class="field-input" name="items[{{ $index }}][unit]" data-price-field="unit" value="{{ $value('unit') }}" maxlength="30" placeholder="ชิ้น">
        </label>
    @endif
    <label class="block">
        <span class="field-label">ราคาต่อหน่วย (บาท)</span>
        <input class="field-input" name="items[{{ $index }}][unit_price]" data-price-field="unit_price" value="{{ $value('unit_price') }}" inputmode="decimal" pattern="[0-9]+([.][0-9]{1,2})?" maxlength="10" required>
    </label>
    @if ($forQuotation ?? false)
        <label class="block">
            <span class="field-label">ส่วนลด (บาท)</span>
            <input class="field-input" name="items[{{ $index }}][discount]" data-price-field="discount" value="{{ $value('discount') }}" inputmode="decimal" pattern="[0-9]+([.][0-9]{1,2})?" maxlength="10" placeholder="0.00">
        </label>
    @endif
    <button class="button button-secondary" type="button" data-remove-price-item>ลบ</button>
</fieldset>
