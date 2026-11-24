<?php

namespace App\Support;

use App\Models\RepairQuotation;
use Illuminate\Validation\Rule;

class RepairPriceItems
{
    public static function rules(bool $forQuotation = false): array
    {
        $types = $forQuotation ? array_keys(RepairQuotation::ITEM_TYPES) : ['labor', 'part'];
        $keys = $forQuotation ? 'type,description,quantity,unit_price,unit,discount' : 'type,description,quantity,unit_price';

        return [
            'items' => ['required', 'array', 'list', 'min:1', 'max:40'],
            'items.*' => ['required', 'array:'.$keys],
            'items.*.type' => ['required', Rule::in($types)],
            'items.*.description' => ['required', 'string', 'max:180'],
            'items.*.quantity' => ['required', 'integer', 'between:1,1000'],
            'items.*.unit_price' => ['required', QuotationAmounts::MONEY],
            ...($forQuotation ? [
                'items.*.unit' => ['nullable', 'string', 'max:30'],
                'items.*.discount' => ['nullable', QuotationAmounts::MONEY],
            ] : []),
        ];
    }

    public static function messages(): array
    {
        return [
            'items.required' => 'กรุณาใส่อย่างน้อย 1 รายการ',
            'items.*.unit_price.regex' => 'ราคาต่อหน่วยต้องเป็นจำนวนบาท ตั้งแต่ 0 ถึง 9,999,999.99 และทศนิยมไม่เกิน 2 ตำแหน่ง',
        ];
    }

    public static function storeValues(array $items, bool $forQuotation = false): array
    {
        return array_map(function (array $item) use ($forQuotation): array {
            $unit = QuotationAmounts::hundredths($item['unit_price']);
            $discount = $forQuotation ? QuotationAmounts::hundredths($item['discount'] ?? 0) : 0;
            $quantity = (int) $item['quantity'];

            return [
                'type' => $item['type'],
                'description' => $item['description'],
                'quantity' => $quantity,
                'unit_satang' => $unit,
                'total_satang' => $unit * $quantity - $discount,
                ...($forQuotation ? ['unit' => $item['unit'] ?? null, 'discount_satang' => $discount] : []),
            ];
        }, $items);
    }

    public static function formValues(array $items, bool $forQuotation = false): array
    {
        return array_map(fn (array $item) => [
            'type' => $item['type'],
            'description' => $item['description'],
            'quantity' => $item['quantity'],
            'unit_price' => RepairQuotation::formatBaht($item['unit_satang'], ''),
            ...($forQuotation ? [
                'unit' => $item['unit'] ?? '',
                'discount' => RepairQuotation::formatBaht($item['discount_satang'] ?? 0, ''),
            ] : []),
        ], $items);
    }
}
