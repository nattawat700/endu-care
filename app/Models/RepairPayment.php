<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'branch_id', 'repair_quotation_id', 'received_by', 'submission_id', 'channel', 'document_type',
    'job_total_satang', 'payable_satang', 'paid_before_satang', 'amount_satang',
    'transfer_reference', 'slip_filename', 'slip_type', 'slip_digest', 'document',
    'cash_shift_id', 'verified_by', 'verified_at', 'status', 'review_note',
])]
#[Hidden(['document', 'transfer_reference', 'slip_filename', 'slip_type', 'slip_digest'])]
class RepairPayment extends Model
{
    public const TYPES = [
        'deposit' => ['ใบรับเงินมัดจำ', 'Deposit Receipt', 'DEP'],
        'receipt' => ['ใบเสร็จรับเงิน', 'Receipt', 'RC'],
        'cash_receipt' => ['ใบเสร็จเงินสด', 'Cash Receipt', 'CS'],
    ];

    public const CHANNELS = ['cash' => 'เงินสด', 'transfer' => 'โอนเงิน', 'qr' => 'QR หน้าร้าน', 'other' => 'ช่องทางเพิ่มเติม', 'mixed' => 'หลายช่องทาง'];

    public const STATUSES = ['confirmed' => 'ยืนยันแล้ว', 'pending' => 'รอตรวจยอด', 'rejected' => 'ไม่ผ่านการตรวจ'];

    public function lines(): HasMany
    {
        return $this->hasMany(RepairPaymentLine::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(RepairRefund::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(CashShift::class, 'cash_shift_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function repairJob(): BelongsTo
    {
        return $this->belongsTo(RepairJob::class);
    }

    public static function slipDirectory(Branch $branch): string
    {
        return 'payment-slips/'.$branch->organization_id.'/'.$branch->id;
    }

    public function title(): string
    {
        return self::TYPES[$this->document_type][0];
    }

    public function reference(): string
    {
        return self::TYPES[$this->document_type][2].'-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public function paymentReference(): string
    {
        return 'PAY-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public function remainingSatang(): int
    {
        return $this->payable_satang - $this->paid_before_satang - $this->amount_satang;
    }

    protected function casts(): array
    {
        return [
            'document' => 'encrypted:array',
            'verified_at' => 'datetime',
            'job_total_satang' => 'integer',
            'payable_satang' => 'integer',
            'paid_before_satang' => 'integer',
            'amount_satang' => 'integer',
        ];
    }
}
