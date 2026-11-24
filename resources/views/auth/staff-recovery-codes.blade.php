<x-layout title="รหัสกู้คืน">
    <section class="panel mx-auto max-w-xl p-6 sm:p-8">
        <h1 class="text-2xl font-bold">เก็บรหัสกู้คืน</h1>
        <p class="mt-3 text-sm text-slate-600">ระบบแสดงรหัสชุดนี้ครั้งเดียว ใช้แต่ละรหัสได้ครั้งเดียวเมื่อเข้าแอป Authenticator ไม่ได้</p>
        <ul class="mt-4 grid gap-2 rounded-lg bg-slate-100 p-4 font-mono sm:grid-cols-2">
            @foreach ($recoveryCodes as $code)
                <li>{{ $code }}</li>
            @endforeach
        </ul>
        <a class="button button-primary mt-5" href="{{ $continueUrl }}">เข้าสู่พื้นที่ทำงาน</a>
    </section>
</x-layout>
