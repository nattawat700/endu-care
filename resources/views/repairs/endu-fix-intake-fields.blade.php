<div class="sm:col-span-2">
    <x-repair-input name="customer_address" label="ที่อยู่" :limit="500" :rows="2" />
</div>
<x-repair-input name="product" label="สินค้า" :limit="120" placeholder="เช่น โทรศัพท์มือถือ" required />
<x-repair-input name="device" label="รุ่น" :limit="150" placeholder="เช่น iPhone 13" required />
<x-repair-input name="color" label="สี" :limit="80" />
<x-repair-input name="imei" label="IMEI" :limit="15" inputmode="numeric" placeholder="ตัวเลข 15 หลัก" />
<x-repair-input name="serial_number" label="Serial No." :limit="80" />
<x-repair-input name="policy_number" label="เลขที่กรมธรรม์" :limit="80" />
<div class="sm:col-span-2">
    <x-repair-input name="intake_terms" label="เงื่อนไข" :limit="1000" :rows="3" />
</div>
<div class="border-t border-slate-200 pt-4 sm:col-span-2">
    <h2 class="font-semibold">รายละเอียดการส่งซ่อม</h2>
</div>
<x-repair-input name="device_condition" label="สภาพเครื่อง" :limit="500" :rows="2" required />
<x-repair-input name="accessories" label="อุปกรณ์ที่นำมาด้วย" :limit="500" :rows="2" />
<div class="sm:col-span-2">
    <x-repair-input name="issue" label="อาการเสีย" :limit="2000" :rows="4" required />
</div>
<fieldset class="sm:col-span-2">
    <legend class="field-label">อยู่ในเงื่อนไข <span aria-hidden="true">*</span></legend>
    <div class="flex flex-wrap gap-6">
        @foreach (['covered' => 'ในประกัน', 'not_covered' => 'นอกประกัน'] as $status => $label)
            <label class="flex min-h-10 items-center gap-2">
                <input type="radio" name="warranty_status" value="{{ $status }}" required @checked(old('warranty_status') === $status) @error('warranty_status') aria-invalid="true" aria-describedby="warranty-error" @enderror>
                {{ $label }}
            </label>
        @endforeach
    </div>
    @error('warranty_status') <p id="warranty-error" class="field-error">{{ $message }}</p> @enderror
</fieldset>
<fieldset class="grid gap-4 border-t border-slate-200 pt-4 sm:col-span-2 sm:grid-cols-2">
    <legend class="field-label">การปลดล็อกเครื่อง</legend>
    <div>
        <label class="field-label" for="device_passcode">รหัสผ่านเครื่อง</label>
        <input class="field-input" type="password" id="device_passcode" name="device_passcode" maxlength="64" autocomplete="new-password" aria-describedby="unlock-info @error('device_passcode') device-passcode-error @enderror" @error('device_passcode') aria-invalid="true" @enderror>
        <p id="unlock-info" class="mt-2 text-sm text-slate-600">กรอกเมื่อจำเป็นต่อการทดสอบเครื่องและลูกค้าอนุญาต</p>
        @error('device_passcode') <p id="device-passcode-error" class="field-error">{{ $message }}</p> @enderror
    </div>
    <div>
        <fieldset data-unlock-pattern aria-describedby="pattern-help @error('device_unlock_pattern') pattern-error @enderror">
            <legend class="field-label">รูปแบบรหัสผ่านเครื่อง</legend>
            <p id="pattern-help" class="mb-2 text-sm text-slate-600">กด 4–9 จุดตามลำดับที่ลูกค้าให้มา</p>
            <input type="hidden" name="device_unlock_pattern">
            <div class="grid w-fit grid-cols-3 gap-2">
                @foreach (range(1, 9) as $point)
                    <button type="button" class="grid size-10 place-items-center rounded-lg border border-slate-300 bg-white aria-pressed:border-sky-800 aria-pressed:bg-sky-800 aria-pressed:text-white" data-pattern-point="{{ $point }}" aria-pressed="false" aria-label="จุดที่ {{ $point }}">●</button>
                @endforeach
            </div>
            <div class="mt-2 flex items-center gap-3">
                <button type="button" data-pattern-reset class="button button-secondary">ล้างรูปแบบ</button>
                <output data-pattern-count class="text-sm text-slate-600" aria-live="polite"></output>
            </div>
        </fieldset>
        @error('device_unlock_pattern') <p id="pattern-error" class="field-error">{{ $message }}</p> @enderror
    </div>
</fieldset>
