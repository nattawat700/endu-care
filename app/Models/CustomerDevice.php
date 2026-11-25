<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['model', 'serial_number', 'imei', 'warranty_until', 'warranty_note'])]
class CustomerDevice extends Model
{
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function repairs(): HasMany
    {
        return $this->hasMany(RepairJob::class);
    }

    public static function serialKey(?string $serial): ?string
    {
        return filled($serial) ? strtoupper(trim($serial)) : null;
    }

    public function matchesIntake(RepairJob $repair): bool
    {
        $serial = self::serialKey($repair->serial_number);
        $hasSerial = $serial !== null && $this->serial_number !== null;
        $hasImei = $repair->imei !== null && $this->imei !== null;

        return ($hasSerial || $hasImei)
            && (! $hasSerial || $serial === $this->serial_number)
            && (! $hasImei || $repair->imei === $this->imei);
    }

    protected function casts(): array
    {
        return ['warranty_until' => 'date'];
    }
}
