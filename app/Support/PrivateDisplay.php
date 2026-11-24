<?php

namespace App\Support;

class PrivateDisplay
{
    public static function phone(?string $value): string
    {
        return self::finalFour(preg_replace('/[^0-9]/', '', $value ?? ''));
    }

    public static function finalFour(?string $value): string
    {
        $value = trim($value ?? '');
        if ($value === '') {
            return '—';
        }

        return mb_strlen($value) > 4 ? '•••• '.mb_substr($value, -4) : '••••';
    }
}
