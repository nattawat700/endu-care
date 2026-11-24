<?php

namespace App\Models;

use App\Enums\ApprovalKind;
use App\Enums\BranchRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['submission_id', 'requested_by', 'kind', 'reference', 'amount', 'limit', 'review_role', 'status', 'reason', 'reviewed_by', 'reviewed_at', 'decision_reason'])]
class OrganizationApprovalRequest extends Model
{
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'pending' => 'รออนุมัติ',
            'approved' => 'อนุมัติแล้ว',
            'rejected' => 'ไม่อนุมัติ',
            'within_limit' => 'อยู่ในเพดาน',
        };
    }

    protected function casts(): array
    {
        return [
            'kind' => ApprovalKind::class, 'review_role' => BranchRole::class,
            'amount' => 'integer', 'limit' => 'integer', 'reviewed_at' => 'datetime',
        ];
    }
}
