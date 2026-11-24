<?php

namespace App\Models;

use App\Enums\RepairStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'created_by', 'submission_id', 'customer_name', 'customer_phone', 'customer_address',
    'product', 'device', 'color', 'imei', 'serial_number', 'intake_terms', 'policy_number',
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
