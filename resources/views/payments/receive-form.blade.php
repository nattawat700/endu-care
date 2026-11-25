<p class="mt-2 text-sm text-slate-600">{{ $activeShift->counter->name }} · {{ $activeShift->point->name }}</p>
<form method="POST" action="{{ route('repairs.payments.store', [$branch, $repair]) }}" enctype="multipart/form-data" class="mt-4 grid gap-4 sm:grid-cols-2" data-payment-form>
    @csrf
    <input type="hidden" name="submission_id" value="{{ old('submission_id', $submissionId) }}">
    <input type="hidden" name="billing_version" value="{{ $billing['version'] }}">
    <x-repair-input name="lines[cash]" label="ยอดตัดชำระเงินสด (บาท)" :default="old('lines.cash')" :limit="10" inputmode="decimal" />
    <x-repair-input name="tendered" label="เงินสดที่ลูกค้ายื่น (บาท)" placeholder="เว้นว่างเมื่อจ่ายพอดี" :limit="10" inputmode="decimal" />
    <x-repair-input name="lines[transfer]" label="ยอดโอน (บาท)" :default="old('lines.transfer')" :limit="10" inputmode="decimal" />
    @if ($activeShift->point->qr_filename)
        <div>
            <x-repair-input name="lines[qr]" label="ยอด QR หน้าร้าน (บาท)" :default="old('lines.qr')" :limit="10" inputmode="decimal" />
            <a class="endu-text-action text-sm" href="{{ route('cash-shifts.qr', [$branch, $activeShift->point]) }}" target="_blank" rel="noopener noreferrer">แสดง QR ของจุดรับเงิน</a>
        </div>
    @endif
    @if ($activeShift->point->other_channel_name)
        <x-repair-input name="lines[other]" :label="$activeShift->point->other_channel_name.' (บาท)'" :default="old('lines.other')" :limit="10" inputmode="decimal" />
    @endif
    @foreach (['transfer' => ['เลขอ้างอิงโอน', 'transfer_reference', 'สลิปโอน', 'slip'], 'qr' => ['เลขอ้างอิง QR', 'qr_reference', 'สลิป QR', 'qr_slip']] as $channel => [$referenceLabel, $referenceName, $fileLabel, $fileName])
        @continue($channel === 'qr' && ! $activeShift->point->qr_filename)
        <fieldset class="grid gap-3 rounded-md border border-slate-200 p-3 sm:col-span-2 sm:grid-cols-2" data-payment-proof="{{ $channel }}">
            <x-repair-input :name="$referenceName" :label="$referenceLabel" :limit="80" />
            <label class="block"><span class="field-label">{{ $fileLabel }}</span><input type="file" name="{{ $fileName }}" class="field-input" accept="image/jpeg,image/png,image/webp"></label>
        </fieldset>
    @endforeach
    <p class="flex flex-wrap gap-x-5 text-sm sm:col-span-2" aria-live="polite">
        <span>ยอดรับชำระ <strong data-payment-total>0.00</strong> บาท</span>
        <span>เงินทอน <strong data-payment-change>0.00</strong> บาท</span>
    </p>
    <div class="sm:col-span-2">
        <x-crm-select name="document_type" label="หัวเอกสาร" :options="array_map(fn ($type) => $type[0], \App\Models\RepairPayment::TYPES)" default="receipt" />
    </div>
    <div class="sm:col-span-2"><x-repair-input name="note" label="หมายเหตุบนเอกสาร" :limit="500" :rows="2" /></div>
    <label class="flex items-start gap-2 text-sm sm:col-span-2">
        <input class="mt-1" type="checkbox" name="confirmed" value="1" required>
        <span>ยืนยันยอดที่บันทึก โดยยอดโอนหรือ QR ต้องรอตรวจยืนยัน</span>
    </label>
    <button class="button button-primary justify-self-start">บันทึกรับเงิน</button>
</form>
