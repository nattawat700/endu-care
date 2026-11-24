@php
    use App\Enums\ApprovalKind;
    use App\Models\Organization;
@endphp

<x-layout :title="$organization->name">
    <div class="endu-page-head">
        <div>
            <a class="endu-text-action" href="{{ route('organizations.index') }}">← กิจการและสาขา</a>
            <h1 class="mt-2">{{ $organization->name }}</h1>
            <p>{{ $organization->code }} · {{ $organization->suspended_at ? 'ระงับใช้งาน' : 'เปิดใช้งาน' }}</p>
        </div>
    </div>
    @include('organizations.errors')

    @if (! $organization->suspended_at)
        <div class="grid gap-5 xl:grid-cols-2">
            <section class="panel p-5">
                <h2 class="text-lg font-bold">ข้อมูลกิจการ</h2>
                <p class="mt-2 text-sm text-slate-600">เจ้าของกิจการ {{ $organization->owner?->name ?? 'ยังไม่ได้ระบุ' }}</p>
                <form method="POST" action="{{ route('organizations.update', $organization) }}" class="mt-4 space-y-3">
                    @csrf
                    @method('PATCH')
                    <label class="block"><span class="field-label">ชื่อกิจการ</span><input class="field-input" name="name" value="{{ $organization->name }}" maxlength="150" required></label>
                    <label class="block"><span class="field-label">ประเภทผู้ประกอบการ</span>
                        <select class="field-input" name="operator_type">
                            @foreach (Organization::OPERATOR_TYPES as $type => $label)
                                <option value="{{ $type }}" @selected($organization->operator_type === $type)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <button class="button button-primary" type="submit">บันทึกข้อมูลกิจการ</button>
                </form>
            </section>
            <section class="panel p-5">
                <h2 class="text-lg font-bold">สาขาที่ดูแล</h2>
                <ul class="mt-3 divide-y divide-slate-100">
                    @foreach ($branches as $branch)
                        <li class="py-2">
                            <a class="endu-text-action" href="{{ route('branch-settings.show', $branch) }}">{{ $branch->name }}</a>
                            <span class="ml-2 text-sm text-slate-600">{{ $branch->code }}</span>
                        </li>
                    @endforeach
                </ul>
                <details class="mt-4">
                    <summary class="endu-text-action cursor-pointer">เพิ่มสาขา</summary>
                    <form method="POST" action="{{ route('organizations.branches.store', $organization) }}" class="mt-3 space-y-3">
                        @csrf
                        @include('organizations.branch-fields', ['editableBranch' => null])
                        <button class="button button-primary" type="submit">สร้างสาขา</button>
                    </form>
                </details>
            </section>
            <section class="panel p-5">
                <h2 class="text-lg font-bold">หัวเอกสารกิจการ</h2>
                <p class="mt-1 text-sm text-slate-600">ตั้งค่าโลโก้และข้อมูลบนใบรับซ่อมและใบเสนอราคาที่เมนูตั้งค่าของแต่ละสาขา</p>
                @include('organizations.document-form', ['action' => route('organizations.document', $organization), 'profile' => $organization->document_profile ?? [], 'nameLabel' => 'ชื่อผู้ขายที่แสดง'])
            </section>
            <section class="panel p-5">
                <h2 class="text-lg font-bold">กติกาอนุมัติรายการ</h2>
                <form method="POST" action="{{ route('organizations.rules', $organization) }}" class="mt-4 space-y-4">
                    @csrf
                    @method('PATCH')
                    @foreach (ApprovalKind::cases() as $kind)
                        @php($rule = $organization->approval_rules[$kind->value] ?? null)
                        <fieldset class="grid gap-3 rounded-lg border border-slate-200 p-3 sm:grid-cols-2">
                            <legend class="px-1 text-sm font-semibold">{{ $kind->label() }}</legend>
                            <label class="block">
                                <span class="field-label">เพดาน ({{ $kind->unit() }})</span>
                                <input class="field-input" name="rules[{{ $kind->value }}][limit]" value="{{ old('rules.'.$kind->value.'.limit', $rule ? $kind->format($rule['limit'], '') : '') }}" inputmode="decimal" required>
                            </label>
                            <label class="block">
                                <span class="field-label">บทบาทผู้อนุมัติในสาขา</span>
                                <select class="field-input" name="rules[{{ $kind->value }}][review_role]" required>
                                    <option value="">เลือกบทบาท</option>
                                    @foreach (ApprovalKind::reviewRoles() as $role)
                                        <option value="{{ $role->value }}" @selected(old('rules.'.$kind->value.'.review_role', $rule['review_role'] ?? '') === $role->value)>{{ $role->label() }}</option>
                                    @endforeach
                                </select>
                            </label>
                        </fieldset>
                    @endforeach
                    <button class="button button-primary" type="submit">บันทึกกติกาอนุมัติ</button>
                </form>
            </section>
        </div>
    @endif

    <section class="panel mt-5 p-5">
        <h2 class="text-lg font-bold">สถานะกิจการและประวัติ</h2>
        <form method="POST" action="{{ route('organizations.status', $organization) }}" class="mt-4 max-w-lg space-y-3">
            @csrf
            @method('PATCH')
            <input type="hidden" name="suspended" value="{{ $organization->suspended_at ? 0 : 1 }}">
            <label class="block">
                <span class="field-label">เหตุผลที่{{ $organization->suspended_at ? 'เปิดใช้งาน' : 'ระงับ' }}กิจการ</span>
                <input class="field-input" name="reason" required maxlength="500">
            </label>
            <button class="button {{ $organization->suspended_at ? 'button-primary' : 'button-secondary' }}" type="submit">{{ $organization->suspended_at ? 'เปิดใช้งานกิจการอีกครั้ง' : 'ระงับกิจการ' }}</button>
        </form>
        <ul class="mt-4 divide-y divide-slate-100">
            @foreach ($history as $event)
                <li class="py-2 text-sm">
                    <strong>{{ $event->suspended ? 'ระงับกิจการ' : 'เปิดใช้งานกิจการ' }}</strong> · {{ $event->actor->name }} · {{ $event->created_at->timezone('Asia/Bangkok')->format('d/m/Y H:i') }}
                    <p class="wrap-break-word">{{ $event->reason }}</p>
                </li>
            @endforeach
        </ul>
        @if ($history->hasPages()) <div class="mt-4">{{ $history->links() }}</div> @endif
    </section>
</x-layout>
