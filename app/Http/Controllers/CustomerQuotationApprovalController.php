<?php

namespace App\Http\Controllers;

use App\Enums\BranchPermission;
use App\Enums\RepairStatus;
use App\Models\Branch;
use App\Models\RepairJob;
use App\Models\RepairQuotation;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CustomerQuotationApprovalController extends Controller
{
    public function requestApproval(Request $request, Branch $branch, RepairJob $repair, RepairQuotation $quotation): RedirectResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::ConfirmCustomerPrice]);
        $fields = $request->validate([
            'request_channel' => ['required', Rule::in(array_keys(RepairQuotation::APPROVAL_CHANNELS))],
        ], [
            'request_channel.required' => 'กรุณาเลือกช่องทางที่ส่งใบเสนอราคาให้ลูกค้า',
            'request_channel.in' => 'กรุณาเลือกช่องทางที่ส่งใบเสนอราคาให้ถูกต้อง',
        ]);

        DB::transaction(function () use ($branch, $repair, $quotation, $request, $fields): void {
            [$job, $latest] = $this->lockCurrentQuotation($branch, $repair, $quotation, 'request_channel');

            if ($latest->customer_requested_at !== null) {
                return;
            }

            if ($latest->approved_at !== null || ! $latest->canRelease() || $job->status !== RepairStatus::AwaitingApproval) {
                throw ValidationException::withMessages([
                    'request_channel' => 'ส่งขออนุมัติได้เมื่อราคาได้รับอนุมัติภายในและใบงานอยู่ในสถานะรอลูกค้าอนุมัติ',
                ]);
            }

            if ($latest->isExpired()) {
                throw ValidationException::withMessages(['request_channel' => 'ใบเสนอราคาหมดอายุ กรุณาออกฉบับแก้ก่อนส่งให้ลูกค้า']);
            }

            $latest->customerApprovalRequester()->associate($request->user());
            $latest->customer_requested_at = now();
            $latest->customer_request_channel = $fields['request_channel'];
            $latest->save();
        }, 3);

        return redirect()->route('repairs.quotations.show', [$branch, $repair, $quotation])
            ->with('status', 'บันทึกการส่งขออนุมัติจากลูกค้าแล้ว');
    }

    public function approve(Request $request, Branch $branch, RepairJob $repair, RepairQuotation $quotation): RedirectResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::ConfirmCustomerPrice]);
        $fields = $request->validate([
            'approval_channel' => ['required', Rule::in(array_keys(RepairQuotation::APPROVAL_CHANNELS))],
            'approval_note' => ['nullable', 'string', 'max:1500'],
            'customer_approver_name' => ['sometimes', 'required', 'string', 'max:150'],
            'approval_received_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'confirmation' => ['accepted'],
        ], [
            'approval_channel.required' => 'กรุณาเลือกช่องทางที่ลูกค้ายืนยัน',
            'approval_channel.in' => 'กรุณาเลือกช่องทางที่ลูกค้ายืนยันให้ถูกต้อง',
            'customer_approver_name.required' => 'กรุณาระบุชื่อผู้ยืนยันจากฝั่งลูกค้า',
            'approval_received_at.date_format' => 'กรุณาระบุวันและเวลาที่ลูกค้ายืนยันให้ถูกต้อง',
            'confirmation.accepted' => 'กรุณายืนยันว่าลูกค้าอนุมัติยอดและเงื่อนไขของฉบับนี้',
        ]);

        DB::transaction(function () use ($branch, $repair, $quotation, $request, $fields): void {
            [$job, $latest] = $this->lockCurrentQuotation($branch, $repair, $quotation, 'confirmation');

            if ($latest->approved_at !== null) {
                return;
            }

            if (! $latest->canRelease()) {
                throw ValidationException::withMessages(['confirmation' => 'ใบเสนอราคาต้องผ่านการอนุมัติจากผู้จัดการก่อนบันทึกการยืนยันจากลูกค้า']);
            }

            if ($job->status !== RepairStatus::AwaitingApproval) {
                throw ValidationException::withMessages(['confirmation' => 'ใบงานต้องอยู่ในสถานะรอลูกค้าอนุมัติก่อนยืนยันราคา']);
            }

            $recordedAt = now();
            $confirmedAt = null;
            if (isset($fields['approval_received_at'])) {
                $confirmedAt = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $fields['approval_received_at'], 'Asia/Bangkok')->utc();
                $releasedAt = ($latest->internal_decided_at ?? $latest->created_at)->copy()->startOfMinute();

                if ($confirmedAt->lt($releasedAt) || $confirmedAt->gt($recordedAt)) {
                    throw ValidationException::withMessages([
                        'approval_received_at' => 'เวลาที่ลูกค้ายืนยันต้องอยู่ระหว่างเวลาที่ใบเสนอราคาพร้อมออกกับเวลาปัจจุบัน',
                    ]);
                }
            }

            if ($latest->isExpired($confirmedAt ?? $recordedAt)) {
                throw ValidationException::withMessages(['confirmation' => 'วันที่ลูกค้ายืนยันเกินวันหมดอายุของใบเสนอราคา กรุณาจัดทำฉบับแก้']);
            }

            $latest->approvedBy()->associate($request->user());
            $latest->approved_at = $recordedAt;
            $latest->customer_confirmed_at = $confirmedAt ?? $recordedAt;
            $latest->customer_approver_name = $fields['customer_approver_name'] ?? null;
            $latest->approval_channel = $fields['approval_channel'];
            $latest->approval_note = $fields['approval_note'] ?? null;
            $latest->save();

            $channel = RepairQuotation::APPROVAL_CHANNELS[$latest->approval_channel];
            $job->price_change_assessment_id = null;
            $job->moveTo(RepairStatus::Repairing, $request->user(), "ลูกค้าอนุมัติใบเสนอราคา {$latest->reference()} ผ่าน{$channel}");
        }, 3);

        return redirect()->route('repairs.quotations.show', [$branch, $repair, $quotation])
            ->with('status', 'บันทึกการยืนยันของลูกค้าแล้ว');
    }

    /** @return array{RepairJob, RepairQuotation} */
    private function lockCurrentQuotation(Branch $branch, RepairJob $repair, RepairQuotation $quotation, string $errorField): array
    {
        $job = $branch->repairs()->whereKey($repair->id)->lockForUpdate()->firstOrFail();
        $latest = $job->quotations()->orderByDesc('revision')->firstOrFail();

        if (! $latest->is($quotation)) {
            throw ValidationException::withMessages([$errorField => 'มีใบเสนอราคาฉบับใหม่แล้ว กรุณาเปิดฉบับล่าสุดก่อนบันทึก']);
        }

        if (! $job->coversPriceChange($latest->repair_assessment_id)) {
            throw ValidationException::withMessages([$errorField => 'มีงานเพิ่มเติมที่รออนุมัติ กรุณาจัดทำใบเสนอราคาจากผลประเมินใหม่']);
        }

        return [$job, $latest];
    }
}
