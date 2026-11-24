@php
    use App\Models\RepairQuotation;
@endphp

<x-layout :title="$quotation->reference()">
    <div class="endu-page-head quotation-screen-only">
        <div>
            <a class="endu-text-action" href="{{ route('repairs.quotations.index', [$branch, $repair]) }}">← ประวัติใบเสนอราคา</a>
            <h1 class="mt-2">ใบเสนอราคา</h1>
            <p>{{ $quotation->reference() }} · {{ $repair->reference() }}</p>
            @if ($quotation->assessment)
                <p>ตั้งต้นจากผลประเมินครั้งที่ {{ $quotation->assessment->revision }}</p>
            @endif
        </div>
        <div class="endu-actions">
            @if ($canDraftQuote && $isLatest && $repair->status->allowsQuotation())
                <a class="button button-secondary" href="{{ route('repairs.quotations.create', [$branch, $repair]) }}">ออกฉบับแก้</a>
            @endif
            @if ($canPrintQuote && $quotation->canRelease())
                <a class="button button-primary" href="{{ route('repairs.quotations.print', [$branch, $repair, $quotation]) }}"><x-workspace-icon name="doc" /> พิมพ์ใบเสนอราคา</a>
            @endif
        </div>
    </div>

    @if ($comparisonQuote)
        @php($difference = $quotation->total_satang - $comparisonQuote->total_satang)
        <section class="quotation-screen-only panel mb-5 p-5" aria-labelledby="quotation-revision-title">
            <h2 id="quotation-revision-title" class="text-lg font-bold">เปรียบเทียบราคา</h2>
            <dl class="mt-3 grid gap-3 sm:grid-cols-3">
                <div>
                    <dt class="detail-label">{{ $comparisonQuote->approved_at ? 'ยอดที่ลูกค้าอนุมัติก่อนฉบับนี้' : 'ยอดของฉบับก่อนหน้า' }}</dt>
                    <dd>
                        <a class="endu-text-action" href="{{ route('repairs.quotations.show', [$branch, $repair, $comparisonQuote]) }}">{{ $comparisonQuote->reference() }}</a>
                        · <span class="tabular-nums">{{ RepairQuotation::formatBaht($comparisonQuote->total_satang) }} บาท</span>
                    </dd>
                </div>
                <div><dt class="detail-label">ยอดของฉบับนี้</dt><dd class="tabular-nums">{{ RepairQuotation::formatBaht($quotation->total_satang) }} บาท</dd></div>
                <div>
                    <dt class="detail-label">ส่วนต่าง</dt>
                    <dd class="font-bold tabular-nums">{{ $difference === 0 ? 'ยอดรวมเท่าเดิม' : ($difference > 0 ? '+' : '−').RepairQuotation::formatBaht(abs($difference)).' บาท' }}</dd>
                </div>
                @if ($canViewInternalNotes && $quotation->revision_reason)
                    <div class="sm:col-span-3"><dt class="detail-label">เหตุผลที่แก้ใบเสนอราคา</dt><dd class="whitespace-pre-wrap wrap-break-word">{{ $quotation->revision_reason }}</dd></div>
                @endif
            </dl>
        </section>
    @endif

    @if ($canViewInternalNotes)
        @include('quotations.internal-approval')
    @endif

    @include('quotations.document')

    @include('quotations.customer-approval')
</x-layout>
