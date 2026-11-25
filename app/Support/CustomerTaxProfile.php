<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CustomerTaxProfile
{
    public static function fromRequest(Request $request): array
    {
        $fields = $request->validate([
            'type' => ['required', Rule::in(['individual', 'company'])],
            'name' => ['required', 'string', 'max:180'],
            'address' => ['required', 'string', 'max:500'],
            'tax_id' => ['nullable', 'required_if:type,company', 'digits:13'],
            'office' => ['exclude_unless:type,company', 'required', Rule::in(['head', 'branch'])],
            'office_number' => ['exclude_unless:office,branch', 'exclude_unless:type,company', 'required', 'digits:5'],
        ]);

        if (($fields['office_number'] ?? null) === '00000') {
            throw ValidationException::withMessages(['office_number' => 'รหัส 00000 ให้เลือกสำนักงานใหญ่']);
        }

        return $fields;
    }

    public static function office(array $profile): ?string
    {
        return match ($profile['office'] ?? null) {
            'head' => 'สำนักงานใหญ่ (00000)',
            'branch' => 'สาขาที่ '.$profile['office_number'],
            default => null,
        };
    }
}
