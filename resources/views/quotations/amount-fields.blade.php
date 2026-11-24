@php
    use App\Support\QuotationAmounts;
@endphp

<fieldset class="grid gap-3 border-t border-slate-200 pt-4 sm:grid-cols-2 xl:grid-cols-4" data-quote-amounts>
    <legend class="pr-3 font-bold">ส่วนลด ค่าใช้จ่าย และภาษี</legend>
    @foreach (QuotationAmounts::ADJUSTMENTS as $key => $label)
        <label class="block">
            <span class="field-label">{{ $label }} (บาท)</span>
            <input class="field-input" name="{{ $key }}" value="{{ $value($key) }}" inputmode="decimal" pattern="[0-9]+([.][0-9]{1,2})?" maxlength="10" placeholder="0.00">
        </label>
    @endforeach
    <label class="block sm:col-span-2">
        <span class="field-label">รายละเอียดค่าใช้จ่ายอื่น</span>
        <input class="field-input" name="other_label" value="{{ $value('other_label') }}" maxlength="100" placeholder="เช่น ค่าบรรจุสินค้า">
    </label>
    <label class="block">
        <span class="field-label">วิธีคิด VAT</span>
        <select class="field-input" name="vat_mode">
            @foreach (QuotationAmounts::VAT_MODES as $key => $label)
                <option value="{{ $key }}" @selected($value('vat_mode') === $key)>{{ $label }}</option>
            @endforeach
        </select>
    </label>
    <label class="block">
        <span class="field-label">อัตรา VAT (%)</span>
        <input class="field-input" name="vat_rate" value="{{ $value('vat_rate') }}" inputmode="decimal" pattern="[0-9]+([.][0-9]{1,2})?" maxlength="6">
    </label>
    <p class="text-sm text-slate-600 sm:col-span-2 xl:col-span-4">VAT ใช้ยอดหลังหักส่วนลดและรวมค่าขนส่งกับค่าใช้จ่ายอื่น ภาษีหัก ณ ที่จ่ายให้กรอกเป็นจำนวนเงิน</p>
    <dl class="grid gap-3 rounded-lg bg-slate-50 p-3 sm:col-span-2 sm:grid-cols-3 xl:col-span-4">
        <div><dt class="detail-label">ก่อน VAT</dt><dd class="tabular-nums"><output data-quote-preview="net">0.00</output> บาท</dd></div>
        <div><dt class="detail-label">VAT</dt><dd class="tabular-nums"><output data-quote-preview="vat">0.00</output> บาท</dd></div>
        <div><dt class="detail-label">ยอดชำระหลังหักภาษี</dt><dd class="font-bold tabular-nums"><output data-quote-preview="payable">0.00</output> บาท</dd></div>
    </dl>
    <p class="text-sm text-red-800 sm:col-span-2 xl:col-span-4" data-quote-price-error hidden>ตรวจส่วนลด อัตราภาษี และยอดหัก ณ ที่จ่ายให้ตรงกับมูลค่ารายการ</p>
</fieldset>
