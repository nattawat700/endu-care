<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable([
    'submission_id', 'name', 'name_key', 'phone', 'phone_key', 'contact_name', 'contact_phone',
    'email', 'delivery_address', 'billing_address', 'service_channel', 'marketing_preference',
])]
class Customer extends Model
{
    protected $hidden = ['tax_profile'];

    protected function casts(): array
    {
        return ['tax_profile' => 'encrypted:array'];
    }

    public const CHANNELS = ['phone' => 'โทรศัพท์', 'line' => 'LINE', 'email' => 'อีเมล', 'sms' => 'SMS'];

    public const MARKETING = ['unspecified' => 'ยังไม่ได้สอบถาม', 'opted_in' => 'ต้องการรับข่าว', 'opted_out' => 'ไม่ต้องการรับข่าว'];

    public static function nameKey(string $name): string
    {
        return Str::substr(Str::lower(Str::squish($name)), 0, 150);
    }

    public static function phoneKey(string $phone): string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone);

        return str_starts_with($phone, '+66') ? '0'.substr($digits, 2) : $digits;
    }

    public function customerDevices(): HasMany
    {
        return $this->hasMany(CustomerDevice::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(CustomerAppointment::class);
    }

    public function posSales(): HasMany
    {
        return $this->hasMany(PosSale::class);
    }

    public function repairs(): HasMany
    {
        return $this->hasMany(RepairJob::class);
    }
}
