<?php

namespace App\Http\Controllers;

use App\Enums\BranchPermission;
use App\Enums\RepairKind;
use App\Enums\RepairStatus;
use App\Models\AuditEvent;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerDevice;
use App\Models\RepairJob;
use App\Models\RepairQualityCheck;
use App\Models\User;
use App\Support\DocumentPdf;
use App\Support\DocumentPrintHistory;
use App\Support\QuotationAmounts;
use App\Support\RepairBilling;
use App\Support\RepairClosing;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RepairController extends Controller
{
    public function index(Request $request, Branch $branch): View
    {
        $role = $branch->roleFor($request->user());
        abort_if($role === null, 404);
        abort_unless($role->allows(BranchPermission::ReadRepairs) || $role->allows(BranchPermission::ReadAssignedRepairs), 403);

        $statusFilter = $request->query('status');
        $statusFilter = is_string($statusFilter) ? RepairStatus::tryFrom($statusFilter) : null;
        $queues = RepairStatus::operationsQueues();
        $queueFilter = $request->query('queue');
        $queueFilter = is_string($queueFilter) && isset($queues[$queueFilter]) ? $queueFilter : null;
        $dueFilter = $request->query('due');
        $dueFilter = in_array($dueFilter, ['today', 'overdue'], true) ? $dueFilter : null;
        $ownOnly = $request->boolean('mine');
        $now = CarbonImmutable::now();
        $reference = $request->query('q');
        $reference = is_string($reference) ? Str::limit(trim($reference), 32, '') : '';
        $repairs = $branch->repairs()
            ->when(! $role->allows(BranchPermission::ReadRepairs), fn ($query) => $query->where('technician_id', $request->user()->id))
            ->when($ownOnly, fn ($query) => $query->responsibleFor($request->user()))
            ->when($queueFilter, fn ($query) => $query->whereIn('status', $queues[$queueFilter]['statuses']))
            ->when($statusFilter, fn ($query, RepairStatus $status) => $query->where('status', $status->value))
            ->when($dueFilter === 'today', fn ($query) => $query->open()->where('due_at', '>=', $now)
                ->where('due_at', '<', RepairJob::endOfDueDay($now)))
            ->when($dueFilter === 'overdue', fn ($query) => $query->open()->where('due_at', '<', $now))
            ->when($reference !== '', function ($query) use ($reference) {
                preg_match('/^(?:REP-)?([0-9]{1,18})$/iD', $reference, $match);

                return $query->whereKey((int) ($match[1] ?? 0));
            })
            ->with(['technician:id,name', 'receivedBy:id,name'])
            ->when($dueFilter, fn ($query) => $query->orderBy('due_at')->orderBy('id'), fn ($query) => $query->orderByDesc('id'))
            ->cursorPaginate(20)
            ->appends(array_filter([
                'status' => $statusFilter?->value, 'queue' => $queueFilter, 'due' => $dueFilter, 'mine' => $ownOnly ? 1 : null, 'q' => $reference,
            ], fn ($value) => $value !== null && $value !== ''));

        return view('repairs.index', [
            'branch' => $branch->load('organization'),
            'repairs' => $repairs,
            'canIntake' => $role->allows(BranchPermission::CreateRepair),
            'role' => $role,
            'statusFilter' => $statusFilter,
            'statuses' => RepairStatus::cases(),
            'queues' => $queues,
            'queueFilter' => $queueFilter,
            'dueFilter' => $dueFilter,
            'ownOnly' => $ownOnly,
            'now' => $now,
            'referenceFilter' => $reference,
        ]);
    }

    public function export(Branch $branch): StreamedResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::ExportRepairs]);

        return response()->streamDownload(function () use ($branch): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['เลขใบงาน', 'อุปกรณ์', 'สถานะ', 'วันที่รับเครื่อง (เวลาไทย)', 'วันทำการ'], escape: '');
            $repairs = $branch->repairs()->select(['id', 'device', 'status', 'created_at', 'business_date'])->lazyById();
            foreach ($repairs as $repair) {
                $device = preg_match('/\A(?:[\x00-\x20]*[=+@－＋＝＠-]|[\r\n\t])/u', $repair->device)
                    ? "'".$repair->device
                    : $repair->device;
                fputcsv($output, [
                    $repair->reference(), $device, $repair->status->label(),
                    $repair->created_at->timezone('Asia/Bangkok')->format('d/m/Y H:i'),
                    $repair->business_date?->format('d/m/Y') ?? $repair->created_at->timezone('Asia/Bangkok')->format('d/m/Y'),
                ], escape: '');
            }
            fclose($output);
        }, 'repairs-'.$branch->code.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    public function create(Request $request, Branch $branch): Response
    {
        Gate::authorize('permission', [$branch, BranchPermission::CreateRepair]);
        $selection = validator($request->query(), $this->customerSelectionRules());
        abort_if($selection->fails(), 404);
        [$customer, $registeredDevice] = $this->customerSelection($branch, $selection->validated());
        $canAssign = $request->user()->can('permission', [$branch, BranchPermission::AssignRepair]);

        return response()->view('repairs.create', [
            'branch' => $branch,
            'isEnduFix' => $branch->usesEnduFixReceipt(),
            'submissionId' => (string) Str::uuid(),
            'customer' => $customer,
            'registeredDevice' => $registeredDevice,
            'canAssign' => $canAssign,
            'technicians' => $canAssign ? $branch->technicians()->with('user:id,name')->orderBy('id')->get() : collect(),
        ], headers: ['Cache-Control' => 'private, no-store']);
    }

    public function store(Request $request, Branch $branch): RedirectResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::CreateRepair]);

        $rules = $this->customerSelectionRules() + [
            'due_at' => ['required_if:work_kind,quick', ...$this->deadlineRules()],
            'work_kind' => ['sometimes', 'required', Rule::enum(RepairKind::class)],
            'fixed_price' => ['nullable', 'required_if:work_kind,quick', 'prohibited_unless:work_kind,quick', QuotationAmounts::MONEY],
            'technician_id' => ['nullable', 'required_if:work_kind,quick', 'integer', 'min:1'],
            'submission_id' => ['required', 'uuid'],
            'customer_name' => ['required', 'string', 'max:150'],
            'customer_phone' => ['required', 'string', 'max:30', 'regex:/^\+?[0-9][0-9 ()-]{6,28}$/D'],
            'device' => ['required', 'string', 'max:150'],
            'issue' => ['required', 'string', 'max:2000'],
            'imei' => ['nullable', 'digits:15'],
            'serial_number' => ['nullable', 'string', 'max:80'],
            'device_condition' => [$branch->usesEnduFixReceipt() ? 'required' : 'nullable', 'string', 'max:500'],
            'accessories' => ['nullable', 'string', 'max:500'],
            'storage_location' => ['nullable', 'string', 'max:120'],
        ];

        if ($branch->usesEnduFixReceipt()) {
            $rules += [
                'customer_address' => ['nullable', 'string', 'max:500'],
                'product' => ['required', 'string', 'max:120'],
                'color' => ['nullable', 'string', 'max:80'],
                'intake_terms' => ['nullable', 'string', 'max:1000'],
                'policy_number' => ['nullable', 'string', 'max:80'],
                'warranty_status' => ['required', Rule::in(['covered', 'not_covered'])],
                'device_passcode' => ['nullable', 'string', 'max:64', 'not_regex:/[\x00-\x1F\x7F]/'],
                'device_unlock_pattern' => ['nullable', 'string', 'regex:/^(?!.*([1-9]).*\1)[1-9]{4,9}$/D'],
            ];
        }

        $fields = $request->validate($rules, [
            'required' => 'กรุณากรอกหรือเลือก:attribute',
            'required_if' => 'กรุณากรอกหรือเลือก:attribute สำหรับงานด่วน',
            'fixed_price.regex' => 'ราคาตกลงต้องกรอกเป็นบาทและมีทศนิยมไม่เกิน 2 ตำแหน่ง',
            'imei.digits' => 'IMEI ต้องเป็นตัวเลข 15 หลัก',
            'warranty_status.in' => 'กรุณาเลือก ในประกัน หรือ นอกประกัน',
            'device_passcode.not_regex' => 'รหัสผ่านเครื่องมีอักขระที่ใช้ไม่ได้',
            'device_unlock_pattern.regex' => 'เลือกรูปแบบรหัส 4–9 จุด โดยแต่ละจุดใช้ได้ครั้งเดียว',
        ], [
            'customer_name' => 'ชื่อลูกค้า',
            'customer_phone' => 'เบอร์โทรศัพท์',
            'customer_address' => 'ที่อยู่',
            'product' => 'สินค้า',
            'device' => 'รุ่น',
            'color' => 'สี',
            'serial_number' => 'Serial No.',
            'intake_terms' => 'เงื่อนไข',
            'policy_number' => 'เลขที่กรมธรรม์',
            'device_condition' => 'สภาพเครื่อง',
            'accessories' => 'อุปกรณ์ที่นำมาด้วย',
            'issue' => 'อาการเสีย',
            'warranty_status' => 'สถานะประกัน',
            'device_passcode' => 'รหัสผ่านเครื่อง',
            'due_at' => 'วันและเวลานัด',
            'fixed_price' => 'ราคาที่ตกลง',
            'technician_id' => 'ช่างผู้รับผิดชอบ',
        ]);

        if (isset($fields['due_at'])) {
            $fields['due_at'] = RepairJob::parseLocalDueTime($fields['due_at']);
        }

        [$customer, $registeredDevice] = $this->customerSelection($branch, $fields);
        if ($customer !== null && ! $branch->usesEnduFixReceipt()) {
            $fields['customer_address'] = $customer->billing_address ?? $customer->delivery_address;
        }
        if ($registeredDevice !== null) {
            foreach (['serial_number', 'imei'] as $key) {
                $identifier = $registeredDevice->$key;
                $entered = $key === 'serial_number' ? CustomerDevice::serialKey($fields[$key] ?? null) : ($fields[$key] ?? null);
                if ($identifier !== null && $entered !== null && $entered !== $identifier) {
                    throw ValidationException::withMessages([$key => 'หมายเลขเครื่องไม่ตรงกับเครื่องที่เลือกจากทะเบียนลูกค้า']);
                }
                $fields[$key] = $identifier ?? ($fields[$key] ?? null);
            }
        }

        if (isset($fields['device_unlock_pattern'])) {
            $fields['device_unlock_pattern'] = str_split($fields['device_unlock_pattern']);
        }

        $kind = RepairKind::from($fields['work_kind'] ?? RepairKind::Assessment->value);
        $technicianId = isset($fields['technician_id']) ? (int) $fields['technician_id'] : null;
        if ($technicianId !== null) {
            Gate::authorize('permission', [$branch, BranchPermission::AssignRepair]);
        }
        $fields['work_kind'] = $kind;
        $fields['fixed_price_satang'] = $kind === RepairKind::Quick ? QuotationAmounts::hundredths($fields['fixed_price']) : null;
        unset($fields['technician_id'], $fields['fixed_price']);

        $repair = DB::transaction(function () use ($branch, $request, $fields, $kind, $technicianId): RepairJob {
            Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $identity = ['created_by' => $request->user()->id, 'submission_id' => $fields['submission_id']];
            $existing = $branch->repairs()->where($identity)->first();
            if ($existing !== null) {
                return $existing;
            }

            $job = $branch->repairs()->make($identity + $fields);
            if ($kind === RepairKind::Quick) {
                $job->status = RepairStatus::Repairing;
            }
            if ($technicianId !== null) {
                $job->assignTo($this->activeTechnician($branch, $technicianId), $request->user());
            }
            $job->save();

            return $job;
        }, 3);

        return redirect()->route('repairs.show', [$branch, $repair])->with('status', 'บันทึกใบรับซ่อมแล้ว');
    }

    private function customerSelectionRules(): array
    {
        return [
            'customer_id' => ['nullable', 'required_with:customer_device_id', 'integer', 'min:1'],
            'customer_device_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array{?Customer, ?CustomerDevice} */
    private function customerSelection(Branch $branch, array $selection): array
    {
        if (! isset($selection['customer_id'])) {
            return [null, null];
        }

        Gate::authorize('permission', [$branch, BranchPermission::ManageCustomers]);
        $customer = $branch->customers()->findOrFail($selection['customer_id']);
        $device = isset($selection['customer_device_id'])
            ? $customer->customerDevices()->findOrFail($selection['customer_device_id'])
            : null;

        return [$customer, $device];
    }

    public function show(Request $request, Branch $branch, RepairJob $repair): Response
    {
        $repair->setRelation('branch', $branch);
        Gate::authorize('view', $repair);

        $role = $branch->roleFor($request->user());
        abort_if($role === null, 404);
        $technicians = $role->allows(BranchPermission::AssignRepair) && ! $repair->status->isClosed()
            ? $branch->technicians()->with('user:id,name')->orderBy('id')->get()
            : collect();
        $statusHistory = $repair->statusChanges()->with('actor:id,name')
            ->orderByDesc('version')->cursorPaginate(10, ['*'], 'history');
        $nextStatuses = $repair->manualNextStatuses($role);
        $quotationForApproval = $role->allows(BranchPermission::ReadQuotation) && $repair->status === RepairStatus::AwaitingApproval
            ? $repair->quotations()->orderByDesc('revision')->first(['id', 'requires_internal_approval', 'internal_decision', 'approved_at', 'repair_assessment_id', 'voided_at'])
            : null;
        if ($quotationForApproval !== null) {
            $nextStatuses = array_values(array_filter($nextStatuses, fn (RepairStatus $status) => $status !== RepairStatus::Repairing));
        }

        $canViewAssessment = $role->allows(BranchPermission::EditAssessment) || $role->allows(BranchPermission::ReadQuotation);
        $repair->load(['receivedBy:id,name', 'technician:id,name', 'assignedBy:id,name', 'closure']);
        if ($canViewAssessment) {
            $repair->load('latestAssessment.recordedBy:id,name');
        }
        $assignments = AuditEvent::where('branch_id', $branch->id)->where('subject_type', 'repair')
            ->where('subject_reference', $repair->reference())->whereNotNull('after_values->technician_id')
            ->orderByDesc('id')->cursorPaginate(10, ['*'], 'assignments');
        $assignmentNames = User::whereIn('id', $assignments->getCollection()->flatMap(fn (AuditEvent $event) => [
            $event->before_values['technician_id'] ?? null, $event->after_values['technician_id'],
        ])->filter()->unique()->values())->pluck('name', 'id');
        $canViewEvidence = $role->allows(BranchPermission::RepairEvidence);
        $photos = $canViewEvidence ? $repair->photos()->with('uploader:id,name')->orderByDesc('id')
            ->cursorPaginate(12, ['*'], 'photos') : null;
        $qualityChecks = $canViewEvidence ? $repair->qualityChecks()->with('inspector:id,name')->orderByDesc('id')
            ->cursorPaginate(10, ['*'], 'qualityChecks') : null;

        $canViewDeposits = $role->allows(BranchPermission::ViewRepairDeposits);

        return response()->view('repairs.show', [
            'branch' => $branch,
            'repair' => $repair,
            'billing' => $canViewDeposits ? RepairBilling::summary($repair) : null,
            'deposits' => $canViewDeposits ? RepairBilling::deposits($repair) : null,
            'technicians' => $technicians,
            'role' => $role,
            'canViewAssessment' => $canViewAssessment,
            'quotationForApproval' => $quotationForApproval,
            'isEnduFix' => $branch->usesEnduFixReceipt(),
            'nextStatuses' => $nextStatuses,
            'statusHistory' => $statusHistory,
            'now' => CarbonImmutable::now(),
            'assignments' => $assignments,
            'assignmentNames' => $assignmentNames,
            'photos' => $photos,
            'qualityChecks' => $qualityChecks,
            'qualityChecklist' => RepairQualityCheck::checklistFor($repair->work_kind),
            'partsCost' => $role->allows(BranchPermission::Costs) ? $repair->partsCost() : null,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function print(Request $request, Branch $branch, RepairJob $repair): Response
    {
        Gate::authorize('permission', [$branch, BranchPermission::PrintRepair]);

        Gate::authorize('permission', [$branch, BranchPermission::PrivateCustomerDetails]);
        $isEnduFix = $branch->usesEnduFixReceipt();
        $showUnlock = $isEnduFix && $request->boolean('show_unlock');
        if ($showUnlock) {
            Gate::authorize('permission', [$branch, BranchPermission::DeviceAccess]);
        }

        $data = [
            'branch' => $branch->loadMissing('organization'),
            'repair' => $repair->load('receivedBy:id,name'),
            'isEnduFix' => $isEnduFix,
            'showUnlock' => $showUnlock,
        ];

        if ($request->query('format') === 'pdf') {
            $pdf = app(DocumentPdf::class)->render('repairs.print', $data, $repair->reference());

            return DocumentPrintHistory::record($pdf, $branch, 'repair_intake', $repair->id, $repair->reference());
        }

        return response()->view('repairs.print', $data)->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function assign(Request $request, Branch $branch, RepairJob $repair): RedirectResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::AssignRepair]);
        $request->validate([
            'technician_id' => ['required', 'integer', 'min:1'],
            'assignment_version' => ['required', 'integer', 'min:0'],
        ]);

        DB::transaction(function () use ($request, $branch, $repair): void {
            $job = $branch->repairs()->whereKey($repair->id)->lockForUpdate()->firstOrFail();
            $technicianId = $request->integer('technician_id');

            if ($job->technician_id === $technicianId) {
                return;
            }

            if ($job->status->isClosed()) {
                throw ValidationException::withMessages(['technician_id' => 'ใบงานนี้ปิดแล้ว จึงเปลี่ยนช่างผู้รับผิดชอบไม่ได้']);
            }

            if ($job->assignment_version !== $request->integer('assignment_version')) {
                throw ValidationException::withMessages([
                    'technician_id' => 'ใบงานนี้ถูกเปลี่ยนผู้รับผิดชอบแล้ว กรุณาตรวจสอบข้อมูลและเลือกช่างอีกครั้ง',
                ]);
            }

            $job->assignTo($this->activeTechnician($branch, $technicianId), $request->user());
            $job->save();
        }, 3);

        return redirect()->route('repairs.show', [$branch, $repair])->with('status', 'มอบหมายช่างแล้ว');
    }

    public function changeStatus(Request $request, Branch $branch, RepairJob $repair): RedirectResponse
    {
        $repair->setRelation('branch', $branch);
        Gate::authorize('view', $repair);

        $validated = $request->validate([
            'next_status' => ['required', Rule::enum(RepairStatus::class)],
            'workflow_version' => ['required', 'integer', 'min:0'],
            'assignment_version' => ['required', 'integer', 'min:0'],
            'workflow_note' => ['nullable', 'string', 'max:2000'],
            'recipient' => ['nullable', 'string', 'max:150'],
            'warranty_days' => ['nullable', 'integer', 'between:0,3650'],
            'warranty_terms' => ['nullable', 'string', 'max:1000'],
            'return_reason' => ['nullable', Rule::in(array_keys(RepairClosing::RETURN_REASONS))],
            'retention_reason' => ['nullable', 'string', 'max:500'],
        ]);
        $next = RepairStatus::from($validated['next_status']);
        $note = $validated['workflow_note'] ?? null;

        DB::transaction(function () use ($request, $branch, $repair, $next, $note, $validated): void {
            if ($next->isClosed()) {
                Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            }
            $job = $branch->repairs()->whereKey($repair->id)->lockForUpdate()->firstOrFail();
            $job->setRelation('branch', $branch);
            Gate::authorize('view', $job);
            $role = $branch->roleFor($request->user());
            abort_if($role === null, 404);

            if ($job->workflow_version !== $request->integer('workflow_version')
                || $job->assignment_version !== $request->integer('assignment_version')) {
                throw ValidationException::withMessages(['next_status' => 'ใบงานนี้มีการอัปเดตแล้ว กรุณาตรวจสอบสถานะล่าสุดก่อนบันทึก']);
            }

            $previous = $job->status;
            abort_unless(in_array($next, $job->manualNextStatuses($role), true), 403);

            if ($previous === RepairStatus::AwaitingApproval && $next === RepairStatus::Repairing && $job->quotations()->exists()) {
                throw ValidationException::withMessages(['next_status' => 'กรุณาบันทึกการอนุมัติจากหน้าใบเสนอราคาฉบับล่าสุด']);
            }

            if (($label = $previous->noteLabel($next)) !== null && $note === null) {
                throw ValidationException::withMessages(['workflow_note' => 'กรุณาระบุ'.$label]);
            }

            if ($next->isClosed()) {
                RepairClosing::record($job, $next, $request->user(), $validated);
                $job->price_change_assessment_id = null;
            }
            $job->moveTo($next, $request->user(), $note);
        }, 3);

        $url = route('repairs.show', [$branch, $repair]).($next === RepairStatus::AwaitingQualityCheck ? '#repair-qc' : '');

        return redirect()->to($url)->with('status', 'อัปเดตสถานะเป็น '.$next->label().' แล้ว');
    }

    public function changeDeadline(Request $request, Branch $branch, RepairJob $repair): RedirectResponse
    {
        $repair->setRelation('branch', $branch);
        Gate::authorize('view', $repair);
        Gate::authorize('permission', [$branch, BranchPermission::EditRepairDeadline]);
        $fields = $request->validate([
            'due_at' => ['present', ...$this->deadlineRules()],
            'due_version' => ['required', 'integer', 'min:0'],
            'assignment_version' => ['required', 'integer', 'min:0'],
        ], ['due_at.date_format' => 'กรุณาระบุวันและเวลานัดให้ถูกต้อง']);
        $dueAt = isset($fields['due_at']) ? RepairJob::parseLocalDueTime($fields['due_at']) : null;

        DB::transaction(function () use ($branch, $repair, $fields, $dueAt): void {
            $job = $branch->repairs()->whereKey($repair->id)->lockForUpdate()->firstOrFail();
            $job->setRelation('branch', $branch);
            Gate::authorize('view', $job);
            Gate::authorize('permission', [$branch, BranchPermission::EditRepairDeadline]);

            if ($job->status->isClosed() || $job->due_version !== (int) $fields['due_version']
                || $job->assignment_version !== (int) $fields['assignment_version']) {
                throw ValidationException::withMessages(['due_at' => 'บันทึกวันนัดไม่ได้ กรุณาตรวจสถานะและวันนัดล่าสุดของใบงาน']);
            }

            $job->reviseDueAt($dueAt);
            $job->save();
        }, 3);

        return redirect()->route('repairs.show', [$branch, $repair])->with('status', 'บันทึกวันนัดใบงานแล้ว');
    }

    private function deadlineRules(): array
    {
        return ['nullable', 'date_format:Y-m-d\TH:i', 'after_or_equal:1001-01-01'];
    }

    private function activeTechnician(Branch $branch, int $technicianId): User
    {
        $technician = $branch->technicians()->where('user_id', $technicianId)->with('user')->lockForUpdate()->first();
        if ($technician === null) {
            throw ValidationException::withMessages(['technician_id' => 'กรุณาเลือกช่างที่มีสิทธิ์ในสาขานี้']);
        }

        return $technician->user;
    }
}
