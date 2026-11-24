<x-layout title="กิจการและสาขา">
    <div class="endu-page-head">
        <div><h1>กิจการและสาขา</h1></div>
    </div>

    @include('organizations.errors')

    <section class="panel mb-5 p-5">
        <h2 class="text-lg font-bold">กิจการที่ดูแล</h2>
        <ul class="mt-3 divide-y divide-slate-100">
            @forelse ($organizations as $organization)
                <li class="flex flex-wrap items-center justify-between gap-2 py-3">
                    <a class="endu-text-action" href="{{ route('organizations.show', $organization) }}">{{ $organization->name }}</a>
                    <span class="badge badge-neutral">{{ $organization->suspended_at ? 'ระงับใช้งาน' : 'เปิดใช้งาน' }}</span>
                </li>
            @empty
                <li class="py-3 text-sm text-slate-600">ยังไม่มีกิจการที่คุณดูแล</li>
            @endforelse
        </ul>
        @if ($organizations->hasPages())
            <div class="mt-4">{{ $organizations->links() }}</div>
        @endif
    </section>

    @can('create', \App\Models\Organization::class)
        <section class="panel p-5" aria-labelledby="create-organization">
            <h2 id="create-organization" class="text-lg font-bold">สร้างกิจการและสาขาแรก</h2>
            <form method="POST" action="{{ route('organizations.store') }}" class="mt-4 grid gap-4 sm:grid-cols-2">
                @csrf
                @foreach (['name' => 'ชื่อกิจการ', 'code' => 'รหัสกิจการ', 'branch_name' => 'ชื่อสาขาแรก', 'branch_code' => 'รหัสสาขาแรก'] as $key => $label)
                    <label class="block">
                        <span class="field-label">{{ $label }}</span>
                        <input class="field-input" name="{{ $key }}" value="{{ is_string(old($key)) ? old($key) : '' }}" maxlength="{{ str_ends_with($key, 'code') ? 32 : 150 }}" required>
                    </label>
                @endforeach
                <label class="block">
                    <span class="field-label">ประเภทผู้ประกอบการ</span>
                    <select class="field-input" name="operator_type" required>
                        @foreach (\App\Models\Organization::OPERATOR_TYPES as $value => $label)
                            <option value="{{ $value }}" @selected(old('operator_type') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="field-label">อีเมลบัญชีเจ้าของกิจการ</span>
                    <input class="field-input" type="email" name="owner_email" value="{{ is_string(old('owner_email')) ? old('owner_email') : '' }}" placeholder="อีเมลพนักงานที่เปิดใช้งานแล้ว" maxlength="254" required>
                </label>
                <div class="sm:col-span-2"><button class="button button-primary" type="submit">สร้างกิจการ</button></div>
            </form>
        </section>
    @endcan
</x-layout>
