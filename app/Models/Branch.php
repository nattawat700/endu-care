<?php

namespace App\Models;

use App\Enums\BranchRole;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'code', 'name', 'address', 'phone', 'opening_hours', 'receipt_layout', 'document_profile',
    'working_days', 'shift_name', 'shift_start', 'shift_end', 'day_cutoff',
])]
class Branch extends Model
{
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(BranchMembership::class);
    }

    public function technicians(): HasMany
    {
        return $this->memberships()->where('role', BranchRole::Technician->value)->whereHas('user', fn ($users) => $users->whereNull('suspended_at'));
    }

    public function staffInvitations(): HasMany
    {
        return $this->hasMany(StaffInvitation::class);
    }

    public function staffAccessEvents(): HasMany
    {
        return $this->hasMany(StaffAccessEvent::class);
    }

    public function repairs(): HasMany
    {
        return $this->hasMany(RepairJob::class);
    }

    public function posSales(): HasMany
    {
        return $this->hasMany(PosSale::class);
    }

    public function issuedDocuments(): HasMany
    {
        return $this->hasMany(IssuedDocument::class);
    }

    public function serviceClaims(): HasMany
    {
        return $this->hasMany(ServiceClaim::class);
    }

    public function cashShifts(): HasMany
    {
        return $this->hasMany(CashShift::class);
    }

    public function repairPayments(): HasMany
    {
        return $this->hasMany(RepairPayment::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function catalogProducts(): HasMany
    {
        return $this->hasMany(CatalogProduct::class);
    }

    public function integrationConnections(): HasMany
    {
        return $this->hasMany(IntegrationConnection::class);
    }

    public function integrationImports(): HasMany
    {
        return $this->hasMany(IntegrationImport::class);
    }

    public function catalogWarehouses(): HasMany
    {
        return $this->hasMany(CatalogWarehouse::class);
    }

    public function catalogMovements(): HasMany
    {
        return $this->hasMany(CatalogMovement::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(CustomerAppointment::class);
    }

    public function paymentPoints(): HasMany
    {
        return $this->hasMany(BranchPaymentPoint::class);
    }

    public function printerProfiles(): HasMany
    {
        return $this->hasMany(BranchPrinterProfile::class);
    }

    public function counters(): HasMany
    {
        return $this->hasMany(BranchCounter::class);
    }

    public function approvalRequests(): HasMany
    {
        return $this->hasMany(OrganizationApprovalRequest::class);
    }

    public function businessDate(CarbonImmutable $instant): CarbonImmutable
    {
        $local = $instant->timezone('Asia/Bangkok');
        $date = $local->startOfDay();

        return $local->format('H:i') < ($this->day_cutoff ?? '00:00') ? $date->subDay() : $date;
    }

    public function documentSeller(?array $snapshot = null): array
    {
        if ($snapshot !== null && ($snapshot['branch_id'] ?? null) === $this->id) {
            return $snapshot;
        }

        // Older snapshots combine organization and branch fields without tracking their source.
        $profile = ($this->document_profile ?? []) + array_filter([
            'name' => $this->name,
            'address' => $this->address,
            'phone' => $this->phone,
        ]);

        if ($this->code === 'fix' && $this->organization->code === 'endu') {
            $profile += [
                'address' => 'เลขที่ 5/2 ซอยบุบผาบุรี แขวงนนทรี เขตยานนาวา กรุงเทพมหานคร 10120',
                'phone' => '093-751-7781',
                'default_logo' => 'images/endu-fix-receipt-logo.png',
            ];
        }

        return ['branch_id' => $this->id] + $profile;
    }

    protected function casts(): array
    {
        return ['issuance_settings' => 'array', 'document_profile' => 'array', 'working_days' => 'array'];
    }

    public function usesEnduFixReceipt(): bool
    {
        return $this->receipt_layout === null
            ? $this->code === 'fix' && $this->organization->code === 'endu'
            : $this->receipt_layout === 'endu_fix';
    }

    public function roleFor(User $user): ?BranchRole
    {
        return $user->suspended_at === null && $this->organization->suspended_at === null
            ? $this->memberships()->whereBelongsTo($user)->first()?->role
            : null;
    }
}
