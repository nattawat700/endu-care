<x-layout title="คิวงานซ่อม">
    @php
        $filters = ['due' => $dueFilter, 'mine' => $ownOnly ? 1 : null, 'q' => $referenceFilter ?: null];
        $showScope = $role->allows(\App\Enums\BranchPermission::ReadRepairs);
    @endphp
    <div class="endu-page-head">
        <div>
            <a href="{{ route('dashboard', ['branch' => $branch->id]) }}#branches" class="text-sm font-semibold text-sky-800 hover:underline">← เลือกสาขา</a>
            <h1 class="mt-2">รับซ่อมและงานช่าง</h1>
            <p>{{ $branch->organization->name }} / {{ $branch->name }} · {{ $role->label() }}</p>
        </div>
        <div class="flex flex-wrap items-end gap-3">
            <form method="GET" action="{{ route('repairs.index', $branch) }}" class="flex flex-wrap items-end gap-2">
                @if ($referenceFilter !== '')
                    <input type="hidden" name="q" value="{{ $referenceFilter }}">
                @endif
                @if ($queueFilter)
                    <input type="hidden" name="queue" value="{{ $queueFilter }}">
                @else
                    <div>
                        <label for="queue-status" class="field-label">สถานะงาน</label>
                        <select class="field-input py-2 text-sm" id="queue-status" name="status">
                            <option value="">ทุกสถานะ</option>
                            @foreach ($statuses as $status)
                                <option value="{{ $status->value }}" @selected($status === $statusFilter)>{{ $status->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <div>
                    <label for="queue-due" class="field-label">วันนัด</label>
                    <select class="field-input py-2 text-sm" id="queue-due" name="due">
                        <option value="">ทุกวันนัด</option>
                        <option value="today" @selected($dueFilter === 'today')>ถึงกำหนดวันนี้</option>
                        <option value="overdue" @selected($dueFilter === 'overdue')>เกินนัด</option>
                    </select>
                </div>
                @if ($showScope)
                    <div>
                        <label for="queue-scope" class="field-label">ผู้รับผิดชอบ</label>
                        <select class="field-input py-2 text-sm" id="queue-scope" name="mine">
                            <option value="0">ทั้งสาขา</option>
                            <option value="1" @selected($ownOnly)>งานของฉัน</option>
                        </select>
                    </div>
                @elseif ($ownOnly)
                    <input type="hidden" name="mine" value="1">
                @endif
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

    <nav aria-label="ประเภทคิวงาน" class="mb-4 flex flex-wrap gap-2">
        <a href="{{ route('repairs.index', ['branch' => $branch] + $filters) }}"
            @class(['button', 'button-primary' => $queueFilter === null, 'button-secondary' => $queueFilter !== null])
            @if ($queueFilter === null) aria-current="page" @endif>ทุกคิว</a>
        @foreach ($queues as $key => $queue)
            <a href="{{ route('repairs.index', ['branch' => $branch, 'queue' => $key] + $filters) }}"
                @class(['button', 'button-primary' => $queueFilter === $key, 'button-secondary' => $queueFilter !== $key])
                @if ($queueFilter === $key) aria-current="page" @endif>{{ $queue['label'] }}</a>
        @endforeach
    </nav>

    @if ($referenceFilter !== '')
        <p class="mb-3 flex flex-wrap items-center gap-3 text-sm">
            <span>เลขใบงานที่ค้นหา <strong>{{ $referenceFilter }}</strong></span>
            <a href="{{ route('repairs.index', ['branch' => $branch, 'status' => $statusFilter?->value, 'queue' => $queueFilter] + array_diff_key($filters, ['q' => true])) }}" class="font-semibold text-sky-800 hover:underline">ล้างการค้นหา</a>
        </p>
    @endif

    <section class="panel overflow-x-auto">
        <table class="w-full min-w-xl text-left text-sm">
            <caption class="sr-only">รายการใบรับซ่อม</caption>
            <thead class="bg-slate-100 text-slate-700">
                <tr>
                    <th scope="col" class="px-5 py-3">เลขใบงาน</th>
                    <th scope="col" class="px-5 py-3">อุปกรณ์</th>
                    <th scope="col" class="px-5 py-3">ผู้รับผิดชอบ</th>
                    <th scope="col" class="px-5 py-3">วันนัดล่าสุด</th>
                    <th scope="col" class="px-5 py-3">สถานะ</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($repairs as $repair)
                    <tr>
                        <td class="whitespace-nowrap px-5 py-4">
                            <a href="{{ route('repairs.show', [$branch, $repair]) }}" class="font-semibold text-sky-800 hover:underline">{{ $repair->reference() }}</a>
                        </td>
                        <td class="max-w-sm wrap-break-word px-5 py-4">
                            {{ $repair->device }}
                            @if ($repair->work_kind)<span class="block text-xs text-slate-600">{{ $repair->work_kind->label() }}</span>@endif
                        </td>
                        <td class="px-5 py-4">
                            {{ $repair->technician?->name ?? $repair->receivedBy->name }}
                            @if ($repair->technician_id === null)<span class="block text-xs text-slate-600">ผู้รับเครื่อง</span>@endif
                        </td>
                        <td class="px-5 py-4"><x-repair-deadline :repair="$repair" :now="$now" /></td>
                        <td class="whitespace-nowrap px-5 py-4">
                            <x-repair-status :status="$repair->status" />
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-5 py-10 text-center text-slate-600">{{ $referenceFilter !== '' ? 'ไม่พบใบงานที่ตรงกับการค้นหา' : ($statusFilter || $queueFilter || $dueFilter || $ownOnly ? 'ไม่พบใบงานตามตัวกรอง' : 'ยังไม่มีใบงาน') }}</td></tr>
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
