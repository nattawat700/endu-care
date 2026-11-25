@php
    use App\Models\CatalogProduct;
    use App\Models\RepairQuotation;

    $profile = $sellerProfile;
    $paidAt = $sale->paid_at->timezone('Asia/Bangkok');
    $items = $sale->lines->map(fn ($line) => [
        'description' => $line->name.' · '.$line->sku.($line->imei ? ' · IMEI '.$line->imei : '').($line->serial_number ? ' · Serial '.$line->serial_number : ''),
        'type_label' => CatalogProduct::KINDS[$line->kind],
        'quantity' => $line->quantity, 'unit' => $line->unit_name, 'unit_satang' => $line->unit_satang, 'discount_satang' => $line->discount_satang,
        'total_satang' => $line->quantity * $line->unit_satang - $line->discount_satang,
    ])->all();
    $money = fn (int $amount) => RepairQuotation::formatBaht($amount).' บาท';
@endphp

<x-document-print :title="'ใบเสร็จหน้าร้าน '.$sale->reference()" :pdf-assets="$pdfAssets ?? null" data-payment-print-page data-a4-print-page>
    <x-slot:controls>
        <nav class="mx-auto flex max-w-[860px] flex-wrap items-center justify-between gap-3 p-3" aria-label="พิมพ์เอกสาร">
            <a class="endu-text-action" href="{{ route('pos.show', [$branch, $sale]) }}">← กลับบิลขาย</a>
            <a class="button button-primary" href="{{ route('pos.receipt', [$branch, $sale, 'format' => 'pdf']) }}" target="_blank" rel="noopener noreferrer">พิมพ์ใบเสร็จ</a>
        </nav>
    </x-slot:controls>
    <article class="panel repair-quotation payment-paper">
        <header class="quotation-doc-head">
            <div class="quotation-seller">@include('organizations.seller-header')</div>
            <div class="quotation-identity">
                <h1 class="quotation-title">ใบเสร็จหน้าร้าน <span lang="en">/ Receipt</span></h1>
                <dl class="quotation-identity-data">
                    <div><dt>เลขที่เอกสาร</dt><dd>{{ $sale->reference() }}</dd></div>
                    <div><dt>วันที่ออกเอกสาร</dt><dd>{{ $paidAt->format('d/m/Y H:i') }}</dd></div>
                    <div><dt>ผู้รับเงิน</dt><dd>{{ $sale->cashier->name }}</dd></div>
                </dl>
            </div>
        </header>
        <div class="quotation-parties">
            <section>
                <h3>ลูกค้า</h3>
                <p class="font-semibold">{{ $sale->buyer['name'] }}</p>
                @if ($canViewBuyer)
                    @isset($sale->buyer['address'])<p class="whitespace-pre-wrap">{{ $sale->buyer['address'] }}</p>@endisset
                    @isset($sale->buyer['phone'])<p>โทรศัพท์ {{ $sale->buyer['phone'] }}</p>@endisset
                    @isset($sale->buyer['email'])<p>{{ $sale->buyer['email'] }}</p>@endisset
                @endif
            </section>
        </div>
        @include('quotations.line-items', ['itemsCaption' => 'รายการขาย'])
        <div class="quotation-closing">
            <div class="quotation-settlement">
                <div class="quotation-conditions"><section><h3>การชำระเงิน</h3><p>เงินสด</p></section></div>
                <dl class="quotation-totals">
                    <div><dt>รวมก่อนส่วนลด</dt><dd>{{ $money($sale->subtotal_satang) }}</dd></div>
                    <div><dt>ส่วนลดรายบรรทัด</dt><dd>{{ $money($sale->line_discount_satang) }}</dd></div>
                    <div><dt>ส่วนลดท้ายบิล</dt><dd>{{ $money($sale->document_discount_satang) }}</dd></div>
                    <div class="quotation-payable"><dt>ยอดสุทธิ</dt><dd>{{ $money($sale->total_satang) }}</dd></div>
                    <div><dt>รับเงินสด</dt><dd>{{ $money($tender->tendered_satang) }}</dd></div>
                    <div><dt>เงินทอน</dt><dd>{{ $money($tender->tendered_satang - $tender->amount_satang) }}</dd></div>
                </dl>
            </div>
        </div>
    </article>
</x-document-print>
