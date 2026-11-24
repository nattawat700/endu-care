<form method="POST" enctype="multipart/form-data" action="{{ $action }}" class="mt-4 space-y-3">
    @csrf
    @method('PATCH')
    <div class="grid gap-3 sm:grid-cols-2">
        @foreach (['name' => [$nameLabel,150], 'tax_id' => ['เลขประจำตัวผู้เสียภาษี',13], 'office' => ['สำนักงานใหญ่ / เลขที่สาขา',100], 'phone' => ['โทรศัพท์บนเอกสาร',30], 'email' => ['อีเมลผู้ขาย',254], 'registration_id' => ['เลขทะเบียนนิติบุคคล / เลขประจำตัวประชาชนบนเอกสาร',13]] as $key => [$label, $max])
            <label class="block">
                <span class="field-label">{{ $label }}</span>
                <input class="field-input" name="{{ $key }}" value="{{ old($key, $profile[$key] ?? '') }}" maxlength="{{ $max }}" @if ($key === 'email') type="email" @endif @if (in_array($key, ['tax_id', 'registration_id'], true)) inputmode="numeric" pattern="[0-9]{13}" @endif>
            </label>
        @endforeach
        <label class="block sm:col-span-2">
            <span class="field-label">ที่อยู่ผู้ขายบนเอกสาร</span>
            <textarea class="field-input" name="address" rows="2" maxlength="750">{{ old('address', $profile['address'] ?? '') }}</textarea>
        </label>
        <label class="block min-w-0 sm:col-span-2">
            <span class="field-label">โลโก้</span>
            <input class="field-input" type="file" name="logo" accept="image/png,image/jpeg,image/webp" aria-describedby="document-logo-hint">
            <span id="document-logo-hint" class="mt-1 block text-sm text-slate-600">PNG, JPG หรือ WebP ไม่เกิน 2 MB และไม่เกิน 6,000 พิกเซลต่อด้าน</span>
        </label>
    </div>
    @if (isset($profile['logo']))
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="remove_logo" value="1"> นำโลโก้ที่ตั้งไว้สำหรับเอกสารใหม่ออก</label>
    @endif
    <button class="button button-primary" type="submit">บันทึกหัวเอกสาร</button>
</form>
