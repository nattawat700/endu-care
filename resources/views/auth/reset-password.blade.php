<x-layout title="กำหนดรหัสผ่านใหม่">
    <section class="panel mx-auto mt-10 max-w-md p-6 sm:p-8">
        <h1 class="text-2xl font-bold">กำหนดรหัสผ่านใหม่</h1>
        <form class="mt-5 space-y-4" method="POST" action="{{ route('password.update') }}">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <label class="block">
                <span class="field-label">อีเมลพนักงาน</span>
                <input class="field-input" type="email" name="email" value="{{ is_string(old('email', $email)) ? old('email', $email) : '' }}" maxlength="254" autocomplete="email" required>
                @error('email') <span class="field-error">{{ $message }}</span> @enderror
            </label>
            @include('auth.password-fields')
            <button class="button button-primary" type="submit">บันทึกรหัสผ่านใหม่</button>
        </form>
        <a class="endu-text-action mt-4" href="{{ route('password.request') }}">ขอลิงก์ใหม่</a>
    </section>
</x-layout>
