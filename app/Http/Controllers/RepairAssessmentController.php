<?php

namespace App\Http\Controllers;

use App\Enums\BranchPermission;
use App\Enums\BranchRole;
use App\Enums\RepairStatus;
use App\Models\Branch;
use App\Models\RepairJob;
use App\Support\RepairPriceItems;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RepairAssessmentController extends Controller
{
    public function create(Request $request, Branch $branch, RepairJob $repair): Response
    {
        $repair->setRelation('branch', $branch);
        Gate::authorize('view', $repair);
        Gate::authorize('permission', [$branch, BranchPermission::EditAssessment]);
        abort_unless($repair->status->allowsQuotation(), 409, 'ใบงานนี้ยังไม่อยู่ในขั้นตอนประเมินราคา');
        $isTechnician = $branch->roleFor($request->user()) === BranchRole::Technician;

        return response()->view('repairs.assessment-create', [
            'branch' => $branch,
            'repair' => $repair->load('latestAssessment'),
            'canSend' => $isTechnician && $repair->status === RepairStatus::Diagnosing,
            'canRequestPriceChange' => $isTechnician && $this->canRequestPriceChange($repair),
            'submissionId' => (string) Str::uuid(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, Branch $branch, RepairJob $repair): RedirectResponse
    {
        $repair->setRelation('branch', $branch);
        Gate::authorize('view', $repair);
        Gate::authorize('permission', [$branch, BranchPermission::EditAssessment]);
        $fields = $request->validate([
            'submission_id' => ['required', 'uuid'],
            'assessment_revision' => ['required', 'integer', 'min:0'],
            'assignment_version' => ['required', 'integer', 'min:0'],
            'workflow_version' => ['required', 'integer', 'min:0'],
            'cause' => ['required', 'string', 'max:2000'],
            'repair_plan' => ['required', 'string', 'max:2000'],
            'expected_completion_at' => ['required', 'date_format:Y-m-d\TH:i', 'after_or_equal:1001-01-01'],
            'send_to_reception' => ['sometimes', 'boolean'],
            'request_price_change' => ['sometimes', 'boolean'],
            ...RepairPriceItems::rules(),
        ], [
            ...RepairPriceItems::messages(),
            'cause.required' => 'กรุณาระบุสาเหตุที่ตรวจพบ',
            'repair_plan.required' => 'กรุณาระบุวิธีซ่อมที่เสนอ',
            'expected_completion_at.required' => 'กรุณาระบุวันและเวลาคาดว่าจะซ่อมเสร็จ',
            'expected_completion_at.date_format' => 'กรุณาระบุวันและเวลาคาดว่าจะซ่อมเสร็จให้ถูกต้อง',
            'expected_completion_at.after_or_equal' => 'กรุณาระบุวันและเวลาคาดว่าจะซ่อมเสร็จให้ถูกต้อง',
        ]);

        $expectedCompletion = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $fields['expected_completion_at'], 'Asia/Bangkok')->utc();

        DB::transaction(function () use ($branch, $repair, $request, $fields, $expectedCompletion): void {
            $job = $branch->repairs()->whereKey($repair->id)->lockForUpdate()->firstOrFail();
            $job->setRelation('branch', $branch);
            Gate::authorize('view', $job);
            Gate::authorize('permission', [$branch, BranchPermission::EditAssessment]);

            if ($job->assessments()->where('submission_id', $fields['submission_id'])->exists()) {
                return;
            }

            abort_unless($job->status->allowsQuotation(), 409, 'ใบงานนี้ยังไม่อยู่ในขั้นตอนประเมินราคา');
            $revision = (int) $job->assessments()->max('revision');
            if ($revision !== (int) $fields['assessment_revision']
                || $job->assignment_version !== (int) $fields['assignment_version']
                || $job->workflow_version !== (int) $fields['workflow_version']) {
                throw ValidationException::withMessages(['assessment_revision' => 'ใบงานหรือผลประเมินมีการเปลี่ยนแปลง กรุณาโหลดข้อมูลล่าสุดก่อนบันทึก']);
            }

            $send = $request->boolean('send_to_reception');
            $requestPriceChange = $request->boolean('request_price_change');
            $isTechnician = $branch->roleFor($request->user()) === BranchRole::Technician;
            if ($send) {
                abort_unless($isTechnician && $job->status === RepairStatus::Diagnosing, 403);
            }
            if ($requestPriceChange) {
                abort_unless($isTechnician && $this->canRequestPriceChange($job), 403);
            }

            $items = RepairPriceItems::storeValues($fields['items']);
            $assessment = $job->assessments()->create([
                'revision' => $revision + 1,
                'submission_id' => $fields['submission_id'],
                'recorded_by' => $request->user()->id,
                'cause' => $fields['cause'],
                'repair_plan' => $fields['repair_plan'],
                'expected_completion_at' => $expectedCompletion,
                'items' => $items,
                'total_satang' => array_sum(array_column($items, 'total_satang')),
            ]);

            if ($requestPriceChange) {
                $job->price_change_assessment_id = $assessment->id;
                $job->moveTo(RepairStatus::AwaitingApproval, $request->user(), "แจ้งงานเพิ่มเติมจากผลประเมินครั้งที่ {$assessment->revision} และพักงานรออนุมัติราคาใหม่");
            } elseif ($send) {
                $job->moveTo(RepairStatus::AwaitingApproval, $request->user(), "ส่งผลตรวจและประเมินราคา ครั้งที่ {$assessment->revision} ให้หน้าร้าน");
            }
        }, 3);

        return redirect()->route('repairs.show', [$branch, $repair])->with('status', 'บันทึกผลตรวจและประเมินราคาแล้ว');
    }

    private function canRequestPriceChange(RepairJob $repair): bool
    {
        return $repair->status === RepairStatus::Repairing && $repair->quotations()->whereNotNull('approved_at')->exists();
    }
}
