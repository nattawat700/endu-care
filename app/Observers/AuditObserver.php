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
use App\Models\Organization;
use App\Models\OrganizationApprovalRequest;
use App\Models\RepairAssessment;
use App\Models\RepairJob;
use App\Models\RepairQuotation;
use App\Models\StaffInvitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class AuditObserver
{
    private const SUBJECTS = [
        RepairJob::class => ['repair',
            ['status', 'technician_id', 'assignment_version', 'workflow_version', 'price_change_assessment_id', 'business_date'],
            ['ข้อมูลลูกค้าและเครื่อง' => ['customer_name', 'customer_phone', 'customer_address', 'imei', 'serial_number', 'policy_number', 'issue', 'intake_terms', 'accessories', 'device_condition']],
        ],
        RepairAssessment::class => ['assessment',
            ['revision', 'total_satang', 'expected_completion_at'],
            ['รายละเอียดตรวจและรายการซ่อม' => ['cause', 'repair_plan', 'items']],
        ],
        RepairQuotation::class => ['quotation',
            ['revision', 'total_satang', 'valid_until', 'repair_assessment_id', 'internal_requested_by', 'internal_requested_at',
                'internal_decision', 'internal_decided_by', 'internal_decided_at', 'customer_requested_by',
                'customer_requested_at', 'customer_request_channel', 'approved_by', 'approved_at', 'approval_channel', 'customer_confirmed_at'],
            ['รายละเอียดใบเสนอราคา' => ['items', 'terms', 'revision_reason', 'customer_name', 'seller_profile', 'document_details', 'signature_filename'],
                'บันทึกการอนุมัติ' => ['approval_note', 'internal_request_reason', 'internal_decision_reason', 'customer_approver_name']],
        ],
        Organization::class => ['organization',
            ['code', 'name', 'operator_type', 'owner_id', 'suspended_at', 'approval_rules'],
            ['หัวเอกสาร' => ['document_profile']],
        ],
        Branch::class => ['branch',
            ['code', 'name', 'opening_hours', 'working_days', 'shift_name', 'shift_start', 'shift_end', 'day_cutoff'],
            ['หัวเอกสารและข้อมูลติดต่อ' => ['document_profile', 'address', 'phone']],
        ],
        OrganizationApprovalRequest::class => ['approval',
            ['kind', 'amount', 'limit', 'status', 'review_role', 'reviewed_by', 'reviewed_at'],
            ['บันทึกคำขอ' => ['reference', 'reason', 'decision_reason']],
        ],
        BranchCounter::class => ['counter', ['code', 'name', 'payment_point_id', 'printer_profile_id'], []],
        BranchPaymentPoint::class => ['payment_point', ['code', 'name'], []],
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
            $model instanceof RepairAssessment, $model instanceof RepairQuotation => RepairJob::select('branch_id')->findOrFail($model->repair_job_id)->branch,
            default => Branch::findOrFail($model->branch_id),
        };
        $actor = Auth::hasResolvedGuards() ? Auth::user() : null;
        if ($actor === null && $operation === 'created') {
            $actorId = match (true) {
                $model instanceof RepairJob => $model->created_by,
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
            $model instanceof RepairJob, $model instanceof RepairQuotation => $model->reference(),
            $model instanceof RepairAssessment => RepairJob::referenceFor($model->repair_job_id),
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
