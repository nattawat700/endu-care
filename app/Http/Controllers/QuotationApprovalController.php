<?php

namespace App\Http\Controllers;

use App\Enums\BranchPermission;
use App\Enums\QuotationDecision;
use App\Enums\RepairStatus;
use App\Models\Branch;
use App\Models\RepairJob;
use App\Models\RepairQuotation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class QuotationApprovalController extends Controller
{
    public function index(Branch $branch): Response
    {
        Gate::authorize('reviewQuotations', $branch);
        $allowedStatuses = array_filter(RepairStatus::cases(), fn (RepairStatus $status) => $status->allowsQuotation());
        $quotations = RepairQuotation::query()
            ->latestRevision()
            ->where('requires_internal_approval', true)
            ->whereNotNull('internal_requested_at')
            ->whereNull('internal_decision')
            ->whereHas('repairJob', fn ($jobs) => $jobs->whereBelongsTo($branch)->whereIn('status', $allowedStatuses))
            ->with(['repairJob:id', 'internalRequester:id,name'])
            ->orderBy('internal_requested_at')->orderBy('id')
            ->cursorPaginate(20);

        return response()->view('quotations.approval-queue', compact('branch', 'quotations'))
            ->header('Cache-Control', 'private, no-store');
    }

    public function submit(Request $request, Branch $branch, RepairJob $repair, RepairQuotation $quotation): RedirectResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::DraftQuotation]);
        $fields = $request->validate([
            'request_reason' => ['required', 'string', 'max:1500'],
        ], ['request_reason.required' => 'กรุณาระบุเหตุผลที่ส่งขออนุมัติ']);

        DB::transaction(function () use ($branch, $repair, $quotation, $request, $fields): void {
            $latest = $this->resolveForUpdate($branch, $repair, $quotation);
            if ($latest->internal_requested_at !== null) {
                return;
            }

            if ($latest->isExpired()) {
                throw ValidationException::withMessages(['review' => 'ใบเสนอราคาหมดอายุ กรุณาจัดทำฉบับแก้']);
            }

            $latest->internalRequester()->associate($request->user());
            $latest->internal_requested_at = now();
            $latest->internal_request_reason = $fields['request_reason'];
            $latest->save();
        }, 3);

        return redirect()->route('repairs.quotations.show', [$branch, $repair, $quotation])
            ->with('status', 'บันทึกคำขออนุมัติแล้ว');
    }

    public function decide(Request $request, Branch $branch, RepairJob $repair, RepairQuotation $quotation): RedirectResponse
    {
        Gate::authorize('reviewQuotations', $branch);
        $fields = $request->validate([
            'decision' => ['required', Rule::enum(QuotationDecision::class)],
            'decision_reason' => ['required', 'string', 'max:1500'],
            'signature' => ['nullable', 'prohibited_unless:decision,approved', 'image', 'mimes:png,jpg,jpeg,webp',
                'max:256', 'dimensions:max_width=1200,max_height=800'],

        ], [
            'decision.required' => 'กรุณาเลือกผลการพิจารณา',
            'decision_reason.required' => 'กรุณาระบุเหตุผลของการตัดสินใจ',
        ]);
        $decision = QuotationDecision::from($fields['decision']);

        $signature = $request->file('signature');
        $filename = $signature ? Str::uuid().'.'.$signature->extension() : null;
        $directory = $quotation->signatureDirectory($branch);
        if ($signature && $signature->storeAs($directory, $filename, 'local') === false) {
            throw ValidationException::withMessages(['signature' => 'จัดเก็บลายเซ็นไม่สำเร็จ']);
        }

        try {
            DB::transaction(function () use ($branch, $repair, $quotation, $request, $decision, $fields, $filename): void {
                $latest = $this->resolveForUpdate($branch, $repair, $quotation);
                if ($latest->internal_requested_at === null) {
                    throw ValidationException::withMessages(['review' => 'ใบเสนอราคาฉบับนี้ยังไม่ได้ส่งขออนุมัติ']);
                }

                if ($latest->internal_decision !== null) {
                    throw ValidationException::withMessages(['review' => 'ใบเสนอราคาฉบับนี้มีผลการพิจารณาแล้ว']);
                }

                if ($decision === QuotationDecision::Approved && $latest->isExpired()) {
                    throw ValidationException::withMessages(['review' => 'ใบเสนอราคาหมดอายุ กรุณาจัดทำฉบับแก้']);
                }

                $latest->internalReviewer()->associate($request->user());
                $latest->internal_decision = $decision;
                $latest->internal_decided_at = now();
                $latest->internal_decision_reason = $fields['decision_reason'];
                $latest->signature_filename = $filename;
                $latest->save();
            }, 3);
        } catch (Throwable $exception) {
            if ($filename !== null) {
                Storage::disk('local')->delete($directory.'/'.$filename);
            }

            throw $exception;
        }

        return redirect()->route('repairs.quotations.show', [$branch, $repair, $quotation])
            ->with('status', $decision->label());
    }

    private function resolveForUpdate(Branch $branch, RepairJob $repair, RepairQuotation $quotation): RepairQuotation
    {
        $job = $branch->repairs()->whereKey($repair->id)->lockForUpdate()->firstOrFail();
        $latest = $job->quotations()->orderByDesc('revision')->firstOrFail();
        if (! $latest->is($quotation) || ! $latest->requires_internal_approval || ! $job->status->allowsQuotation()) {
            throw ValidationException::withMessages(['review' => 'ใบเสนอราคาฉบับนี้ไม่พร้อมสำหรับการพิจารณา กรุณาตรวจฉบับล่าสุดและสถานะใบงาน']);
        }

        return $latest;
    }
}
