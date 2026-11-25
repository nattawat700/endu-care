<?php

namespace App\Enums;

enum RepairKind: string
{
    case Quick = 'quick';
    case Assessment = 'assessment';

    public function label(): string
    {
        return match ($this) {
            self::Quick => 'งานด่วนทราบราคา',
            self::Assessment => 'งานตรวจประเมิน',
        };
    }
}
