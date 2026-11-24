<div class="grid gap-3 sm:grid-cols-2">
    <label class="block">
        <span class="field-label">ชื่อสาขา</span>
        <input class="field-input" name="name" value="{{ $editableBranch?->name ?? old('name') }}" maxlength="150" required>
    </label>
    <label class="block">
        <span class="field-label">รหัสสาขา</span>
        <input class="field-input" name="code" value="{{ $editableBranch?->code ?? old('code') }}" maxlength="32" required>
    </label>
    <label class="block">
        <span class="field-label">โทรศัพท์สาขา</span>
        <input class="field-input" type="tel" name="phone" value="{{ $editableBranch?->phone ?? old('phone') }}" maxlength="30" required>
    </label>
    <label class="block">
        <span class="field-label">เวลาเปิดบริการ</span>
        <input class="field-input" name="opening_hours" value="{{ $editableBranch?->opening_hours ?? old('opening_hours') }}" placeholder="จันทร์–เสาร์ 09:00–18:00" maxlength="250" required>
    </label>
    <label class="block sm:col-span-2">
        <span class="field-label">ที่อยู่สาขา</span>
        <textarea class="field-input" name="address" rows="2" maxlength="750" required>{{ $editableBranch?->address ?? old('address') }}</textarea>
    </label>
</div>
