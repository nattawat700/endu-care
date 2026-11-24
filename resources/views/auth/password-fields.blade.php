<label class="block">
    <span class="field-label">ตั้งรหัสผ่าน (อย่างน้อย 12 ตัวอักษร)</span>
    <input class="field-input" type="password" name="password" minlength="12" maxlength="72" autocomplete="new-password" required>
    @error('password') <span class="field-error">{{ $message }}</span> @enderror
</label>
<label class="block">
    <span class="field-label">ยืนยันรหัสผ่าน</span>
    <input class="field-input" type="password" name="password_confirmation" minlength="12" maxlength="72" autocomplete="new-password" required>
</label>
