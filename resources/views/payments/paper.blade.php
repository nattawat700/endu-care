@php
    use App\Models\RepairPayment;
    use App\Models\RepairQuotation;
    use App\Support\PrivateDisplay;

    $document = $payment->document;
    $profile = $branch->documentSeller($document['seller']);
    $buyer = $document['buyer'];
    $money = static fn (int $amount): string => RepairQuotation::formatBaht($amount).' บาท';
    $receivedAt = ($payment->verified_at ?? $payment->created_at)->timezone('Asia/Bangkok');
@endphp

<article class="panel repair-quotation payment-paper">
    <header class="quotation-doc-head">
        <div class="quotation-seller">
            @include('organizations.seller-header')
        </div>
        <div class="quotation-identity">
            <h1 class="quotation-title">{{ $payment->title() }} <span lang="en">/ {{ RepairPayment::TYPES[$payment->document_type][1] }}</span></h1>
            <dl class="quotation-identity-data">
                <div><dt>เลขที่เอกสาร</dt><dd class="font-bold tabular-nums">{{ $payment->reference() }}</dd></div>
                <div><dt>วันที่ออกเอกสาร</dt><dd>{{ $receivedAt->format('d/m/Y') }}</dd></div>
                <div><dt>ใบงานอ้างอิง</dt><dd>{{ $repair->reference() }}</dd></div>
                @if ($document['quotation_reference'])
                    <div><dt>ใบเสนอราคา</dt><dd>{{ $document['quotation_reference'] }}</dd></div>
                @endif
                <div><dt>เลขรายการรับเงิน</dt><dd>{{ $payment->paymentReference() }}</dd></div>
            </dl>
        </div>
    </header>

    <div class="quotation-parties">
        <section>
            <h3>ผู้ชำระเงิน</h3>
            <p class="font-semibold">{{ $document['buyer_name'] }}</p>
            @if ($canViewPrivateCustomerDetails)
                @isset($buyer['buyer_office']) <p>{{ $buyer['buyer_office'] }}</p> @endisset
                @isset($buyer['buyer_address']) <p class="whitespace-pre-wrap">{{ $buyer['buyer_address'] }}</p> @endisset
                @isset($buyer['buyer_tax_id']) <p>เลขผู้เสียภาษี {{ $buyer['buyer_tax_id'] }}</p> @endisset
                @isset($buyer['buyer_email']) <p>{{ $buyer['buyer_email'] }}</p> @endisset
            @endif
            @if ($buyer['buyer_phone'])
                <p>โทรศัพท์ {{ $canViewPrivateCustomerDetails ? $buyer['buyer_phone'] : PrivateDisplay::phone($buyer['buyer_phone']) }}</p>
            @endif
        </section>
        <section>
            <h3>ผู้รับเงิน</h3>
            <p class="font-semibold">{{ $document['receiver'] }}</p>
            @isset($profile['phone']) <p>โทรศัพท์ {{ $profile['phone'] }}</p> @endisset
            @isset($profile['email']) <p>{{ $profile['email'] }}</p> @endisset
            <p class="mt-2">งานซ่อม {{ $document['device'] }}</p>
        </section>
    </div>

    @include('quotations.line-items', ['items' => $document['items'], 'itemsCaption' => 'รายการสินค้าและบริการตามยอดเต็ม'])

    <div class="quotation-closing">
        <div class="quotation-settlement">
            <div class="quotation-conditions">
                <section>
                    <h3>การรับชำระ</h3>
                    @forelse ($payment->lines as $line)
                        <p>{{ $line->label }} {{ $money($line->amount_satang) }}</p>
                        @if ($line->tendered_satang !== null && $line->changeSatang() > 0)
                            <p>รับเงินสด {{ $money($line->tendered_satang) }} · เงินทอน {{ $money($line->changeSatang()) }}</p>
                        @endif
                    @empty
                        <p>{{ RepairPayment::CHANNELS[$payment->channel] }} · {{ $receivedAt->format('d/m/Y H:i') }} น.</p>
                    @endforelse
                </section>
                @if ($document['note'])
                    <section><h3>หมายเหตุ</h3><p>{{ $document['note'] }}</p></section>
                @endif
            </div>
            <dl class="quotation-totals">
                @if ($amounts = $document['amount_breakdown'])
                    @include('quotations.amount-breakdown')
                @endif
                <div><dt>ยอดเต็มของใบงาน</dt><dd>{{ $money($payment->job_total_satang) }}</dd></div>
                @if ($payment->payable_satang !== $payment->job_total_satang)
                    <div><dt>หัก ณ ที่จ่าย</dt><dd>−{{ $money($payment->job_total_satang - $payment->payable_satang) }}</dd></div>
                    <div><dt>ยอดที่ต้องชำระสุทธิ</dt><dd>{{ $money($payment->payable_satang) }}</dd></div>
                @endif
                @if ($payment->paid_before_satang > 0)
                    <div><dt>รับชำระก่อนครั้งนี้</dt><dd>{{ $money($payment->paid_before_satang) }}</dd></div>
                @endif
                <div class="quotation-total-line"><dt>รับเงินจริงครั้งนี้</dt><dd>{{ $money($payment->amount_satang) }}</dd></div>
                <div class="quotation-payable"><dt>คงเหลือหลังรับเงิน</dt><dd>{{ $money($payment->remainingSatang()) }}</dd></div>
            </dl>
        </div>

        <footer class="quotation-signatures">
            <section>
                <h3>ผู้รับเงิน</h3>
                <div class="quotation-sign-space"></div>
                <p>{{ $document['receiver'] }}</p>
                <p class="text-slate-600">{{ $receivedAt->format('d/m/Y') }}</p>
            </section>
            <section>
                <h3>ผู้ชำระเงิน</h3>
                <div class="quotation-sign-space"></div>
                <p>ลงชื่อผู้ชำระเงิน</p>
                <p class="text-slate-600">วันที่ ____________________</p>
            </section>
        </footer>
    </div>
</article>
