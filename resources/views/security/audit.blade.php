@php
    use App\Enums\BranchRole;
    use App\Enums\RepairStatus;
    use App\Models\AuditEvent;
    use App\Models\RepairQuotation;
    use Carbon\CarbonImmutable;

    $fieldLabels = [
        'status' => 'สถานะ', 'technician_id' => 'รหัสช่าง', 'role' => 'บทบาท', 'user_id' => 'รหัสพนักงาน',
        'total_satang' => 'ยอดเสนอราคา (บาท)', 'assignment_version' => 'ครั้งที่มอบหมาย', 'workflow_version' => 'ครั้งที่เปลี่ยนสถานะ',
        'revision' => 'ฉบับ', 'internal_decision' => 'ผลอนุมัติภายใน', 'customer_request_channel' => 'ช่องทางส่งใบเสนอราคา',
        'approval_channel' => 'ช่องทางที่ลูกค้ายืนยัน', 'suspended_at' => 'เวลาระงับ', 'name' => 'ชื่อ', 'code' => 'รหัส',
        'approval_rules' => 'กติกาอนุมัติ', 'day_cutoff' => 'จุดตัดวัน', 'business_date' => 'วันทำการ',
        'staff_session_version' => 'รุ่นเซสชัน', 'amount' => 'จำนวน (สตางค์หรือหน่วย)', 'limit' => 'เพดาน (สตางค์หรือหน่วย)',
        'price_change_assessment_id' => 'รหัสผลประเมินงานเพิ่ม', 'repair_assessment_id' => 'รหัสผลประเมิน',
        'expected_completion_at' => 'กำหนดซ่อมเสร็จ', 'internal_requested_by' => 'รหัสผู้ขออนุมัติภายใน',
        'internal_requested_at' => 'เวลาขออนุมัติภายใน', 'internal_decided_by' => 'รหัสผู้อนุมัติภายใน',
        'internal_decided_at' => 'เวลาอนุมัติภายใน', 'customer_requested_by' => 'รหัสผู้ส่งใบเสนอราคา',
        'customer_requested_at' => 'เวลาส่งใบเสนอราคา', 'approved_by' => 'รหัสผู้บันทึกการยืนยัน',
        'approved_at' => 'เวลาบันทึกการยืนยัน', 'customer_confirmed_at' => 'เวลาที่ลูกค้ายืนยัน',
        'operator_type' => 'ประเภทกิจการ', 'owner_id' => 'รหัสเจ้าของ', 'opening_hours' => 'เวลาเปิดบริการ',
        'working_days' => 'วันทำการ', 'shift_name' => 'ชื่อกะ', 'shift_start' => 'เวลาเริ่มกะ', 'shift_end' => 'เวลาจบกะ',
        'kind' => 'ประเภทคำขอ', 'review_role' => 'บทบาทผู้อนุมัติ', 'reviewed_by' => 'รหัสผู้พิจารณา',
        'reviewed_at' => 'เวลาพิจารณา', 'payment_point_id' => 'รหัสจุดรับเงิน', 'printer_profile_id' => 'รหัสโปรไฟล์เครื่องพิมพ์',
        'voided_at' => 'เวลายกเลิก', 'voided_by' => 'รหัสผู้ยกเลิก', 'issue_id' => 'รหัสเอกสารคลังต้นทาง',
        'repair_payment_id' => 'รหัสรายการรับเงินต้นทาง', 'pos_sale_id' => 'รหัสบิลขายต้นทาง',
        'paper' => 'ขนาดกระดาษ', 'invited_by' => 'รหัสผู้เชิญ', 'expires_at' => 'เวลาหมดอายุ', 'accepted_at' => 'เวลารับคำเชิญ',
    ];
    $display = static function (string $field, mixed $value): string {
        if ($value === null) {
            return '—';
        }

        return match (true) {
            $field === 'status' && is_string($value) => RepairStatus::tryFrom($value)?->label() ?? $value,
            in_array($field, ['role', 'review_role'], true) => BranchRole::tryFrom($value)?->label() ?? (string) $value,
            $field === 'total_satang' => RepairQuotation::formatBaht((int) $value),
            str_ends_with($field, '_at') => CarbonImmutable::parse($value, 'UTC')->timezone('Asia/Bangkok')->format('d/m/Y H:i:s'),
            is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE),
            default => (string) $value,
        };
    };
@endphp

<x-layout title="ประวัติการเปลี่ยนแปลง">
    <div class="endu-page-head">
        <div><h1>ประวัติการเปลี่ยนแปลง</h1></div>
    </div>
    @include('organizations.errors')
    <form class="panel mb-5 grid items-end gap-3 p-4 sm:grid-cols-2 xl:grid-cols-3" method="GET" action="{{ route('audit.index') }}">
        <label class="block">
            <span class="field-label">สาขา</span>
            <select class="field-input" name="branch">
                <option value="">ทุกขอบเขตที่มีสิทธิ์</option>
                @foreach ($branches as $branch)
                    <option value="{{ $branch->id }}" @selected((int) ($filters['branch'] ?? 0) === $branch->id)>{{ $branch->organization->name }} / {{ $branch->name }}</option>
                @endforeach
            </select>
        </label>
        <label class="block">
            <span class="field-label">ชื่อผู้ทำรายการ</span>
            <input class="field-input" name="actor" value="{{ $filters['actor'] ?? '' }}" maxlength="100">
        </label>
        <label class="block">
            <span class="field-label">เลขอ้างอิง</span>
            <input class="field-input" name="reference" value="{{ $filters['reference'] ?? '' }}" maxlength="100" placeholder="REP-000001">
        </label>
        <label class="block">
            <span class="field-label">ประเภทรายการ</span>
            <select class="field-input" name="subject">
                <option value="">ทุกประเภท</option>
                @foreach (AuditEvent::SUBJECTS as $key => $label)
                    <option value="{{ $key }}" @selected(($filters['subject'] ?? '') === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label class="block"><span class="field-label">ตั้งแต่วันที่ (เวลาไทย)</span><input class="field-input" name="from" type="date" value="{{ $filters['from'] ?? '' }}"></label>
        <label class="block"><span class="field-label">ถึงวันที่ (เวลาไทย)</span><input class="field-input" name="to" type="date" value="{{ $filters['to'] ?? '' }}"></label>
        <div class="flex flex-wrap gap-2 sm:col-span-2 xl:col-span-3">
            <button class="button button-primary" type="submit">ค้นประวัติ</button>
            <a class="button button-secondary" href="{{ route('audit.index') }}">ล้างตัวกรอง</a>
        </div>
    </form>

    <div class="space-y-3">
        @forelse ($events as $event)
            @php($fields = array_unique([...array_keys($event->before_values ?? []), ...array_keys($event->after_values ?? [])]))
            <article class="panel p-4 sm:p-5">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <h2 class="font-bold">{{ AuditEvent::SUBJECTS[$event->subject_type] }} · {{ AuditEvent::OPERATIONS[$event->operation] }} · {{ $event->subject_reference }}</h2>
                        <p class="mt-1 text-sm text-slate-600">{{ $event->organization->name }}@if ($event->branch) / {{ $event->branch->name }}@endif · ผู้ทำ {{ $event->actor_name ?? 'ระบบ' }}</p>
                    </div>
                    <time class="text-sm text-slate-600" datetime="{{ $event->occurred_at->toIso8601String() }}">{{ $event->occurred_at->timezone('Asia/Bangkok')->format('d/m/Y H:i:s') }}</time>
                </div>
                @if ($fields !== [])
                    <details class="mt-3">
                        <summary class="endu-text-action cursor-pointer">ดูค่าก่อนและหลัง</summary>
                        <div class="endu-table-scroll mt-2">
                            <table>
                                <thead><tr><th scope="col">ข้อมูล</th><th scope="col">ก่อน</th><th scope="col">หลัง</th></tr></thead>
                                <tbody>
                                    @foreach ($fields as $field)
                                        <tr>
                                            <th scope="row">{{ $fieldLabels[$field] ?? str_replace('_', ' ', $field) }}</th>
                                            <td class="wrap-break-word">{{ $display($field, $event->before_values[$field] ?? null) }}</td>
                                            <td class="wrap-break-word">{{ $display($field, $event->after_values[$field] ?? null) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </details>
                @endif
                @if ($event->protected_changes)
                    <p class="mt-2 text-sm text-slate-600">มีการเปลี่ยนข้อมูลที่ปิดบัง · {{ implode(', ', $event->protected_changes) }}</p>
                @endif
            </article>
        @empty
            <p class="panel p-5 text-sm text-slate-600">ไม่พบประวัติที่ตรงกับตัวกรอง</p>
        @endforelse
    </div>
    @if ($events->hasPages())
        <nav class="mt-4 flex justify-end gap-2" aria-label="หน้าประวัติการเปลี่ยนแปลง">
            @if ($events->previousPageUrl()) <a class="button button-secondary" href="{{ $events->previousPageUrl() }}">ใหม่กว่า</a> @endif
            @if ($events->nextPageUrl()) <a class="button button-secondary" href="{{ $events->nextPageUrl() }}">เก่ากว่า</a> @endif
        </nav>
    @endif
</x-layout>
