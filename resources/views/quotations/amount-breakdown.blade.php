<div><dt>รวมมูลค่ารายการ</dt><dd>{{ $money($amounts['subtotal_satang']) }}</dd></div>
@if (! empty($amounts['line_discount_satang'])) <div><dt>ส่วนลดรายบรรทัด</dt><dd>−{{ $money($amounts['line_discount_satang']) }}</dd></div> @endif
@if (! empty($amounts['document_discount_satang'])) <div><dt>ส่วนลดท้ายเอกสาร</dt><dd>−{{ $money($amounts['document_discount_satang']) }}</dd></div> @endif
@if (! empty($amounts['shipping_satang'])) <div><dt>ค่าขนส่ง</dt><dd>{{ $money($amounts['shipping_satang']) }}</dd></div> @endif
@if (! empty($amounts['other_charge_satang'])) <div><dt>{{ $amounts['other_label'] ?? 'ค่าใช้จ่ายอื่น' }}</dt><dd>{{ $money($amounts['other_charge_satang']) }}</dd></div> @endif
@if ($amounts['vat_mode'] !== 'none')
    <div><dt>มูลค่าก่อน VAT</dt><dd>{{ $money($amounts['net_before_vat_satang']) }}</dd></div>
    <div><dt>VAT {{ \App\Models\RepairQuotation::formatBaht($amounts['vat_rate'], '') }}%</dt><dd>{{ $money($amounts['vat_satang']) }}</dd></div>
@endif
