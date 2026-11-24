@php
    use App\Models\RepairQuotation;
    use App\Support\PrivateDisplay;

    $profile = $branch->documentSeller($quotation->seller_profile);
    $details = $quotation->document_details ?? [];
    $amounts = $quotation->amount_breakdown ?? [
        'subtotal_satang' => $quotation->total_satang,
        'net_before_vat_satang' => $quotation->total_satang,
        'payable_satang' => $quotation->total_satang,
        'vat_mode' => 'none',
    ];
    $releaseDate = $quotation->canRelease() ? ($quotation->internal_decided_at ?? $quotation->created_at) : $quotation->created_at;
    $dateLabel = $quotation->canRelease() ? 'วันที่ออกเอกสาร' : 'วันที่จัดทำ';
    $buyerPhone = array_key_exists('buyer_phone', $details) ? $details['buyer_phone'] : $repair->customer_phone;
    $money = static fn (int $amount): string => RepairQuotation::formatBaht($amount).' บาท';
@endphp

<div class="quotation-wrap">
    @unless ($quotation->canRelease() && $canPrintQuote)
        <p class="quotation-print-notice hidden">ใบเสนอราคา {{ $quotation->reference() }} ยังไม่พร้อมสำหรับพิมพ์ส่งลูกค้า</p>
    @endunless
    <article @class(['panel repair-quotation', 'quotation-unreleased' => ! $quotation->canRelease() || ! $canPrintQuote])>
        <header class="quotation-doc-head">
            <div class="quotation-seller">
                @include('organizations.seller-header')
            </div>
            <div class="quotation-identity">
                <h2 class="quotation-title">ใบเสนอราคา <span lang="en">/ Quotation</span></h2>
                <dl class="quotation-identity-data">
                    <div><dt>เลขที่เอกสาร</dt><dd class="font-bold tabular-nums">{{ $quotation->reference() }}</dd></div>
                    <div><dt>{{ $dateLabel }}</dt><dd>{{ $releaseDate->timezone('Asia/Bangkok')->format('d/m/Y') }}</dd></div>
                    <div><dt>วันหมดอายุ</dt><dd>{{ $quotation->valid_until?->format('d/m/Y') ?? '—' }}</dd></div>
                    <div><dt>ใบงานอ้างอิง</dt><dd>{{ $repair->reference() }}</dd></div>
                    <div><dt>ฉบับ</dt><dd>{{ $quotation->revision }} · {{ $isLatest ? 'ฉบับล่าสุด' : 'ฉบับก่อนหน้า' }}</dd></div>
                </dl>
                @unless ($quotation->canRelease())
                    <p class="mt-2 text-xs font-semibold text-amber-800">สำหรับตรวจภายใน · {{ $quotation->internalApprovalLabel($isLatest) }}</p>
                @endunless
                @if ($quotation->isExpired() && ! $quotation->approved_at)
                    <p class="mt-2 text-xs font-semibold text-amber-800">ใบเสนอราคาหมดอายุ</p>
                @endif
            </div>
        </header>

        <div class="quotation-parties">
            <section>
                <h3>ผู้ซื้อ</h3>
                <p class="font-semibold">{{ $quotation->customer_name }}</p>
                @if ($canViewPrivateCustomerDetails)
                    @isset($details['buyer_office']) <p>{{ $details['buyer_office'] }}</p> @endisset
                    @isset($details['buyer_address']) <p class="whitespace-pre-wrap">{{ $details['buyer_address'] }}</p> @endisset
                    @isset($details['buyer_tax_id']) <p>เลขผู้เสียภาษี {{ $details['buyer_tax_id'] }}</p> @endisset
                    @isset($details['buyer_email']) <p>{{ $details['buyer_email'] }}</p> @endisset
                @endif
                @if ($buyerPhone)
                    <p>โทรศัพท์ {{ $canViewPrivateCustomerDetails ? $buyerPhone : PrivateDisplay::phone($buyerPhone) }}</p>
                @endif
            </section>
            <section>
                <h3>ผู้ประสานงาน</h3>
                <p class="font-semibold">{{ $details['contact_name'] ?? $quotation->issuer_name }}</p>
                @if ($phone = $details['contact_phone'] ?? $profile['phone'] ?? null) <p>โทรศัพท์ {{ $phone }}</p> @endif
                @if ($email = $details['contact_email'] ?? $profile['email'] ?? null) <p>{{ $email }}</p> @endif
                <p class="mt-2">งานซ่อม {{ $quotation->device }}</p>
                @if ($quotation->assessment)
                    <p>คาดว่าจะซ่อมเสร็จ {{ $quotation->assessment->expected_completion_at->timezone('Asia/Bangkok')->format('d/m/Y H:i') }} น.</p>
                @endif
            </section>
        </div>

        <table class="quotation-table">
            <caption class="sr-only">รายการสินค้าและบริการ</caption>
            <thead><tr>
                <th scope="col">รายการสินค้า / บริการ</th><th scope="col" class="text-right">จำนวน</th>
                <th scope="col" class="text-right">ราคา/หน่วย</th><th scope="col" class="text-right">ส่วนลด</th>
                <th scope="col" class="text-right">รวม (บาท)</th>
            </tr></thead>
            <tbody>
                @foreach ($quotation->items as $item)
                    <tr>
                        <td><strong>{{ $item['description'] }}</strong><small class="block text-slate-600">{{ RepairQuotation::ITEM_TYPES[$item['type']] }}</small></td>
                        <td class="text-right tabular-nums">{{ number_format($item['quantity']) }} @if (! empty($item['unit']))<small class="block text-slate-600">{{ $item['unit'] }}</small>@endif</td>
                        <td class="text-right tabular-nums">{{ RepairQuotation::formatBaht($item['unit_satang']) }}</td>
                        <td class="text-right tabular-nums">{{ ! empty($item['discount_satang']) ? RepairQuotation::formatBaht($item['discount_satang']) : '—' }}</td>
                        <td class="text-right tabular-nums">{{ RepairQuotation::formatBaht($item['total_satang']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="quotation-closing">
            <div class="quotation-settlement">
                <div class="quotation-conditions">
                    <section><h3>เงื่อนไขการเสนอราคา</h3><p>{{ $quotation->terms }}</p></section>
                    @isset($details['delivery_terms'])
                        <section><h3>กำหนดและเงื่อนไขส่งมอบ</h3><p>{{ $details['delivery_terms'] }}</p></section>
                    @endisset
                    @if (isset($details['payment_channels']) || isset($details['payment_details']) || isset($details['credit_days']))
                        <section>
                            <h3>การชำระเงิน</h3>
                            @isset($details['payment_channels']) <p>{{ $details['payment_channels'] }}</p> @endisset
                            @isset($details['credit_days']) <p>{{ (int) $details['credit_days'] === 0 ? 'ชำระทันที' : 'เครดิต '.$details['credit_days'].' วัน' }}</p> @endisset
                            @isset($details['payment_details']) <p>{{ $details['payment_details'] }}</p> @endisset
                        </section>
                    @endif
                    @isset($details['notes'])
                        <section><h3>หมายเหตุ</h3><p>{{ $details['notes'] }}</p></section>
                    @endisset
                </div>
                <dl class="quotation-totals">
                    <div><dt>รวมมูลค่ารายการ</dt><dd>{{ $money($amounts['subtotal_satang']) }}</dd></div>
                    @if (! empty($amounts['line_discount_satang'])) <div><dt>ส่วนลดรายบรรทัด</dt><dd>−{{ $money($amounts['line_discount_satang']) }}</dd></div> @endif
                    @if (! empty($amounts['document_discount_satang'])) <div><dt>ส่วนลดท้ายเอกสาร</dt><dd>−{{ $money($amounts['document_discount_satang']) }}</dd></div> @endif
                    @if (! empty($amounts['shipping_satang'])) <div><dt>ค่าขนส่ง</dt><dd>{{ $money($amounts['shipping_satang']) }}</dd></div> @endif
                    @if (! empty($amounts['other_charge_satang'])) <div><dt>{{ $amounts['other_label'] ?? 'ค่าใช้จ่ายอื่น' }}</dt><dd>{{ $money($amounts['other_charge_satang']) }}</dd></div> @endif
                    @if ($amounts['vat_mode'] !== 'none')
                        <div><dt>มูลค่าก่อน VAT</dt><dd>{{ $money($amounts['net_before_vat_satang']) }}</dd></div>
                        <div><dt>VAT {{ RepairQuotation::formatBaht($amounts['vat_rate'], '') }}%</dt><dd>{{ $money($amounts['vat_satang']) }}</dd></div>
                    @endif
                    <div class="quotation-total-line"><dt>ยอดรวมใบเสนอราคา</dt><dd data-quotation-total>{{ $money($quotation->total_satang) }}</dd></div>
                    @if (! empty($amounts['withholding_satang']))
                        <div><dt>ภาษีหัก ณ ที่จ่าย</dt><dd>−{{ $money($amounts['withholding_satang']) }}</dd></div>
                        <div class="quotation-payable"><dt>ยอดชำระหลังหักภาษี</dt><dd>{{ $money($amounts['payable_satang']) }}</dd></div>
                    @endif
                </dl>
            </div>

            @if ($quotation->approved_at)
                <p class="quotation-confirmed"><strong>ลูกค้ายืนยันฉบับนี้แล้ว</strong> {{ ($quotation->customer_confirmed_at ?? $quotation->approved_at)->timezone('Asia/Bangkok')->format('d/m/Y H:i') }} น.</p>
            @endif
            <footer class="quotation-signatures">
                <section>
                    <h3>ผู้อนุมัติใบเสนอราคา</h3>
                    <div class="quotation-sign-space">
                        @if ($quotation->signature_filename && $quotation->canRelease())
                            <img src="{{ route('repairs.quotations.signature', [$branch, $repair, $quotation]) }}" alt="ลายเซ็นผู้อนุมัติ">
                        @endif
                    </div>
                    <p>{{ $quotation->canRelease() ? ($quotation->internalReviewer?->name ?? 'ลงชื่อผู้อนุมัติ') : 'ลงชื่อผู้อนุมัติ' }}</p>
                    <p class="text-slate-600">{{ $quotation->canRelease() && $quotation->internal_decided_at ? $releaseDate->timezone('Asia/Bangkok')->format('d/m/Y') : 'วันที่ ____________________' }}</p>
                </section>
                <section>
                    <h3>ลูกค้ายืนยันใบเสนอราคา</h3>
                    <div class="quotation-sign-space"></div>
                    <p>ลงชื่อผู้มีอำนาจ / ผู้ซื้อ</p>
                    <p class="text-slate-600">วันที่ ____________________</p>
                </section>
            </footer>
        </div>
    </article>
</div>
