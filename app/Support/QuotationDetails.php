<?php

namespace App\Support;

class QuotationDetails
{
    public const TEXT_FIELDS = [
        'buyer_name' => 150,
        'buyer_phone' => 30,
        'buyer_office' => 100,
        'buyer_address' => 750,
        'contact_name' => 150,
        'contact_phone' => 30,
        'delivery_terms' => 1500,
        'notes' => 1500,
        'payment_channels' => 150,
        'payment_details' => 1000,
    ];

    public static function rules(): array
    {
        $rules = [];
        foreach (self::TEXT_FIELDS as $field => $max) {
            $rules[$field] = ['nullable', 'string', 'max:'.$max];
        }

        $rules['buyer_name'] = ['sometimes', 'required', 'string', 'max:150'];

        return $rules + [
            'buyer_email' => ['nullable', 'email', 'max:254'],
            'buyer_tax_id' => ['nullable', 'digits:13'],
            'contact_email' => ['nullable', 'email', 'max:254'],
            'credit_days' => ['nullable', 'integer', 'between:0,365'],
        ];
    }
}
