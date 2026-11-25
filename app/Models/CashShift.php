<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'branch_counter_id', 'branch_payment_point_id', 'opened_by', 'closed_by', 'submission_id',
    'active_counter', 'active_operator', 'opening_satang', 'closing', 'closed_at',
])]
class CashShift extends Model
{
    protected function casts(): array
    {
        return ['closing' => 'array', 'closed_at' => 'datetime', 'opening_satang' => 'integer'];
    }

    public function counter(): BelongsTo
    {
        return $this->belongsTo(BranchCounter::class, 'branch_counter_id');
    }

    public function point(): BelongsTo
    {
        return $this->belongsTo(BranchPaymentPoint::class, 'branch_payment_point_id');
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashShiftMovement::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(RepairPayment::class);
    }

    public static function active(Branch $branch, User $user): ?self
    {
        return $branch->cashShifts()->where('active_operator', $user->id)->first();
    }

    public function posTenders(): HasMany
    {
        return $this->hasMany(PosTender::class);
    }

    public function totals(): array
    {
        $lines = RepairPaymentLine::query()
            ->join('repair_payments', 'repair_payments.id', '=', 'repair_payment_lines.repair_payment_id')
            ->where('repair_payments.cash_shift_id', $this->id)
            ->where(fn ($query) => $query->where('repair_payments.status', 'confirmed')
                ->orWhere(fn ($pending) => $pending->where('repair_payments.status', 'pending')->where('repair_payment_lines.channel', 'cash')))
            ->selectRaw('repair_payment_lines.channel, SUM(repair_payment_lines.amount_satang) AS total')
            ->groupBy('repair_payment_lines.channel')->pluck('total', 'channel');
        $refunds = RepairRefund::where('cash_shift_id', $this->id)->where('status', 'completed')
            ->selectRaw('channel, SUM(amount_satang) AS total')->groupBy('channel')->pluck('total', 'channel');
        $movements = $this->movements()->selectRaw('kind, SUM(amount_satang) AS total')->groupBy('kind')->pluck('total', 'kind');
        $pos = $this->posTenders()->selectRaw('direction, SUM(amount_satang) AS total')->groupBy('direction')->pluck('total', 'direction');
        $cash = (int) ($lines['cash'] ?? 0) + (int) ($pos['received'] ?? 0);
        $cashRefunds = (int) ($refunds['cash'] ?? 0) + (int) ($pos['returned'] ?? 0);
        $topUps = (int) ($movements['top_up'] ?? 0);
        $deposits = (int) ($movements['deposit'] ?? 0);
        $expenses = (int) ($movements['expense'] ?? 0);
        $transfer = (int) ($lines['transfer'] ?? 0) + (int) ($lines['qr'] ?? 0);
        $transferRefunds = (int) ($refunds['transfer'] ?? 0) + (int) ($refunds['qr'] ?? 0);

        return [
            'cash_received' => $cash, 'cash_refunds' => $cashRefunds, 'top_up' => $topUps, 'deposit' => $deposits, 'expense' => $expenses,
            'cash_expected' => $this->opening_satang + $cash + $topUps - $cashRefunds - $deposits - $expenses,
            'transfer_received' => $transfer, 'transfer_refunds' => $transferRefunds, 'transfer_expected' => $transfer - $transferRefunds,
            'other_received' => (int) ($lines['other'] ?? 0), 'other_refunds' => (int) ($refunds['other'] ?? 0),
        ];
    }
}
