<?php

namespace App\Observers;

use App\Enums\ApprovalKind;
use App\Enums\BranchRole;
use App\Enums\RepairStatus;
use App\Models\AuditEvent;
use App\Models\Branch;
use App\Models\BranchCounter;
use App\Models\BranchMembership;
use App\Models\BranchPaymentPoint;
use App\Models\BranchPrinterProfile;
use App\Models\CashShift;
use App\Models\CashShiftMovement;
use App\Models\CatalogLocation;
use App\Models\CatalogMovement;
use App\Models\CatalogProduct;
use App\Models\CatalogWarehouse;
use App\Models\Customer;
use App\Models\CustomerAppointment;
use App\Models\CustomerDevice;
use App\Models\IntegrationConnection;
use App\Models\IntegrationImport;
use App\Models\IssuedDocument;
use App\Models\Organization;
use App\Models\OrganizationApprovalRequest;
use App\Models\PosSale;
use App\Models\RepairAssessment;
use App\Models\RepairClosure;
use App\Models\RepairJob;
use App\Models\RepairPayment;
use App\Models\RepairPhoto;
use App\Models\RepairQualityCheck;
use App\Models\RepairQuotation;
use App\Models\RepairRefund;
use App\Models\ServiceClaim;
use App\Models\StaffInvitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class AuditObserver
{
    private const SUBJECTS = [
        IssuedDocument::class => ['issued_document', ['kind', 'source_type', 'source_reference', 'issued_by'], []],
        ServiceClaim::class => ['service_claim', ['status', 'repair_job_id', 'pos_sale_id', 'received_by', 'reviewed_by', 'reviewed_at'],
            ['ข้อมูลเคลม' => ['intake', 'review']],
        ],
        RepairClosure::class => ['repair_closure', ['outcome', 'closed_by', 'closed_at'], ['ข้อมูลปิดงาน' => ['details']]],

        IntegrationConnection::class => ['integration_connection',
            ['name', 'kind', 'account_ref', 'enabled'], ['การตั้งค่าการเชื่อมต่อ' => ['secret', 'credentials']],
        ],
        IntegrationImport::class => ['integration_import', ['kind', 'status'], []],
        CatalogProduct::class => ['catalog_product',
            ['sku', 'name', 'kind', 'tracking', 'category', 'brand', 'unit', 'version'],
            ['ราคาและต้นทุน' => ['retail_satang', 'dealer_satang', 'partner_satang', 'cost_satang']],
        ],
        CatalogWarehouse::class => ['catalog_warehouse', ['name', 'kind'], []],
        CatalogLocation::class => ['catalog_location', ['catalog_warehouse_id', 'code', 'name'], []],
        CatalogMovement::class => ['catalog_movement',
            ['kind', 'sku', 'quantity', 'catalog_warehouse_id', 'catalog_location_id', 'repair_job_id', 'issue_id', 'pos_sale_id'],
            ['หมายเหตุคลัง' => ['note'], 'ต้นทุน' => ['unit_cost_satang', 'total_cost_satang']],
        ],
        RepairJob::class => ['repair',
            ['status', 'technician_id', 'assignment_version', 'workflow_version', 'price_change_assessment_id', 'business_date', 'customer_id', 'customer_device_id', 'due_at', 'work_kind', 'fixed_price_satang'],
            ['ข้อมูลลูกค้าและเครื่อง' => ['customer_name', 'customer_phone', 'customer_address', 'imei', 'serial_number', 'policy_number', 'issue', 'intake_terms', 'accessories', 'device_condition', 'storage_location']],
        ],
        PosSale::class => ['pos_sale',
            ['status', 'branch_counter_id', 'customer_id', 'salesperson_id', 'paid_by', 'voided_by', 'approval_id', 'price_group', 'version'],
            ['ข้อมูลการขาย' => ['total_satang', 'buyer', 'seller', 'void_reason']],
        ],
        CashShift::class => ['cash_shift', ['branch_counter_id', 'opened_by', 'closed_by', 'closed_at'], ['ยอดปิดกะ' => ['opening_satang', 'closing']]],
        CashShiftMovement::class => ['cash_shift_movement', ['kind', 'cash_shift_id', 'recorded_by'], ['ยอดและเหตุผล' => ['amount_satang', 'reason']]],
        RepairRefund::class => ['repair_refund', ['repair_payment_id', 'channel', 'status', 'reviewed_by', 'paid_by', 'cash_shift_id'],
            ['ยอดและหลักฐานคืนเงิน' => ['amount_satang', 'reason', 'review_note', 'proof_filename']]],
        RepairPayment::class => ['repair_payment',
            ['repair_job_id', 'repair_quotation_id', 'received_by', 'channel', 'document_type', 'status', 'cash_shift_id', 'verified_by', 'verified_at'],
            ['ยอดรับและหัวเอกสาร' => ['amount_satang', 'document'], 'หลักฐานการโอน' => ['slip_filename', 'transfer_reference', 'review_note']],
        ],
        RepairPhoto::class => ['repair_photo', ['phase', 'uploaded_by'], []],
        RepairQualityCheck::class => ['repair_quality_check', ['passed', 'workflow_version', 'inspected_by'], ['ผลตรวจ QC' => ['items', 'note']]],
        Customer::class => ['customer',
            ['service_channel', 'marketing_preference'],
            ['ข้อมูลลูกค้าและที่อยู่' => ['name', 'phone', 'contact_name', 'contact_phone', 'email', 'delivery_address', 'billing_address', 'tax_profile']],
        ],
        CustomerDevice::class => ['customer_device',
            ['customer_id'],
            ['ข้อมูลเครื่องและประกัน' => ['model', 'serial_number', 'imei', 'warranty_until', 'warranty_note']],
        ],
        CustomerAppointment::class => ['customer_appointment',
            ['customer_id', 'responsible_user_id', 'kind', 'status', 'starts_at'],
            ['บันทึกนัดหมาย' => ['note']],
        ],
        RepairAssessment::class => ['assessment',
            ['revision', 'total_satang', 'expected_completion_at'],
            ['รายละเอียดตรวจและรายการซ่อม' => ['cause', 'repair_plan', 'items']],
        ],
        RepairQuotation::class => ['quotation',
            ['revision', 'total_satang', 'valid_until', 'repair_assessment_id', 'internal_requested_by', 'internal_requested_at',
                'internal_decision', 'internal_decided_by', 'internal_decided_at', 'customer_requested_by',
                'customer_requested_at', 'customer_request_channel', 'approved_by', 'approved_at', 'approval_channel', 'customer_confirmed_at', 'voided_at', 'voided_by'],
            ['รายละเอียดใบเสนอราคา' => ['items', 'terms', 'revision_reason', 'customer_name', 'seller_profile', 'document_details', 'signature_filename'],
                'บันทึกการอนุมัติ' => ['approval_note', 'internal_request_reason', 'internal_decision_reason', 'customer_approver_name', 'void_reason']],
        ],
        Organization::class => ['organization',
            ['code', 'name', 'operator_type', 'owner_id', 'suspended_at', 'approval_rules'],
            ['หัวเอกสาร' => ['document_profile']],
        ],
        Branch::class => ['branch',
            ['code', 'name', 'opening_hours', 'working_days', 'shift_name', 'shift_start', 'shift_end', 'day_cutoff'],
            ['หัวเอกสาร การออกใบกำกับ และข้อมูลติดต่อ' => ['document_profile', 'issuance_settings', 'address', 'phone']],
        ],
        OrganizationApprovalRequest::class => ['approval',
            ['kind', 'amount', 'limit', 'status', 'review_role', 'reviewed_by', 'reviewed_at'],
            ['บันทึกคำขอ' => ['reference', 'reason', 'decision_reason']],
        ],
        BranchCounter::class => ['counter', ['code', 'name', 'payment_point_id', 'printer_profile_id'], []],
        BranchPaymentPoint::class => ['payment_point', ['code', 'name', 'other_channel_name'], ['QR หน้าร้าน' => ['qr_filename']]],
        BranchPrinterProfile::class => ['printer', ['name', 'paper'], ['ข้อมูลอุปกรณ์' => ['device_name']]],
        BranchMembership::class => ['membership', ['user_id', 'role'], []],
        StaffInvitation::class => ['invitation', ['role', 'invited_by', 'expires_at', 'accepted_at'], []],
    ];

    public static function models(): array
    {
        return array_keys(self::SUBJECTS);
    }

    public function created(Model $model): void
    {
        $this->record($model, 'created');
    }

    public function updated(Model $model): void
    {
        $this->record($model, 'updated');
    }

    public function deleted(Model $model): void
    {
        $this->record($model, 'deleted');
    }

    private function record(Model $model, string $operation): void
    {
        [$type, $fields, $protected] = self::SUBJECTS[$model::class];
        $keys = $operation === 'updated' ? array_keys($model->getChanges()) : array_keys($model->getAttributes());
        $permitted = array_values(array_intersect($fields, $keys));
        $masked = array_keys(array_filter($protected, fn (array $names) => array_intersect($names, $keys) !== []));
        if ($operation === 'updated' && $permitted === [] && $masked === []) {
            return;
        }

        $before = $operation === 'created' ? [] : $this->values($model->getRawOriginal(), $permitted);
        $after = $operation === 'deleted' ? [] : $this->values($model->getAttributes(), $permitted);
        if ($model instanceof RepairJob && $operation === 'created') {
            $after['status'] ??= RepairStatus::Received->value;
        }

        $branch = match (true) {
            $model instanceof Organization => null,
            $model instanceof Branch => $model,
            $model instanceof CustomerDevice => $model->customer->branch,
            $model instanceof CashShiftMovement => Branch::findOrFail(CashShift::findOrFail($model->cash_shift_id)->branch_id),
            $model instanceof CatalogLocation => $model->warehouse->branch,
            $model instanceof RepairAssessment, $model instanceof RepairQuotation, $model instanceof RepairClosure,
            $model instanceof RepairPhoto, $model instanceof RepairQualityCheck => RepairJob::select('branch_id')->findOrFail($model->repair_job_id)->branch,
            default => Branch::findOrFail($model->branch_id),
        };
        $actor = Auth::hasResolvedGuards() ? Auth::user() : null;
        if ($actor === null && $operation === 'created') {
            $actorId = match (true) {
                $model instanceof RepairJob => $model->created_by,
                $model instanceof RepairPayment => $model->received_by,
                $model instanceof RepairAssessment => $model->recorded_by,
                $model instanceof RepairQuotation => $model->issued_by,
                $model instanceof OrganizationApprovalRequest => $model->requested_by,
                default => null,
            };
            $actor = $actorId === null ? null : User::find($actorId);
        }

        AuditEvent::create([
            'organization_id' => $branch?->organization_id ?? $model->id,
            'branch_id' => $branch?->id,
            'actor_id' => $actor?->id,
            'actor_name' => $actor?->name,
            'subject_type' => $type,
            'subject_reference' => $this->reference($model),
            'operation' => $operation,
            'before_values' => $before ?: null,
            'after_values' => $after ?: null,
            'protected_changes' => $masked ?: null,
            'occurred_at' => now(),
        ]);
    }

    private function values(array $attributes, array $fields): array
    {
        $values = array_intersect_key($attributes, array_flip($fields));
        foreach (['working_days', 'approval_rules'] as $field) {
            if (isset($values[$field]) && is_string($values[$field])) {
                $values[$field] = json_decode($values[$field], true, flags: JSON_THROW_ON_ERROR);
            }
        }
        if (is_array($values['working_days'] ?? null)) {
            $values['working_days'] = array_values(array_filter($values['working_days'], fn ($day) => is_int($day) && $day >= 1 && $day <= 7));
        }
        if (is_array($values['approval_rules'] ?? null)) {
            $rules = [];
            foreach (ApprovalKind::cases() as $kind) {
                $rule = $values['approval_rules'][$kind->value] ?? null;
                if (is_array($rule) && is_int($rule['limit'] ?? null) && is_string($rule['review_role'] ?? null)
                    && in_array(BranchRole::tryFrom($rule['review_role']), ApprovalKind::reviewRoles(), true)) {
                    $rules[$kind->value] = ['limit' => $rule['limit'], 'review_role' => $rule['review_role']];
                }
            }
            $values['approval_rules'] = $rules;
        }

        return $values;
    }

    private function reference(Model $model): string
    {
        return match (true) {
            $model instanceof IssuedDocument => $model->number,
            $model instanceof ServiceClaim => $model->reference(),
            $model instanceof RepairClosure => RepairJob::referenceFor($model->repair_job_id),
            $model instanceof RepairJob, $model instanceof RepairQuotation => $model->reference(),
            $model instanceof CashShift => 'SHIFT-'.$model->id,
            $model instanceof CashShiftMovement => 'DRAWER-'.$model->id,
            $model instanceof RepairRefund => 'REFUND-'.$model->id,
            $model instanceof CatalogMovement, $model instanceof RepairPayment, $model instanceof PosSale => $model->reference(),
            $model instanceof CatalogProduct => 'SKU-'.$model->id,
            $model instanceof CatalogWarehouse => 'WH-'.$model->id,
            $model instanceof CashShiftMovement => Branch::findOrFail(CashShift::findOrFail($model->cash_shift_id)->branch_id),
            $model instanceof CatalogLocation => 'LOC-'.$model->id,
            $model instanceof IntegrationConnection => 'CON-'.$model->id,
            $model instanceof IntegrationImport => 'IMP-'.$model->id,
            $model instanceof RepairAssessment, $model instanceof RepairPhoto, $model instanceof RepairQualityCheck => RepairJob::referenceFor($model->repair_job_id),
            $model instanceof Customer => 'CUS-'.$model->id,
            $model instanceof CustomerDevice => 'DEV-'.$model->id,
            $model instanceof CustomerAppointment => 'APT-'.$model->id,
            $model instanceof Organization => 'ORG-'.$model->id,
            $model instanceof Branch => 'BR-'.$model->id,
            $model instanceof OrganizationApprovalRequest => 'APP-'.$model->id,
            $model instanceof BranchMembership => 'STAFF-'.$model->user_id,
            $model instanceof StaffInvitation => 'INVITE-'.$model->id,
            $model instanceof BranchPaymentPoint => 'POINT-'.$model->id,
            $model instanceof BranchPrinterProfile => 'PRINT-'.$model->id,
            $model instanceof BranchCounter => 'COUNTER-'.$model->id,
        };
    }
}
