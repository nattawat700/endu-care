<?php

namespace App\Support;

use App\Enums\RepairStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\PosSale;
use App\Models\RepairJob;
use App\Models\RepairPayment;
use Illuminate\Validation\ValidationException;

class TaxDocumentData
{
    public static function repair(Branch $branch, RepairJob $job, RepairPayment $payment, string $kind): array
    {
        $billing = RepairBilling::summary($job);
        $document = $payment->document;
        if ($job->status === RepairStatus::ReturnedUnrepaired || $payment->status !== 'confirmed' || $payment->document_type === 'deposit'
            || $payment->paid_before_satang !== 0 || $payment->remainingSatang() !== 0
            || $billing['pending'] > 0 || $billing['refunded'] > 0 || $billing['paid'] !== $payment->amount_satang
            || $billing['payable'] !== $payment->payable_satang || $billing['quotation']?->id !== $payment->repair_quotation_id) {
            self::invalid('ชุดนี้ออกใบกำกับจากใบเสร็จที่ชำระเต็มในรายการเดียว กรุณาให้บัญชีจัดการกรณีมัดจำ แบ่งชำระ หรือคืนเงิน');
        }

        $customer = $job->customer_id ? $branch->customers()->findOrFail($job->customer_id) : null;

        return self::document($branch, $kind, $customer, $document['items'], $payment->job_total_satang,
            $document['amount_breakdown'] ?? [], $payment->amount_satang);
    }

    public static function pos(Branch $branch, PosSale $sale, string $kind): array
    {
        if ($sale->status !== 'paid' || $sale->total_satang <= 0) {
            self::invalid('ใช้ได้กับบิล POS ที่ชำระแล้วและยังมีผล');
        }
        $lines = $sale->lines()->get()->map(fn ($line) => [
            'description' => $line->name.' · '.$line->sku, 'quantity' => $line->quantity, 'unit' => $line->unit_name,
            'unit_satang' => $line->unit_satang, 'discount_satang' => $line->discount_satang,
            'total_satang' => ($line->quantity * $line->unit_satang) - $line->discount_satang,
        ])->all();
        $customer = $sale->customer_id ? $branch->customers()->findOrFail($sale->customer_id) : null;

        return self::document($branch, $kind, $customer, $lines, $sale->total_satang,
            ['document_discount_satang' => $sale->document_discount_satang], $sale->total_satang);
    }

    private static function document(Branch $branch, string $kind, ?Customer $customer, array $items, int $gross, array $amounts, int $paid): array
    {
        $settings = $branch->issuance_settings ?? [];
        if (! ($settings['enabled'] ?? false) || ($kind === 'tax_short' && ! ($settings['abbreviated'] ?? false))) {
            self::invalid('ยังไม่ได้เปิดใช้ใบกำกับภาษีประเภทนี้ในการตั้งค่าสาขา');
        }

        $seller = $branch->documentSeller();
        if (empty($seller['address']) || empty($seller['name']) || ! preg_match('/^[0-9]{13}$/D', $seller['tax_id'] ?? '') || ! isset($settings['office_number'])) {
            self::invalid('กรุณาตรวจชื่อ ที่อยู่ เลขภาษี และรหัสสาขาของผู้ขาย');
        }
        $seller['office'] = $settings['office_number'] === '00000' ? 'สำนักงานใหญ่ (00000)' : 'สาขาที่ '.$settings['office_number'];

        $buyer = $kind === 'tax_full' ? $customer?->tax_profile : null;
        if ($kind === 'tax_full' && $buyer === null) {
            self::invalid('กรุณาผูกลูกค้ากับรายการต้นทางและบันทึกข้อมูลออกใบกำกับภาษีในทะเบียนลูกค้าก่อน');
        }

        $mode = $amounts['vat_mode'] ?? 'none';
        if ($kind === 'tax_short' && ($mode === 'exclusive' || ($amounts['withholding_satang'] ?? 0) > 0)) {
            self::invalid('รายการนี้มี VAT แยกหรือหัก ณ ที่จ่าย กรุณาใช้แบบเต็มรูป');
        }
        $rate = (int) ($settings['rate'] ?? 0);
        if ($mode !== 'none' && (int) $amounts['vat_rate'] !== $rate) {
            self::invalid('อัตราภาษีของเอกสารต้นทางไม่ตรงกับการตั้งค่าปัจจุบัน กรุณาให้บัญชีตรวจสอบก่อนออกเอกสาร');
        }
        $vat = $mode !== 'none' ? (int) $amounts['vat_satang'] : intdiv($gross * $rate + intdiv(10000 + $rate, 2), 10000 + $rate);

        return [
            'seller' => $seller, 'buyer' => $buyer, 'items' => $items, 'rate' => $rate, 'vat' => $vat, 'base' => $gross - $vat,
            'gross' => $gross, 'paid' => $paid, 'mode' => $mode === 'exclusive' ? 'exclusive' : 'inclusive',
            'adjustments' => array_intersect_key($amounts, array_flip(['document_discount_satang', 'shipping_satang', 'other_charge_satang', 'withholding_satang'])),
        ];
    }

    private static function invalid(string $message): never
    {
        throw ValidationException::withMessages(['kind' => $message]);
    }
}
