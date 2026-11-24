<x-layout title="ตั้งรหัสผ่านใหม่">
    <section class="panel mx-auto mt-10 max-w-md p-6 sm:p-8">
        <h1 class="text-2xl font-bold">ตั้งรหัสผ่านใหม่</h1>
        <form class="mt-5 space-y-4" method="POST" action="{{ route('password.email') }}">
            @csrf
            <label class="block">
                <span class="field-label">อีเมลพนักงาน</span>
                <input class="field-input" type="email" name="email" value="{{ is_string(old('email')) ? old('email') : '' }}" maxlength="254" autocomplete="email" required autofocus>
                @error('email') <span class="field-error">{{ $message }}</span> @enderror
            </label>
            <button class="button button-primary" type="submit">ส่งลิงก์ตั้งรหัสผ่าน</button>
        </form>
        <a class="endu-text-action mt-4" href="{{ route('login') }}">กลับไปเข้าสู่ระบบ</a>
    </section>
</x-layout>
