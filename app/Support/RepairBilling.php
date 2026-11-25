<?php

namespace App\Support;

use App\Enums\RepairStatus;
use App\Models\RepairJob;
use App\Models\RepairRefund;

class RepairBilling
{
    public static function summary(RepairJob $repair): array
    {
        $latest = $repair->quotations()->orderByDesc('revision')->first();
        $quotation = $latest !== null && $latest->approved_at !== null && $latest->canRelease()
            && $repair->coversPriceChange($latest->repair_assessment_id) ? $latest : null;
        $total = $quotation?->total_satang;
        if ($latest === null && $repair->coversPriceChange(null)) {
            $total = $repair->fixed_price_satang;
        }
        $payable = $quotation->amount_breakdown['payable_satang'] ?? $total;
        $canReceive = $payable !== null && $repair->status !== RepairStatus::ReturnedUnrepaired;
        $confirmed = (int) $repair->payments()->where('status', 'confirmed')->sum('amount_satang');
        $refunded = (int) RepairRefund::where('status', 'completed')
            ->whereIn('repair_payment_id', $repair->payments()->select('id'))->sum('amount_satang');
        $paid = $confirmed - $refunded;
        $pending = (int) $repair->payments()->where('status', 'pending')->sum('amount_satang');
        $closedWithoutRepair = $repair->status === RepairStatus::ReturnedUnrepaired;
        $remaining = match (true) {
            $closedWithoutRepair => 0,
            $payable !== null => $payable - $paid,
            default => null,
        };
        $creditDays = $quotation->document_details['credit_days'] ?? null;
        $due = $creditDays === null ? null : $quotation->approved_at->copy()->timezone('Asia/Bangkok')->startOfDay()->addDays((int) $creditDays);
        $state = match (true) {
            $pending > 0 => 'รอตรวจยอด',
            $refunded > 0 && $paid === 0 => 'คืนเงินครบ',
            $closedWithoutRepair && $paid > 0 => 'ปิดงาน · คงยอดที่รับไว้',
            $closedWithoutRepair => 'ปิดงานส่งคืน',
            $remaining === null => 'รอยอดที่ตกลง',
            $remaining < 0 => 'รับเงินเกิน',
            $remaining === 0 => 'ชำระครบ',
            $due !== null && $due->lt(now('Asia/Bangkok')->startOfDay()) => 'ค้างรับ',
            $paid > 0 => 'ชำระบางส่วน',
            default => 'ยังไม่ชำระ',
        };
        $version = hash('sha256', json_encode([$repair->id, $quotation?->id, $total, $payable, $confirmed, $refunded, $pending, $canReceive], JSON_THROW_ON_ERROR));

        return compact('quotation', 'total', 'payable', 'canReceive', 'confirmed', 'paid', 'pending', 'refunded', 'remaining', 'due', 'state', 'version');
    }

    public static function deposits(RepairJob $repair): array
    {
        $query = $repair->payments()->where('document_type', 'deposit');
        $totals = (clone $query)->whereIn('status', ['confirmed', 'pending'])
            ->selectRaw('status, SUM(amount_satang) AS total')->groupBy('status')->pluck('total', 'status');
        $received = (int) ($totals['confirmed'] ?? 0);
        $refunds = (int) RepairRefund::where('status', 'completed')
            ->whereIn('repair_payment_id', (clone $query)->select('id'))->sum('amount_satang');
        $pending = (int) ($totals['pending'] ?? 0);
        $receipts = $query->where('status', 'confirmed')->latest('id')
            ->cursorPaginate(5, ['id', 'document_type', 'amount_satang', 'channel', 'created_at', 'verified_at'], 'deposit_receipts');

        return compact('received', 'refunds', 'pending', 'receipts');
    }
}
