<?php

namespace App\Support;

use App\Enums\ApprovalKind;
use App\Enums\BranchRole;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\PosSale;
use App\Models\User;
use Illuminate\Support\Str;

class PosDiscount
{
    public static function rule(Branch $branch): array
    {
        return $branch->organization->approval_rules['discount'] ?? ['limit' => 0, 'review_role' => BranchRole::Manager->value];
    }

    public static function lockRules(Branch $branch): void
    {
        $organization = Organization::whereKey($branch->organization_id)->lockForUpdate()->firstOrFail();
        abort_if($organization->suspended_at !== null, 404);
        $branch->setRelation('organization', $organization);
    }

    public static function request(Branch $branch, PosSale $sale, User $actor, string $reason): void
    {
        $rule = self::rule($branch);
        $amount = $sale->discountTotal();
        if ($amount <= $rule['limit']) {
            PosCart::invalid('reason', 'ส่วนลดอยู่ในเพดานที่ร้านกำหนด');
        }
        if ($sale->approval && $sale->approval->review_role->value === $rule['review_role']
            && in_array($sale->approval->status, ['pending', 'approved'], true)) {
            return;
        }
        if (! $branch->memberships()->where('role', $rule['review_role'])->whereNotIn('user_id', [$actor->id, $sale->created_by])
            ->whereHas('user', fn ($query) => $query->whereNull('suspended_at'))->exists()) {
            PosCart::invalid('reason', 'สาขายังไม่มีผู้อนุมัติคนอื่นตามบทบาทที่กำหนดในกิจการ');
        }
        self::withdraw($sale);
        $approval = $branch->approvalRequests()->create([
            'submission_id' => (string) Str::uuid(), 'kind' => ApprovalKind::Discount, 'requested_by' => $actor->id,
            'reference' => $sale->reference().' / ฉบับ '.$sale->version, 'reason' => $reason,
            'review_role' => $rule['review_role'], 'status' => 'pending', 'limit' => $rule['limit'], 'amount' => $amount,
        ]);
        $sale->update(['approval_id' => $approval->id]);
    }

    public static function withdraw(PosSale $sale): void
    {
        if ($sale->approval?->status === 'pending') {
            $sale->approval->update(['status' => 'withdrawn']);
        }
    }

    public static function canCheckout(Branch $branch, PosSale $sale): bool
    {
        $rule = self::rule($branch);
        $approval = $sale->approval;

        return $sale->discountTotal() <= $rule['limit']
            || ($approval?->status === 'approved' && $approval->amount === $sale->discountTotal()
                && $approval->review_role->value === $rule['review_role']
                && (int) $approval->reviewed_by !== (int) $sale->created_by);
    }
}
