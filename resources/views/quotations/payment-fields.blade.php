<fieldset class="grid gap-3 border-t border-slate-200 pt-4 sm:grid-cols-2">
    <legend class="pr-3 font-bold">ส่งมอบและชำระเงิน</legend>
    <label class="block">
        <span class="field-label">ช่องทางชำระเงิน</span>
        <input class="field-input" name="payment_channels" value="{{ $value('payment_channels') }}" maxlength="150" placeholder="เช่น โอนผ่านธนาคาร หรือเงินสด">
    </label>
    <label class="block">
        <span class="field-label">เครดิตการชำระเงิน (วัน)</span>
        <input class="field-input" type="number" min="0" max="365" step="1" name="credit_days" value="{{ $value('credit_days') }}" placeholder="0 สำหรับชำระทันที">
    </label>
    @foreach (['payment_details' => ['รายละเอียดการชำระเงิน', 1000], 'delivery_terms' => ['กำหนดและเงื่อนไขส่งมอบ', 1500], 'notes' => ['หมายเหตุถึงลูกค้า', 1500]] as $key => [$label, $max])
        <label class="block {{ $key === 'notes' ? 'sm:col-span-2' : '' }}">
            <span class="field-label">{{ $label }}</span>
            <textarea class="field-input" name="{{ $key }}" maxlength="{{ $max }}" rows="2">{{ $value($key) }}</textarea>
        </label>
    @endforeach
</fieldset>
