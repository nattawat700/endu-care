<?php

namespace App\Support;

use Closure;

class StaffPassword
{
    public static function rules(): array
    {
        return ['bail', 'required', 'string', 'min:12', 'confirmed', function (string $attribute, string $value, Closure $fail): void {
            if (strlen($value) > 72 || ! mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                $fail('รหัสผ่านต้องไม่เกิน 72 ไบต์และไม่มีอักขระควบคุม');
            }
        }];
    }
}
