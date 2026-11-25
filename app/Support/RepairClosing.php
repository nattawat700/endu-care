<?php

namespace App\Support;

use App\Enums\BranchPermission;
use App\Enums\RepairStatus;
use App\Models\RepairJob;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class RepairClosing
{
    public const RETURN_REASONS = [
        'customer_cancelled' => 'ลูกค้ายกเลิก',
        'unrepairable' => 'ซ่อมไม่ได้',
        'other' => 'เหตุผลอื่น',
    ];

    public static function record(RepairJob $job, RepairStatus $outcome, User $actor, array $fields): void
    {
        $amounts = RepairBilling::summary($job);
        if ($amounts['pending'] > 0 || $job->payments()->whereHas('refunds', fn ($refunds) => $refunds->whereIn('status', ['pending', 'approved']))->exists()) {
            throw ValidationException::withMessages(['next_status' => 'กรุณาจัดการรายการรับเงินหรือคืนเงินที่ค้างก่อนปิดใบงาน']);
        }

        $delivered = $outcome === RepairStatus::Delivered;
        $retention = $fields['retention_reason'] ?? null;
        if ($delivered && (($amounts['remaining'] ?? 0) !== 0 || ($amounts['payable'] === null && $amounts['paid'] > 0))) {
            throw ValidationException::withMessages(['next_status' => 'กรุณาตรวจยอดตกลง รับยอดคงเหลือ หรือคืนยอดเกินก่อนส่งมอบ']);
        }
        if (! $delivered && $amounts['paid'] > 0
            && ($retention === null || $actor->cannot('permission', [$job->branch, BranchPermission::ApproveDepositRetention]))) {
            throw ValidationException::withMessages(['retention_reason' => 'คืนเงินผ่านรายการรับเงินต้นทางให้ครบ หรือให้ผู้จัดการอนุมัติยอดที่คงไว้พร้อมเหตุผล']);
        }

        $now = now();
        $local = $now->copy()->timezone('Asia/Bangkok')->startOfDay();
        $days = $delivered ? (int) ($fields['warranty_days'] ?? 0) : 0;
        $payments = $job->payments()->where('status', 'confirmed')->latest('id')->limit(30)->get();
        $job->closure()->create([
            'closed_by' => $actor->id, 'outcome' => $outcome->value, 'closed_at' => $now,
            'details' => [
                'recipient' => $fields['recipient'] ?? $job->customer_name, 'device' => $job->device,
                'note' => $fields['workflow_note'] ?? null, 'collected' => $amounts['paid'], 'refunded' => $amounts['refunded'],
                'remaining' => $delivered ? $amounts['remaining'] : 0,
                'billing_state' => $delivered ? $amounts['state'] : ($amounts['paid'] > 0 ? 'คงยอดที่อนุมัติ' : 'ปิดงานส่งคืน'),
                'retention_reason' => ! $delivered && $amounts['paid'] > 0 ? $retention : null,
                'return_reason' => $delivered ? null : self::RETURN_REASONS[$fields['return_reason'] ?? 'other'],
                'closed_by' => $actor->name,

                'received_references' => $payments->map->reference()->all(),
                'warranty_days' => $days, 'warranty_start' => $days > 0 ? $local->toDateString() : null,
                'warranty_end' => $days > 0 ? $local->addDays($days - 1)->toDateString() : null,
                'warranty_terms' => $days > 0 ? ($fields['warranty_terms'] ?? null) : null,
            ],
        ]);
    }
}
