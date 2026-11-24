<?php

namespace App\Support;

use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class QuotationAmounts
{
    public const MONEY = 'regex:/\A(?:0|[1-9][0-9]{0,6})(?:\.[0-9]{1,2})?\z/';

    public const VAT_MODES = [
        'none' => 'ไม่คิด VAT',
        'exclusive' => 'บวก VAT จากยอดก่อนภาษี',
        'inclusive' => 'ราคาและค่าบริการรวม VAT แล้ว',
    ];

    public const ADJUSTMENTS = [
        'document_discount' => 'ส่วนลดท้ายเอกสาร',
        'shipping' => 'ค่าขนส่ง',
        'other_charge' => 'ค่าใช้จ่ายอื่น',
        'withholding' => 'ภาษีหัก ณ ที่จ่าย',
    ];

    public static function rules(): array
    {
        return [
            'vat_mode' => ['nullable', Rule::in(array_keys(self::VAT_MODES))],
            'vat_rate' => ['exclude_if:vat_mode,none', 'nullable', 'required_with:vat_mode', self::MONEY, 'numeric', 'min:0', 'max:100'],
            'other_label' => ['nullable', 'string', 'max:100'],
            ...array_fill_keys(array_keys(self::ADJUSTMENTS), ['nullable', self::MONEY]),
        ];
    }

    public static function hundredths(string|int $amount): int
    {
        [$units, $fraction] = array_pad(explode('.', (string) $amount, 2), 2, '');

        return ((int) $units * 100) + (int) str_pad($fraction, 2, '0');
    }

    public static function calculate(array $items, array $fields): array
    {
        $subtotal = 0;
        $lineDiscount = 0;
        foreach ($items as $index => $item) {
            $gross = $item['quantity'] * $item['unit_satang'];
            $discount = $item['discount_satang'] ?? 0;
            if ($discount > $gross) {
                throw ValidationException::withMessages(["items.{$index}.discount" => 'ส่วนลดรายการต้องไม่เกินมูลค่าของรายการนั้น']);
            }

            $subtotal += $gross;
            $lineDiscount += $discount;
        }

        $adjustments = array_map(fn (string $key): int => self::hundredths($fields[$key] ?? 0), array_keys(self::ADJUSTMENTS));
        [$documentDiscount, $shipping, $otherCharge, $withholding] = $adjustments;
        if ($documentDiscount > $subtotal - $lineDiscount) {
            throw ValidationException::withMessages(['document_discount' => 'ส่วนลดท้ายเอกสารต้องไม่เกินยอดรายการหลังหักส่วนลดรายบรรทัด']);
        }

        $base = $subtotal - $lineDiscount - $documentDiscount + $shipping + $otherCharge;
        $vatMode = $fields['vat_mode'] ?? 'none';
        $vatRate = $vatMode === 'none' ? 0 : self::hundredths($fields['vat_rate'] ?? 0);
        $divisor = $vatMode === 'inclusive' ? 10000 + $vatRate : 10000;
        $vat = intdiv($base * $vatRate + intdiv($divisor, 2), $divisor);
        $grandTotal = $vatMode === 'exclusive' ? $base + $vat : $base;
        if ($withholding > $grandTotal) {
            throw ValidationException::withMessages(['withholding' => 'ภาษีหัก ณ ที่จ่ายต้องไม่เกินยอดรวมใบเสนอราคา']);
        }

        return [
            'subtotal_satang' => $subtotal,
            'line_discount_satang' => $lineDiscount,
            ...array_combine(array_map(fn (string $key): string => $key.'_satang', array_keys(self::ADJUSTMENTS)), $adjustments),
            'other_label' => $otherCharge > 0 ? ($fields['other_label'] ?? null) : null,
            'vat_mode' => $vatMode, 'vat_rate' => $vatRate, 'vat_satang' => $vat,
            'net_before_vat_satang' => $grandTotal - $vat,
            'grand_total_satang' => $grandTotal,
            'payable_satang' => $grandTotal - $withholding,
        ];
    }
}
