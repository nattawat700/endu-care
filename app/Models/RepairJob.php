<?php

namespace App\Models;

use App\Enums\BranchRole;
use App\Enums\RepairKind;
use App\Enums\RepairStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'created_by', 'submission_id', 'customer_name', 'customer_phone', 'customer_address', 'customer_id', 'customer_device_id',
    'product', 'device', 'color', 'imei', 'serial_number', 'intake_terms', 'policy_number', 'due_at',
    'work_kind', 'fixed_price_satang', 'storage_location',
    'device_condition', 'accessories', 'issue', 'warranty_status', 'device_passcode', 'device_unlock_pattern',
])]
#[Hidden(['device_passcode', 'device_unlock_pattern'])]
class RepairJob extends Model
{
    protected static function booted(): void
    {
        self::creating(function (RepairJob $repair): void {
            $timestamp = $repair->created_at ?? $repair->freshTimestamp();
            $repair->setCreatedAt($timestamp);
            $repair->business_date = $repair->branch->businessDate(CarbonImmutable::instance($timestamp))->toDateString();
            $repair->seller_profile = $repair->branch->documentSeller();
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function statusChanges(): HasMany
    {
        return $this->hasMany(RepairStatusChange::class);
    }

    public function closure(): HasOne
    {
        return $this->hasOne(RepairClosure::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(RepairPayment::class);
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(RepairQuotation::class);
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(RepairAssessment::class);
    }

    public function latestAssessment(): HasOne
    {
        return $this->hasOne(RepairAssessment::class)->ofMany('revision', 'max');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(RepairPhoto::class);
    }

    public function catalogMovements(): HasMany
    {
        return $this->hasMany(CatalogMovement::class);
    }

    /** @return array{total: int, pending: int} */
    public function partsCost(): array
    {
        $total = $pending = 0;
        $issues = $this->catalogMovements()->where('kind', 'issue')->withSum('returns', 'quantity')->cursor();
        foreach ($issues as $issue) {
            $returned = (int) $issue->returns_sum_quantity;
            if ($returned === (int) $issue->quantity) {
                continue;
            }
            $cost = $issue->costForQuantity($issue->quantity);
            if ($cost === null) {
                $pending++;
            } else {
                $total += $cost - $issue->costForQuantity($returned);
            }
        }

        return compact('total', 'pending');
    }

    public function qualityChecks(): HasMany
    {
        return $this->hasMany(RepairQualityCheck::class);
    }

    /** @return list<RepairStatus> */
    public function manualNextStatuses(BranchRole $role): array
    {
        $next = $this->status->nextStatuses($role);
        if ($this->work_kind !== null && $this->status === RepairStatus::Repairing) {
            return array_values(array_filter($next, fn (RepairStatus $status) => $status !== RepairStatus::Ready));
        }

        return $next;
    }

    public function assignTo(User $technician, User $actor): void
    {
        $this->technician()->associate($technician);
        $this->assignedBy()->associate($actor);
        $this->assigned_at = now();
        $this->assignment_version = (int) $this->assignment_version + 1;
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', [RepairStatus::Delivered->value, RepairStatus::ReturnedUnrepaired->value]);
    }

    public function scopeResponsibleFor(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $owned) => $owned->where('technician_id', $user->id)
            ->orWhere(fn (Builder $unassigned) => $unassigned->whereNull('technician_id')->where('created_by', $user->id)));
    }

    public static function endOfDueDay(CarbonImmutable $now): CarbonImmutable
    {
        return $now->timezone('Asia/Bangkok')->startOfDay()->addDay()->utc();
    }

    public static function parseLocalDueTime(string $value): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $value, 'Asia/Bangkok')->utc();
    }

    public function reviseDueAt(?CarbonImmutable $dueAt): void
    {
        $this->due_at = $dueAt;
        if ($this->isDirty('due_at')) {
            $this->due_version++;
        }
    }

    public function coversPriceChange(?int $assessmentId): bool
    {
        return $this->price_change_assessment_id === null || ($assessmentId ?? 0) >= $this->price_change_assessment_id;
    }

    public function moveTo(RepairStatus $next, User $actor, ?string $note): void
    {
        $previous = $this->status;
        $this->status = $next;
        $this->workflow_version++;
        $this->save();
        $this->statusChanges()->create([
            'version' => $this->workflow_version,
            'from_status' => $previous,
            'to_status' => $next,
            'changed_by' => $actor->getKey(),
            'note' => $note,
        ]);
    }

    public function reference(): string
    {
        return self::referenceFor($this->id);
    }

    public static function referenceFor(int $id): string
    {
        return 'REP-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'business_date' => 'date',
            'work_kind' => RepairKind::class,
            'fixed_price_satang' => 'integer',
            'due_at' => 'immutable_datetime',
            'due_version' => 'integer',
            'seller_profile' => 'array',
            'assignment_version' => 'integer',
            'technician_id' => 'integer',
            'status' => RepairStatus::class,
            'workflow_version' => 'integer',
            'price_change_assessment_id' => 'integer',
            'device_passcode' => 'encrypted',
            'device_unlock_pattern' => 'encrypted:array',
        ];
    }
}
