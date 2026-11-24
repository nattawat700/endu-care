<x-layout title="คำเชิญพนักงาน">
    <section class="panel mx-auto mt-10 max-w-lg p-6 sm:p-8">
        <h1 class="text-2xl font-bold">รับคำเชิญพนักงาน</h1>
        <dl class="mt-4 grid gap-3">
            <div><dt class="detail-label">กิจการและสาขา</dt><dd>{{ $invitation->branch->organization->name }} / {{ $invitation->branch->name }}</dd></div>
            <div><dt class="detail-label">อีเมล</dt><dd class="wrap-break-word">{{ $invitation->email }}</dd></div>
            <div><dt class="detail-label">บทบาท</dt><dd>{{ $invitation->role->label() }}</dd></div>
        </dl>
        @error('invitation') <p class="field-error">{{ $message }}</p> @enderror
        <form class="mt-5 space-y-4" method="POST" action="{{ route('staff-invitations.accept', $token) }}">
            @csrf
            @unless ($account)
                @include('auth.password-fields')
            @endunless
            <button class="button button-primary" type="submit">รับคำเชิญ</button>
        </form>
    </section>
</x-layout>
