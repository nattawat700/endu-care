<?php

namespace App\Models;

use App\Enums\ApprovalKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'branch_id', 'repair_payment_line_id', 'cash_shift_id', 'requested_by', 'reviewed_by', 'paid_by',
    'submission_id', 'channel', 'amount_satang', 'reason', 'review_note', 'review_role',
    'status', 'proof_filename', 'proof_type', 'reviewed_at', 'paid_at',
])]
#[Hidden(['proof_filename', 'proof_type'])]
class RepairRefund extends Model
{
    public const STATUSES = ['pending' => 'รออนุมัติ', 'approved' => 'อนุมัติแล้ว · รอคืนจริง', 'rejected' => 'ไม่อนุมัติ', 'completed' => 'คืนเงินแล้ว'];

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function canReview(Branch $branch, User $user): bool
    {
        $role = $branch->roleFor($user);

        return (int) $this->requested_by !== $user->id && in_array($role, ApprovalKind::reviewRoles(), true)
            && ($this->review_role === null || $this->review_role === $role->value);
    }

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime', 'paid_at' => 'datetime'];
    }
}
