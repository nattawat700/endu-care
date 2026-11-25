<?php

namespace App\Http\Controllers;

use App\Enums\BranchPermission;
use App\Enums\RepairStatus;
use App\Models\Branch;
use App\Models\RepairJob;
use App\Models\RepairQuotation;
use App\Support\DocumentPdf;
use App\Support\DocumentPrintHistory;
use App\Support\QuotationAmounts;
use App\Support\QuotationDetails;
use App\Support\RepairBilling;
use App\Support\RepairPriceItems;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RepairQuotationController extends Controller
{
    public function index(Request $request, Branch $branch, RepairJob $repair): Response
    {
        Gate::authorize('permission', [$branch, BranchPermission::ReadQuotation]);
        Gate::authorize('permission', [$branch, BranchPermission::CustomerData]);

        return response()->view('quotations.index', [
            'branch' => $branch,
            'repair' => $repair,
            'latest' => $repair->quotations()->orderByDesc('revision')->first(),
            'canDraftQuote' => $request->user()->can('permission', [$branch, BranchPermission::DraftQuotation]),
            'history' => $repair->quotations()->orderByDesc('revision')->cursorPaginate(15),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function create(Request $request, Branch $branch, RepairJob $repair): Response
    {
        Gate::authorize('permission', [$branch, BranchPermission::DraftQuotation]);
        Gate::authorize('permission', [$branch, BranchPermission::CustomerData]);
        abort_unless($repair->status->allowsQuotation(), 409, 'ใบงานนี้ยังไม่อยู่ในขั้นตอนออกใบเสนอราคา');
        $latest = $repair->quotations()->with('assessment:id,expected_completion_at')->orderByDesc('revision')->first();
        $assessment = $repair->latestAssessment;
        $useAssessment = $latest === null || $request->boolean('from_assessment')
            || ! $repair->coversPriceChange($latest->repair_assessment_id);

        return response()->view('quotations.create', [
            'branch' => $branch,
            'repair' => $repair,
            'latest' => $latest,
            'latestAssessment' => $assessment,
            'sourceAssessment' => $useAssessment ? $assessment : null,
            'comparisonQuote' => $latest ? $this->comparisonQuote($repair, $latest->revision + 1) : null,
            'submissionId' => (string) Str::uuid(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, Branch $branch, RepairJob $repair): RedirectResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::DraftQuotation]);
        Gate::authorize('permission', [$branch, BranchPermission::CustomerData]);
        $fields = $request->validate([
            'submission_id' => ['required', 'uuid'],
            'base_revision' => ['required', 'integer', 'min:0'],
            'assessment_revision' => ['nullable', 'integer', 'min:1'],
            'revision_reason' => ['sometimes', 'required', 'string', 'max:1500'],
            'terms' => ['required', 'string', 'max:3000'],
            'valid_until' => ['nullable', 'date_format:Y-m-d'],
            ...QuotationDetails::rules(),
            ...QuotationAmounts::rules(),
            ...RepairPriceItems::rules(forQuotation: true),
        ], [
            ...RepairPriceItems::messages(),
            'terms.required' => 'กรุณาระบุเงื่อนไขที่จะให้ลูกค้ายืนยัน',
            'revision_reason.required' => 'กรุณาระบุเหตุผลที่ออกใบเสนอราคาฉบับแก้',
        ]);
        $items = RepairPriceItems::storeValues($fields['items'], forQuotation: true);
        $amounts = QuotationAmounts::calculate($items, $fields);
        $details = array_intersect_key($fields, QuotationDetails::rules());
        $details += ['buyer_phone' => $repair->customer_phone, 'buyer_address' => $repair->customer_address];
        unset($details['buyer_name']);

        $quotation = DB::transaction(function () use ($branch, $repair, $request, $fields, $items, $amounts, $details): RepairQuotation {
            $job = $branch->repairs()->whereKey($repair->id)->lockForUpdate()->firstOrFail();
            $existing = $job->quotations()->where('submission_id', $fields['submission_id'])->first();
            if ($existing !== null) {
                return $existing;
            }

            if (isset($fields['valid_until']) && $fields['valid_until'] < now('Asia/Bangkok')->toDateString()) {
                throw ValidationException::withMessages(['valid_until' => 'วันหมดอายุต้องเป็นวันนี้หรือหลังจากวันนี้']);
            }
            abort_unless($job->status->allowsQuotation(), 409, 'ใบงานนี้ยังไม่อยู่ในขั้นตอนออกใบเสนอราคา');
            $latest = $job->quotations()->orderByDesc('revision')->first();
            $currentRevision = $latest?->revision ?? 0;
            if ($currentRevision !== (int) $fields['base_revision']) {
                throw ValidationException::withMessages(['base_revision' => 'มีใบเสนอราคาฉบับใหม่แล้ว กรุณาเปิดฉบับล่าสุดก่อนออกฉบับแก้']);
            }

            $assessmentId = $latest?->repair_assessment_id;
            if (isset($fields['assessment_revision'])) {
                $assessment = $job->latestAssessment;
                if ($assessment?->revision !== (int) $fields['assessment_revision']) {
                    throw ValidationException::withMessages(['assessment_revision' => 'ผลประเมินมีการเปลี่ยนแปลง กรุณาโหลดรายการจากผลประเมินล่าสุด']);
                }
                $assessmentId = $assessment->id;
            }
            if (! $job->coversPriceChange($assessmentId)) {
                throw ValidationException::withMessages(['assessment_revision' => 'ใบงานกำลังรออนุมัติงานเพิ่ม กรุณาโหลดผลประเมินล่าสุดก่อนบันทึก']);
            }

            $quotation = $job->quotations()->create([
                'revision' => $currentRevision + 1,
                'submission_id' => $fields['submission_id'],
                'organization_name' => $branch->organization->name,
                'branch_name' => $branch->name,
                'seller_profile' => $branch->documentSeller(),
                'customer_name' => $fields['buyer_name'] ?? $job->customer_name,
                'device' => $job->device,
                'issued_by' => $request->user()->id,
                'issuer_name' => $request->user()->name,
                'items' => $items,
                'total_satang' => $amounts['grand_total_satang'],
                'amount_breakdown' => $amounts,
                'document_details' => $details,
                'valid_until' => $fields['valid_until'] ?? null,
                'terms' => $fields['terms'],
                'requires_internal_approval' => true,
                'repair_assessment_id' => $assessmentId,
                'revision_reason' => $latest ? ($fields['revision_reason'] ?? null) : null,
            ]);

            if ($job->status === RepairStatus::Repairing) {
                $job->moveTo(RepairStatus::AwaitingApproval, $request->user(), 'จัดทำใบเสนอราคาฉบับแก้ '.$quotation->reference().' เพื่อขออนุมัติอีกครั้ง');
            }

            return $quotation;
        }, 3);

        return redirect()->route('repairs.quotations.show', [$branch, $repair, $quotation])->with('status', 'บันทึกร่างใบเสนอราคาแล้ว');
    }

    public function show(Request $request, Branch $branch, RepairJob $repair, RepairQuotation $quotation): Response
    {
        Gate::authorize('permission', [$branch, BranchPermission::ReadQuotation]);
        Gate::authorize('permission', [$branch, BranchPermission::CustomerData]);
        $role = $branch->roleFor($request->user());
        $latest = $quotation->revision === (int) $repair->quotations()->max('revision');
        $allowsReview = $latest && $quotation->voided_at === null && $quotation->requires_internal_approval && $repair->status->allowsQuotation();

        return response()->view('quotations.show', [
            'branch' => $branch,
            'repair' => $repair,
            'quotation' => $quotation->load(['voidedBy:id,name', 'approvedBy:id,name', 'customerApprovalRequester:id,name', 'internalRequester:id,name', 'internalReviewer:id,name', 'assessment:id,revision,expected_completion_at']),
            'comparisonQuote' => $quotation->revision > 1 ? $this->comparisonQuote($repair, $quotation->revision) : null,
            'isLatest' => $latest,
            'canCancelQuote' => $latest && $quotation->canRelease() && $repair->status->allowsQuotation()
                && $role->allows(BranchPermission::CancelIssuedQuotation),
            'canDraftQuote' => $role->allows(BranchPermission::DraftQuotation),
            'canPrintQuote' => $role->allows(BranchPermission::PrintQuotation),
            'canViewPrivateCustomerDetails' => $role->allows(BranchPermission::PrivateCustomerDetails),
            'canViewInternalNotes' => $role->allows(BranchPermission::InternalNotes),
            'canApprove' => $role->allows(BranchPermission::ConfirmCustomerPrice) && $latest && $quotation->canRelease() && $quotation->approved_at === null && $repair->status === RepairStatus::AwaitingApproval,
            'canRequestReview' => $role->allows(BranchPermission::DraftQuotation) && ! $quotation->isExpired() && $allowsReview && $quotation->internal_requested_at === null,
            'canDecide' => $allowsReview && $quotation->internal_requested_at !== null && $quotation->internal_decision === null
                && $request->user()->can('reviewQuotations', $branch),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function void(Request $request, Branch $branch, RepairJob $repair, RepairQuotation $quotation): RedirectResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::CancelIssuedQuotation]);
        $fields = $request->validate(['void_reason' => ['required', 'string', 'max:500']]);

        DB::transaction(function () use ($request, $branch, $repair, $quotation, $fields): void {
            $branch = Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('permission', [$branch, BranchPermission::CancelIssuedQuotation]);
            $job = $branch->repairs()->whereKey($repair->id)->lockForUpdate()->firstOrFail();
            $current = $job->quotations()->orderByDesc('revision')->lockForUpdate()->firstOrFail();
            abort_unless($current->is($quotation), 409, 'กรุณาจัดการใบเสนอราคาฉบับล่าสุด');
            if ($current->voided_at !== null) {
                return;
            }
            abort_unless($current->canRelease() && $job->status->allowsQuotation(), 409, 'ใบเสนอราคาหรือสถานะใบงานนี้ยังยกเลิกไม่ได้');

            $billing = RepairBilling::summary($job);
            if ($billing['paid'] > 0 || $billing['pending'] > 0) {
                throw ValidationException::withMessages(['void_reason' => 'กรุณาจัดการยอดที่รอตรวจและคืนยอดที่รับไว้ หรือออกใบเสนอราคาฉบับแก้']);
            }

            $current->voidedBy()->associate($request->user());
            $current->voided_at = now();
            $current->void_reason = $fields['void_reason'];
            $current->save();

            if ($job->status === RepairStatus::Repairing) {
                $job->moveTo(RepairStatus::AwaitingApproval, $request->user(), 'ยกเลิกใบเสนอราคา '.$current->reference());
            }
        }, 3);

        return redirect()->route('repairs.quotations.show', [$branch, $repair, $quotation])
            ->with('status', 'ยกเลิกใบเสนอราคาแล้ว');
    }

    public function print(Request $request, Branch $branch, RepairJob $repair, RepairQuotation $quotation): Response
    {
        Gate::authorize('permission', [$branch, BranchPermission::PrintQuotation]);
        Gate::authorize('permission', [$branch, BranchPermission::CustomerData]);
        abort_unless($quotation->wasReleased(), 409);

        $data = [
            'branch' => $branch, 'repair' => $repair,
            'quotation' => $quotation->load(['assessment:id,revision,expected_completion_at', 'internalReviewer:id,name']),
            'isLatest' => $quotation->revision === (int) $repair->quotations()->max('revision'),
            'canPrintQuote' => true,
            'canViewPrivateCustomerDetails' => $branch->roleFor(auth()->user())->allows(BranchPermission::PrivateCustomerDetails),
        ];

        if ($request->query('format') === 'pdf') {
            $pdf = app(DocumentPdf::class)->render('quotations.print', $data, $quotation->reference());

            return DocumentPrintHistory::record($pdf, $branch, 'quotation', $quotation->id, $quotation->reference());
        }

        return response()->view('quotations.print', $data)->header('Cache-Control', 'private, no-store');
    }

    private function comparisonQuote(RepairJob $repair, int $revision): ?RepairQuotation
    {
        $priorQuotes = $repair->quotations()->where('revision', '<', $revision)->orderByDesc('revision');
        $columns = ['id', 'repair_job_id', 'revision', 'total_satang', 'approved_at', 'voided_at'];

        return (clone $priorQuotes)->whereNotNull('approved_at')->whereNull('voided_at')->first($columns) ?? $priorQuotes->first($columns);
    }
}
