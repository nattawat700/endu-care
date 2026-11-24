<?php

namespace App\Http\Controllers;

use App\Enums\BranchPermission;
use App\Enums\RepairStatus;
use App\Models\Branch;
use App\Models\RepairJob;
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
        $reference = $request->query('q');
        $reference = is_string($reference) ? Str::limit(trim($reference), 32, '') : '';
        $repairs = $branch->repairs()
            ->when(! $role->allows(BranchPermission::ReadRepairs), fn ($query) => $query->where('technician_id', $request->user()->id))
            ->when($statusFilter, fn ($query, RepairStatus $status) => $query->where('status', $status->value))
            ->when($reference !== '', function ($query) use ($reference) {
                preg_match('/^(?:REP-)?([0-9]{1,18})$/iD', $reference, $match);

                return $query->whereKey((int) ($match[1] ?? 0));
            })
            ->with('technician:id,name')
            ->orderByDesc('id')
            ->cursorPaginate(20)
            ->appends(array_filter(['status' => $statusFilter?->value, 'q' => $reference], fn ($value) => $value !== null && $value !== ''));

        return view('repairs.index', [
            'branch' => $branch->load('organization'),
            'repairs' => $repairs,
            'canIntake' => $role->allows(BranchPermission::CreateRepair),
            'role' => $role,
            'statusFilter' => $statusFilter,
            'statuses' => RepairStatus::cases(),
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

    public function create(Branch $branch): View
    {
        Gate::authorize('permission', [$branch, BranchPermission::CreateRepair]);

        return view('repairs.create', [
            'branch' => $branch,
            'isEnduFix' => $branch->usesEnduFixReceipt(),
            'submissionId' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request, Branch $branch): RedirectResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::CreateRepair]);

        $rules = [
            'submission_id' => ['required', 'uuid'],
            'customer_name' => ['required', 'string', 'max:150'],
            'customer_phone' => ['required', 'string', 'max:30', 'regex:/^\+?[0-9][0-9 ()-]{6,28}$/D'],
            'device' => ['required', 'string', 'max:150'],
            'issue' => ['required', 'string', 'max:2000'],
        ];

        if ($branch->usesEnduFixReceipt()) {
            $rules += [
                'customer_address' => ['nullable', 'string', 'max:500'],
                'product' => ['required', 'string', 'max:120'],
                'color' => ['nullable', 'string', 'max:80'],
                'imei' => ['nullable', 'digits:15'],
                'serial_number' => ['nullable', 'string', 'max:80'],
                'intake_terms' => ['nullable', 'string', 'max:1000'],
                'policy_number' => ['nullable', 'string', 'max:80'],
                'device_condition' => ['required', 'string', 'max:500'],
                'accessories' => ['nullable', 'string', 'max:500'],
                'warranty_status' => ['required', Rule::in(['covered', 'not_covered'])],
                'device_passcode' => ['nullable', 'string', 'max:64', 'not_regex:/[\x00-\x1F\x7F]/'],
                'device_unlock_pattern' => ['nullable', 'string', 'regex:/^(?!.*([1-9]).*\1)[1-9]{4,9}$/D'],
            ];
        }

        $fields = $request->validate($rules, [
            'required' => 'กรุณากรอกหรือเลือก:attribute',
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
        ]);

        if (isset($fields['device_unlock_pattern'])) {
            $fields['device_unlock_pattern'] = str_split($fields['device_unlock_pattern']);
        }

        $repair = DB::transaction(fn () => $branch->repairs()->firstOrCreate([
            'created_by' => $request->user()->id,
            'submission_id' => $fields['submission_id'],
        ], $fields), 3);

        return redirect()->route('repairs.show', [$branch, $repair])->with('status', 'บันทึกใบรับซ่อมแล้ว');
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
        $nextStatuses = $repair->status->nextStatuses($role);
        $quotationForApproval = $role->allows(BranchPermission::ReadQuotation) && $repair->status === RepairStatus::AwaitingApproval
            ? $repair->quotations()->orderByDesc('revision')->first(['id', 'requires_internal_approval', 'internal_decision', 'approved_at', 'repair_assessment_id'])
            : null;
        if ($quotationForApproval !== null) {
            $nextStatuses = array_values(array_filter($nextStatuses, fn (RepairStatus $status) => $status !== RepairStatus::Repairing));
        }

        $canViewAssessment = $role->allows(BranchPermission::EditAssessment) || $role->allows(BranchPermission::ReadQuotation);
        $repair->load(['receivedBy:id,name', 'technician:id,name', 'assignedBy:id,name']);
        if ($canViewAssessment) {
            $repair->load('latestAssessment.recordedBy:id,name');
        }

        return response()->view('repairs.show', [
            'branch' => $branch,
            'repair' => $repair,
            'technicians' => $technicians,
            'role' => $role,
            'canViewAssessment' => $canViewAssessment,
            'quotationForApproval' => $quotationForApproval,
            'isEnduFix' => $branch->usesEnduFixReceipt(),
            'nextStatuses' => $nextStatuses,
            'statusHistory' => $statusHistory,
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

        return response()->view('repairs.print', [
            'branch' => $branch->loadMissing('organization'),
            'repair' => $repair->load('receivedBy:id,name'),
            'isEnduFix' => $isEnduFix,
            'showUnlock' => $showUnlock,
        ])->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
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

            $technician = $branch->technicians()->where('user_id', $technicianId)->lockForUpdate()->first();

            if ($technician === null) {
                throw ValidationException::withMessages(['technician_id' => 'กรุณาเลือกช่างที่มีสิทธิ์ในสาขานี้']);
            }

            $job->technician()->associate($technician->user_id);
            $job->assignedBy()->associate($request->user());
            $job->assigned_at = now();
            $job->assignment_version++;
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
        ]);
        $next = RepairStatus::from($validated['next_status']);
        $note = $validated['workflow_note'] ?? null;

        DB::transaction(function () use ($request, $branch, $repair, $next, $note): void {
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
            abort_unless(in_array($next, $previous->nextStatuses($role), true), 403);

            if ($previous === RepairStatus::AwaitingApproval && $next === RepairStatus::Repairing && $job->quotations()->exists()) {
                throw ValidationException::withMessages(['next_status' => 'กรุณาบันทึกการอนุมัติจากหน้าใบเสนอราคาฉบับล่าสุด']);
            }

            if (($label = $previous->noteLabel($next)) !== null && $note === null) {
                throw ValidationException::withMessages(['workflow_note' => 'กรุณาระบุ'.$label]);
            }

            if ($next->isClosed()) {
                $job->price_change_assessment_id = null;
            }
            $job->moveTo($next, $request->user(), $note);
        }, 3);

        return redirect()->route('repairs.show', [$branch, $repair])->with('status', 'อัปเดตสถานะเป็น '.$next->label().' แล้ว');
    }
}
