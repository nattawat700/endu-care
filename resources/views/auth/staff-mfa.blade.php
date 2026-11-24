<x-layout title="ยืนยันตัวตนสองขั้นตอน">
    <section class="panel mx-auto mt-10 max-w-lg p-6 sm:p-8">
        <h1 class="text-2xl font-bold">ยืนยันตัวตนสองขั้นตอน</h1>
        @if ($needsSetup && ! $secret)
            <p class="mt-3 text-sm text-slate-600">ขอรหัสตั้งค่าใช้ครั้งเดียวจากผู้ดูแลระบบเพื่อเชื่อมแอป Authenticator</p>
            <form class="mt-5 space-y-4" method="POST" action="{{ route('staff-mfa.setup') }}">
                @csrf
                <label class="block">
                    <span class="field-label">รหัสตั้งค่า</span>
                    <input class="field-input" name="setup_code" type="password" maxlength="24" autocomplete="off" required autofocus>
                    @error('setup_code') <span class="field-error">{{ $message }}</span> @enderror
                </label>
                <button class="button button-primary" type="submit">ตั้งค่า Authenticator</button>
            </form>
        @else
            @if ($secret)
                <p class="mt-3 text-sm text-slate-600">เพิ่มบัญชี ENDU CARE ในแอป Authenticator แบบกรอกคีย์ด้วยตนเอง และเลือกประเภทตามเวลา จากนั้นกรอกรหัส 6 หลักจากแอป</p>
                <p class="mt-3 break-all rounded-lg bg-slate-100 p-3 font-mono font-semibold" aria-label="คีย์ตั้งค่า Authenticator">{{ $secret }}</p>
            @else
                <p class="mt-3 text-sm text-slate-600">กรอกรหัส 6 หลักจาก Authenticator หรือรหัสกู้คืนที่ยังไม่เคยใช้</p>
            @endif
            <form class="mt-5 space-y-4" method="POST" action="{{ route('staff-mfa.verify') }}">
                @csrf
                <label class="block">
                    <span class="field-label">รหัสยืนยัน</span>
                    <input class="field-input" name="mfa_code" type="text" maxlength="40" autocomplete="one-time-code" autocapitalize="off" required autofocus>
                    @error('mfa_code') <span class="field-error">{{ $message }}</span> @enderror
                </label>
                <button class="button button-primary" type="submit">ยืนยันและเข้าสู่ระบบ</button>
            </form>
        @endif
        <a class="endu-text-action mt-4" href="{{ route('login') }}">กลับไปเข้าสู่ระบบ</a>
    </section>
</x-layout>
