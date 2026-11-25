<?php

namespace App\Models;

use App\Enums\BranchRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'organization_id', 'branch_id', 'actor_id', 'actor_name', 'subject_type', 'subject_reference', 'operation',
    'before_values', 'after_values', 'protected_changes', 'occurred_at',
])]
class AuditEvent extends Model
{
    public const SUBJECTS = [
        'repair' => 'ใบรับซ่อม', 'assessment' => 'ผลประเมิน', 'quotation' => 'ใบเสนอราคา',
        'cash_shift' => 'กะรับเงิน', 'cash_shift_movement' => 'เงินเข้า–ออกลิ้นชัก', 'repair_refund' => 'คืนเงินใบงาน',
        'repair_payment' => 'เอกสารรับเงิน', 'issued_document' => 'เอกสารงานบริการ',
        'document_series' => 'ชุดเลขเอกสาร', 'service_claim' => 'รายการเคลม', 'repair_closure' => 'บันทึกปิดใบงาน',
        'repair_photo' => 'รูปหลักฐานงานซ่อม', 'repair_quality_check' => 'ผลตรวจ QC',
        'catalog_product' => 'ทะเบียนสินค้า', 'catalog_warehouse' => 'คลังสินค้า',
        'catalog_location' => 'ตำแหน่งเก็บ',
        'catalog_movement' => 'ความเคลื่อนไหวคลัง',
        'pos_sale' => 'บิลขายหน้าร้าน',
        'integration_connection' => 'การเชื่อมต่อ', 'integration_import' => 'นำเข้าข้อมูล',
        'customer' => 'ลูกค้า', 'customer_device' => 'เครื่องลูกค้า', 'customer_appointment' => 'นัดหมาย',
        'organization' => 'กิจการ', 'branch' => 'สาขา', 'approval' => 'คำขออนุมัติรายการ',
        'payment_point' => 'จุดรับเงิน', 'printer' => 'โปรไฟล์เครื่องพิมพ์', 'counter' => 'เคาน์เตอร์',
        'membership' => 'สิทธิ์สาขา', 'staff_account' => 'บัญชีพนักงาน', 'invitation' => 'คำเชิญพนักงาน',
    ];

    public const OPERATIONS = [
        'created' => 'สร้าง', 'updated' => 'เปลี่ยนแปลง', 'deleted' => 'ถอนหรือยกเลิก',
        'suspend' => 'ระงับบัญชี', 'restore' => 'เปิดใช้บัญชี', 'revoke_sessions' => 'ยุติเซสชัน',
    ];

    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit events are append-only.'));
        static::deleting(fn () => throw new LogicException('Audit events are append-only.'));
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public static function branchRoles(): array
    {
        return [BranchRole::Manager, BranchRole::Owner, BranchRole::Hq];
    }

    public static function organizationRoles(): array
    {
        return [BranchRole::Owner, BranchRole::Hq];
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $branchIds = $user->activeBranchMemberships()->whereIn('role', self::branchRoles())->select('branch_id');
        $organizationIds = Branch::whereHas('memberships', fn ($memberships) => $memberships
            ->whereBelongsTo($user)->whereIn('role', self::organizationRoles()))->select('organization_id');

        return $query->where(fn ($scope) => $scope->whereIn('branch_id', $branchIds)
            ->orWhere(fn ($organization) => $organization->whereNull('branch_id')->whereIn('organization_id', $organizationIds)));
    }

    protected function casts(): array
    {
        return [
            'before_values' => 'array', 'after_values' => 'array',
            'protected_changes' => 'array', 'occurred_at' => 'immutable_datetime',
        ];
    }
}
