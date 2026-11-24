<x-layout title="คิวงานซ่อม">
    <div class="endu-page-head">
        <div>
            <a href="{{ route('dashboard', ['branch' => $branch->id]) }}#branches" class="text-sm font-semibold text-sky-800 hover:underline">← เลือกสาขา</a>
            <h1 class="mt-2">รับซ่อมและงานช่าง</h1>
            <p>{{ $branch->organization->name }} / {{ $branch->name }} · {{ $role->label() }}</p>
        </div>
        <div class="flex flex-wrap items-end gap-3">
            <form method="GET" action="{{ route('repairs.index', $branch) }}" class="flex items-end gap-2">
                @if ($referenceFilter !== '')
                    <input type="hidden" name="q" value="{{ $referenceFilter }}">
                @endif
                <div>
                    <label for="queue-status" class="field-label">สถานะงาน</label>
                    <select class="field-input py-2 text-sm" id="queue-status" name="status">
                        <option value="">ทุกสถานะ</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status->value }}" @selected($status === $statusFilter)>{{ $status->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <button class="button button-secondary" type="submit">กรอง</button>
            </form>
            @if ($role->allows(\App\Enums\BranchPermission::ExportRepairs))
                <a href="{{ route('repairs.export', $branch) }}" class="button button-secondary">ส่งออก CSV</a>
            @endif
            @if ($canIntake)
                <a href="{{ route('repairs.create', $branch) }}" class="button button-primary">+ เปิดใบรับซ่อม</a>
            @endif
        </div>
    </div>

    <nav class="endu-page-tabs" aria-label="งานซ่อม">
        <a href="{{ route('repairs.index', $branch) }}" aria-current="page">คิวงานซ่อม</a>
        @if ($role->allows(\App\Enums\BranchPermission::ReviewQuotation))
            <a href="{{ route('repairs.quotations.review-queue', $branch) }}">คำขออนุมัติราคา</a>
        @endif
        <a href="{{ route('phase-one', ['module' => 'REP', 'branch' => $branch->id]) }}">ฟังก์ชันเฟส 1</a>
    </nav>

    @if ($referenceFilter !== '')
        <p class="mb-3 flex flex-wrap items-center gap-3 text-sm">
            <span>เลขใบงานที่ค้นหา <strong>{{ $referenceFilter }}</strong></span>
            <a href="{{ route('repairs.index', [$branch, 'status' => $statusFilter?->value]) }}" class="font-semibold text-sky-800 hover:underline">ล้างการค้นหา</a>
        </p>
    @endif

    <section class="panel overflow-x-auto">
        <table class="w-full min-w-xl text-left text-sm">
            <caption class="sr-only">รายการใบรับซ่อม</caption>
            <thead class="bg-slate-100 text-slate-700">
                <tr>
                    <th scope="col" class="px-5 py-3">เลขใบงาน</th>
                    <th scope="col" class="px-5 py-3">อุปกรณ์</th>
                    <th scope="col" class="px-5 py-3">ช่างผู้รับผิดชอบ</th>
                    <th scope="col" class="px-5 py-3">สถานะ</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($repairs as $repair)
                    <tr>
                        <td class="whitespace-nowrap px-5 py-4">
                            <a href="{{ route('repairs.show', [$branch, $repair]) }}" class="font-semibold text-sky-800 hover:underline">{{ $repair->reference() }}</a>
                        </td>
                        <td class="max-w-sm wrap-break-word px-5 py-4">{{ $repair->device }}</td>
                        <td class="px-5 py-4">{{ $repair->technician?->name ?? '—' }}</td>
                        <td class="whitespace-nowrap px-5 py-4">
                            <x-repair-status :status="$repair->status" />
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-5 py-10 text-center text-slate-600">{{ $referenceFilter !== '' ? 'ไม่พบใบงานที่ตรงกับการค้นหา' : ($statusFilter ? 'ไม่มีใบงานในสถานะนี้' : 'ยังไม่มีใบงาน') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
    @if ($repairs->hasPages())
        <nav aria-label="หน้าคิวงานซ่อม" class="mt-5 flex justify-end gap-2">
            @if ($repairs->previousPageUrl())
                <a class="button button-secondary" href="{{ $repairs->previousPageUrl() }}">ก่อนหน้า</a>
            @endif
            @if ($repairs->nextPageUrl())
                <a class="button button-secondary" href="{{ $repairs->nextPageUrl() }}">ถัดไป</a>
            @endif
        </nav>
    @endif
</x-layout>
