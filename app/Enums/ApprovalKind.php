<?php

namespace App\Enums;

use App\Models\RepairQuotation;

enum ApprovalKind: string
{
    case Discount = 'discount';
    case Refund = 'refund';
    case StockAdjustment = 'stock_adjustment';

    public function label(): string
    {
        return match ($this) {
            self::Discount => 'ส่วนลด',
            self::Refund => 'คืนเงิน',
            self::StockAdjustment => 'ปรับสต๊อก',
        };
    }

    public function unit(): string
    {
        return $this === self::StockAdjustment ? 'หน่วย' : 'บาท';
    }

    public function canRequest(BranchRole $role): bool
    {
        if (in_array($role, self::reviewRoles(), true)) {
            return true;
        }

        return $this === self::StockAdjustment
            ? $role === BranchRole::Stock
            : in_array($role, [BranchRole::Reception, BranchRole::Cashier], true);
    }

    /** @return list<BranchRole> */
    public static function reviewRoles(): array
    {
        return [BranchRole::Owner, BranchRole::Hq, BranchRole::Manager];
    }

    public function inputRules(int $minimum = 0): array
    {
        return $this === self::StockAdjustment
            ? ['required', 'integer', 'between:'.$minimum.',9999999']
            : ['required', 'regex:/\A(?:0|[1-9][0-9]{0,6})(?:\.[0-9]{1,2})?\z/'];
    }

    public function parse(string|int $value): int
    {
        if ($this === self::StockAdjustment) {
            return (int) $value;
        }

        [$baht, $fraction] = array_pad(explode('.', (string) $value, 2), 2, '');

        return ((int) $baht * 100) + (int) str_pad($fraction, 2, '0');
    }

    public function format(int $amount, string $separator = ','): string
    {
        return $this === self::StockAdjustment
            ? number_format($amount, thousands_separator: $separator)
            : RepairQuotation::formatBaht($amount, $separator);
    }
}
