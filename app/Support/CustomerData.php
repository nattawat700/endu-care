<?php

namespace App\Support;

use App\Models\Customer;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CustomerData
{
    public const COLUMNS = ['name', 'phone', 'contact_name', 'contact_phone', 'email', 'delivery_address', 'billing_address', 'service_channel', 'marketing_preference'];

    public static function prepare(array $input, array $rules = []): array
    {
        $phone = ['string', 'max:30', 'regex:/^\+?[0-9][0-9 ()-]*$/D'];
        $fields = Validator::make($input, $rules + [
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['required', ...$phone],
            'contact_name' => ['nullable', 'string', 'max:150'],
            'contact_phone' => ['nullable', ...$phone],
            'email' => ['nullable', 'email', 'max:254'],
            'delivery_address' => ['nullable', 'string', 'max:500'],
            'billing_address' => ['nullable', 'string', 'max:500'],
            'service_channel' => ['required', Rule::in(array_keys(Customer::CHANNELS))],
            'marketing_preference' => ['required', Rule::in(array_keys(Customer::MARKETING))],
            'confirm_duplicate' => ['sometimes', 'accepted'],
        ], [
            'phone.regex' => 'กรุณากรอกหมายเลขโทรศัพท์',
            'contact_phone.regex' => 'กรุณากรอกหมายเลขโทรศัพท์ผู้ติดต่อ',
            'service_channel.required' => 'กรุณาเลือกช่องทางแจ้งงาน',
            'marketing_preference.required' => 'กรุณาระบุความประสงค์รับข่าวการตลาด',
        ])->validate();

        foreach (['phone', 'contact_phone'] as $key) {
            if (isset($fields[$key]) && ! preg_match('/^[0-9]{7,15}$/D', Customer::phoneKey($fields[$key]))) {
                throw ValidationException::withMessages([$key => 'หมายเลขโทรศัพท์ต้องมีตัวเลข 7–15 หลัก']);
            }
        }

        unset($fields['confirm_duplicate']);

        return $fields + ['name_key' => Customer::nameKey($fields['name']), 'phone_key' => Customer::phoneKey($fields['phone'])];
    }
}
