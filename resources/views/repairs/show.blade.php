@php
    use App\Enums\BranchPermission;
    use App\Support\PrivateDisplay;

    $showPrivateDetails = $role->allows(BranchPermission::PrivateCustomerDetails);
@endphp

<x-layout :title="$repair->reference()">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <a href="{{ route('repairs.index', $branch) }}" class="text-sm font-semibold text-sky-800 hover:underline">← คิวงานซ่อม</a>
            <div class="mt-3 flex flex-wrap items-center gap-3">
                <h1 class="text-3xl font-semibold tracking-tight">{{ $repair->reference() }}</h1>
                <x-repair-status :status="$repair->status" />
            </div>
            <p class="mt-2 text-sm text-slate-600">{{ $branch->name }} · {{ $repair->created_at->timezone('Asia/Bangkok')->format('d/m/Y H:i') }} น.</p>
            @if ($repair->business_date)
                <p class="mt-1 text-xs text-slate-600">วันทำการ {{ $repair->business_date->format('d/m/Y') }}</p>
            @endif
        </div>
        @if ($role->allows(BranchPermission::ReadQuotation) || $role->allows(BranchPermission::PrintRepair) || $role->allows(BranchPermission::ManagePaymentDocuments))
            <div class="endu-actions">
                @if ($role->allows(BranchPermission::ManagePaymentDocuments))
                    <a class="button button-secondary" href="{{ route('repairs.payments.index', [$branch, $repair]) }}"><x-workspace-icon name="wallet" /> รับเงินและออกใบเสร็จ</a>
                @endif
                @if ($role->allows(BranchPermission::ConfirmCustomerPrice) && $quotationForApproval?->canRelease() && $quotationForApproval->approved_at === null)
                    <a class="button button-primary" href="{{ route('repairs.quotations.show', [$branch, $repair, $quotationForApproval]) }}#customer-approval">การอนุมัติราคาจากลูกค้า</a>
                @endif
                @if ($role->allows(BranchPermission::ReadQuotation))
                    <a class="button button-secondary" href="{{ route('repairs.quotations.index', [$branch, $repair]) }}"><x-workspace-icon name="doc" /> ใบเสนอราคา</a>
                @endif
                @if ($role->allows(BranchPermission::PrintRepair))
                    <a class="button button-secondary" href="{{ route('repairs.print', [$branch, $repair]) }}">พิมพ์ใบรับเครื่อง</a>
                @endif
            </div>
        @endif
    </div>

    @if ($repair->price_change_assessment_id !== null)
        <section class="panel mb-5 flex flex-wrap items-center justify-between gap-3 border-amber-200 bg-amber-50 p-4" aria-labelledby="price-change-title">
            <div>
                <h2 id="price-change-title" class="font-bold text-amber-900">พักงานรออนุมัติราคาใหม่</h2>
                <p class="mt-1 text-sm text-amber-900">รอให้ลูกค้ายืนยันใบเสนอราคาฉบับแก้ก่อนทำงานเพิ่มเติม</p>
            </div>
            @if ($role->allows(BranchPermission::DraftQuotation) && $quotationForApproval)
                @if ($repair->coversPriceChange($quotationForApproval->repair_assessment_id))
                    <a class="button button-secondary" href="{{ route('repairs.quotations.show', [$branch, $repair, $quotationForApproval]) }}">เปิดใบเสนอราคาฉบับแก้</a>
                @else
                    <a class="button button-primary" href="{{ route('repairs.quotations.create', [$branch, $repair, 'from_assessment' => 1]) }}">จัดทำใบเสนอราคางานเพิ่ม</a>
                @endif
            @endif
        </section>
    @endif

    @if ($repair->closure)
        @include('repairs.closure')
    @elseif ($repair->status === \App\Enums\RepairStatus::Delivered && $role->allows(BranchPermission::IssueServiceDocuments))
        <p class="mb-4"><a class="endu-text-action" href="{{ route('documents.source', [$branch, 'repair', $repair->id]) }}">รับเคลมจากใบงานนี้</a></p>
    @endif

    @if ($deposits !== null)
        @include('repairs.deposits')
    @endif

    <div class="grid gap-5 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
        <section class="panel min-w-0 p-5 sm:p-7">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-lg font-semibold">ข้อมูลรับเครื่อง</h2>
                @if ($repair->customer_id && $role->allows(BranchPermission::ManageCustomers))
                    <a class="endu-text-action" href="{{ route('crm.customers.show', [$branch, $repair->customer_id]) }}">ทะเบียนลูกค้า</a>
                @endif
            </div>
            <dl class="mt-5 grid gap-5 sm:grid-cols-2">
                @if ($repair->work_kind)
                    <div>
                        <dt class="detail-label">ประเภทใบงาน</dt>
                        <dd>{{ $repair->work_kind->label() }}</dd>
                    </div>
                @endif
                @if ($repair->fixed_price_satang !== null)
                    <div>
                        <dt class="detail-label">ราคาที่ตกลงตอนรับเครื่อง</dt>
                        <dd>{{ \App\Models\RepairQuotation::formatBaht($repair->fixed_price_satang) }} บาท</dd>
                    </div>
                @endif
                @if ($role->allows(BranchPermission::CustomerData))
                    <div>
                        <dt class="detail-label">ชื่อลูกค้า</dt>
                        <dd class="wrap-break-word">{{ $repair->customer_name }}</dd>
                    </div>
                    <div>
                        <dt class="detail-label">เบอร์โทรศัพท์</dt>
                        <dd class="wrap-break-word">{{ $showPrivateDetails ? $repair->customer_phone : PrivateDisplay::phone($repair->customer_phone) }}</dd>
                    </div>
                    @if ($isEnduFix && $showPrivateDetails)
                        <div class="sm:col-span-2">
                            <dt class="detail-label">ที่อยู่</dt>
                            <dd class="whitespace-pre-wrap wrap-break-word">{{ $repair->customer_address ?? '—' }}</dd>
                        </div>
                    @endif
                @endif
                <div>
                    <dt class="detail-label">{{ $isEnduFix ? 'รุ่น' : 'อุปกรณ์และรุ่น' }}</dt>
                    <dd class="wrap-break-word">{{ $repair->device }}</dd>
                </div>
                <div>
                    <dt class="detail-label">พนักงานรับเครื่อง</dt>
                    <dd class="wrap-break-word">{{ $repair->receivedBy->name }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="detail-label">{{ $isEnduFix ? 'อาการเสีย' : 'อาการที่แจ้ง' }}</dt>
                    <dd class="whitespace-pre-wrap wrap-break-word">{{ $repair->issue }}</dd>
                </div>
                @php
                    $intakeDetails = [
                        ...($isEnduFix ? ['สินค้า' => $repair->product, 'สี' => $repair->color] : []),
                        'สภาพเครื่อง' => $repair->device_condition,
                        'อุปกรณ์ที่นำมาด้วย' => $repair->accessories,
                        'จุดเก็บเครื่อง' => $repair->storage_location,
                    ];
                    if ($showPrivateDetails) {
                        $intakeDetails += ['IMEI' => $repair->imei, 'Serial No.' => $repair->serial_number];
                        if ($isEnduFix) {
                            $intakeDetails += [
                                'เลขที่กรมธรรม์' => $repair->policy_number, 'เงื่อนไข' => $repair->intake_terms,
                                'อยู่ในเงื่อนไข' => match ($repair->warranty_status) {
                                    'covered' => 'ในประกัน',
                                    'not_covered' => 'นอกประกัน',
                                    default => null,
                                },
                            ];
                        }
                    } elseif ($role->allows(BranchPermission::MaskedDeviceIdentifiers)) {
                        $intakeDetails += [
                            'IMEI (ท้าย 4 ตัว)' => PrivateDisplay::finalFour($repair->imei),
                            'Serial No. (ท้าย 4 ตัว)' => PrivateDisplay::finalFour($repair->serial_number),
                        ];
                    }
                @endphp
                @foreach ($intakeDetails as $label => $value)
                    <div>
                        <dt class="detail-label">{{ $label }}</dt>
                        <dd class="whitespace-pre-wrap wrap-break-word">{{ $value ?? '—' }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>

        <div class="space-y-5">
            <section class="panel p-5 sm:p-7">
                <h2 class="text-lg font-semibold">ช่างผู้รับผิดชอบ</h2>
                <p class="mt-3">
                    <span @class(['badge', 'badge-assigned' => $repair->technician_id, 'badge-neutral' => ! $repair->technician_id])>
                        {{ $repair->technician?->name ?? 'รอมอบหมาย' }}
                    </span>
                </p>
                @if ($repair->assigned_at)
                    <p class="mt-3 text-sm text-slate-600">{{ $repair->assignedBy->name }} มอบหมายเมื่อ {{ $repair->assigned_at->timezone('Asia/Bangkok')->format('d/m/Y H:i') }} น.</p>
                @endif

                @if ($role->allows(BranchPermission::AssignRepair) && ! $repair->status->isClosed() && $technicians->isNotEmpty())
                    <form method="POST" action="{{ route('repairs.assign', [$branch, $repair]) }}" class="mt-5 space-y-3">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="assignment_version" value="{{ $repair->assignment_version }}">
                        <label class="field-label" for="technician-id">เลือกช่างในสาขา</label>
                        <select class="field-input" id="technician-id" name="technician_id" required @error('technician_id') aria-invalid="true" aria-describedby="technician-error" @enderror>
                            <option value="">เลือกชื่อช่าง</option>
                            @foreach ($technicians as $technician)
                                <option value="{{ $technician->user_id }}" @selected((string) $repair->technician_id === (string) $technician->user_id)>{{ $technician->user->name }}</option>
                            @endforeach
                        </select>
                        @error('technician_id') <p id="technician-error" class="field-error">{{ $message }}</p> @enderror
                        @error('assignment_version') <p role="alert" class="field-error">{{ $message }}</p> @enderror
                        <button class="button button-primary w-full" type="submit">บันทึกการมอบหมาย</button>
                    </form>
                @elseif ($role->allows(BranchPermission::AssignRepair) && ! $repair->status->isClosed())
                    <p class="mt-4 text-sm text-slate-600">สาขานี้ยังไม่มีบัญชีช่าง กรุณาติดต่อผู้ดูแลระบบ</p>
                @endif
            </section>
            @include('repairs.workflow')
            @include('repairs.deadline')
        </div>
    </div>

    @if ($canViewAssessment)
        @include('repairs.assessment-panel')
    @endif

    @if ($photos !== null)
        @include('repairs.evidence')
        @include('repairs.quality-check')
    @endif

    @include('repairs.assignment-history')

    @if ($role->allows(BranchPermission::ReadPartsDocuments))
        <section class="panel mt-5 p-5 sm:p-7">
            <h2 class="text-lg font-semibold">อะไหล่ในใบงาน</h2>
            @if ($partsCost !== null)
                <div class="mt-3">
                    <p class="detail-label">{{ $partsCost['pending'] ? 'ต้นทุนสุทธิเฉพาะรายการที่ทราบ' : 'ต้นทุนอะไหล่สุทธิ' }}</p>
                    <p class="text-lg font-semibold">{{ \App\Models\RepairQuotation::formatBaht($partsCost['total']) }} บาท</p>
                    @if ($partsCost['pending'])
                        <p class="mt-1 text-sm text-slate-600">มี {{ $partsCost['pending'] }} ใบเบิกคงค้างที่ยังไม่มีต้นทุนที่ยืนยันได้</p>
                    @endif
                </div>
            @endif
            <div class="mt-3 flex flex-wrap gap-2">
                <a class="button button-secondary" href="{{ route('catalog.stock', [$branch, 'job' => $repair->id]) }}">เอกสารเบิกและคืน</a>
                @if ($role->allows(BranchPermission::ManageRepairParts))
                    <a class="button button-primary" href="{{ route('repairs.parts.create', [$branch, $repair]) }}">เบิกหรือคืนอะไหล่</a>
                @endif
            </div>
        </section>
    @endif

    <section class="panel mt-5 p-5 sm:p-7" aria-labelledby="status-history">
        <h2 id="status-history" class="text-lg font-semibold">ประวัติสถานะงาน</h2>
        <ol class="mt-3 divide-y divide-slate-100">
            @forelse ($statusHistory as $change)
                <li class="space-y-1 py-3">
                    <p class="font-medium">{{ $change->from_status->label() }} → {{ $change->to_status->label() }}</p>
                    <p class="text-sm text-slate-600">{{ $change->actor->name }} · <time datetime="{{ $change->created_at->toIso8601String() }}">{{ $change->created_at->timezone('Asia/Bangkok')->format('d/m/Y H:i') }} น.</time></p>
                    @if ($change->note !== null && ($role->allows(BranchPermission::InternalNotes) || (int) $change->changed_by === auth()->id()))
                        <p class="whitespace-pre-wrap wrap-break-word text-sm">{{ $change->note }}</p>
                    @endif
                </li>
            @empty
                <li class="py-3 text-sm text-slate-600">ยังไม่มีการเปลี่ยนสถานะหลังรับเครื่อง</li>
            @endforelse
        </ol>
        @if ($statusHistory->hasPages())
            <nav aria-label="หน้าประวัติสถานะ" class="mt-2 flex justify-end gap-2">
                @if ($statusHistory->previousPageUrl())
                    <a class="button button-secondary" href="{{ $statusHistory->previousPageUrl() }}#status-history">ใหม่กว่า</a>
                @endif
                @if ($statusHistory->nextPageUrl())
                    <a class="button button-secondary" href="{{ $statusHistory->nextPageUrl() }}#status-history">เก่ากว่า</a>
                @endif
            </nav>
        @endif
    </section>
</x-layout>
