<x-layout title="เข้าสู่ระบบ">
    <div class="endu-login">
        <section class="endu-login__brand">
            <img src="{{ asset('images/endu-care-workspace.png') }}" width="640" height="98" alt="ENDU CARE">
            <h1>ระบบจัดการหน้าร้าน</h1>
            <p>รับเครื่อง ติดตามงานซ่อม และส่งมอบให้ลูกค้าในระบบเดียว</p>
        </section>
        <section class="panel endu-login__form">
            <h2 class="text-2xl font-bold">เข้าสู่ระบบ</h2>
            <form method="POST" action="{{ route('login.store') }}" class="mt-6 space-y-5">
                @csrf
                <div>
                    <label class="field-label" for="email">อีเมลพนักงาน</label>
                    <input class="field-input" id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
                    @error('email') <p id="email-error" class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="field-label" for="password">รหัสผ่าน</label>
                    <input class="field-input" id="password" name="password" type="password" autocomplete="current-password" required @error('password') aria-invalid="true" aria-describedby="password-error" @enderror>
                    @error('password') <p id="password-error" class="field-error">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="button button-primary w-full">เข้าสู่ระบบ</button>
            </form>
            <a class="endu-text-action mt-4" href="{{ route('password.request') }}">ลืมรหัสผ่าน</a>
        </section>
    </div>
</x-layout>
