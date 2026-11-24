<?php

namespace App\Enums;

enum QuotationDecision: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Approved => 'ผู้จัดการอนุมัติแล้ว',
            self::Rejected => 'ผู้จัดการไม่อนุมัติ',
        };
    }
}
