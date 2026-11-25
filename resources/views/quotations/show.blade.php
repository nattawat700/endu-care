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
            @if ($isLatest && $quotation->approved_at && $quotation->canRelease())
                @can('permission', [$branch, \App\Enums\BranchPermission::ManagePaymentDocuments])
                    <a class="button button-secondary" href="{{ route('repairs.payments.index', [$branch, $repair]) }}">รับเงินและออกใบเสร็จ</a>
                @endcan
            @endif
            @if ($canDraftQuote && $isLatest && $repair->status->allowsQuotation())
                <a class="button button-secondary" href="{{ route('repairs.quotations.create', [$branch, $repair]) }}">ออกฉบับแก้</a>
            @endif
            @if ($canPrintQuote && ($quotation->canRelease() || $quotation->voided_at))
                <a class="button button-primary" href="{{ route('repairs.quotations.print', [$branch, $repair, $quotation, 'format' => 'pdf']) }}" target="_blank" rel="noopener noreferrer"><x-workspace-icon name="doc" /> {{ $quotation->voided_at ? 'พิมพ์สำเนาที่ถูกยกเลิก' : 'พิมพ์ใบเสนอราคา' }}</a>
            @endif
        </div>
    </div>

    @include('organizations.errors')
    @if ($quotation->voided_at)
        <section class="panel mb-5 p-4 text-sm" aria-labelledby="quotation-void-title">
            <h2 id="quotation-void-title" class="font-bold text-red-800">ยกเลิกใบเสนอราคาแล้ว</h2>
            <p class="mt-1">{{ $quotation->voided_at->timezone('Asia/Bangkok')->format('d/m/Y H:i') }}
                @if ($canViewInternalNotes) · {{ $quotation->voidedBy->name }} @endif
            </p>
            @if ($canViewInternalNotes)<p class="mt-1 whitespace-pre-wrap wrap-break-word">{{ $quotation->void_reason }}</p>@endif
            @can('viewAny', \App\Models\AuditEvent::class)
                <a class="endu-text-action mt-2 inline-block" href="{{ route('audit.index', ['branch' => $branch->id, 'subject' => 'quotation', 'reference' => $quotation->reference()]) }}">ดูประวัติเอกสารนี้</a>
            @endcan
        </section>
    @elseif ($canCancelQuote)
        <details class="panel mb-5 p-4" @if ($errors->has('void_reason')) open @endif>
            <summary class="cursor-pointer text-sm font-semibold text-red-800">ยกเลิกใบเสนอราคา</summary>
            <form method="POST" action="{{ route('repairs.quotations.void', [$branch, $repair, $quotation]) }}" class="mt-3 max-w-xl space-y-3">
                @csrf
                <x-repair-input name="void_reason" label="เหตุผลที่ยกเลิก" :limit="500" :rows="2" required />
                <button type="submit" class="button button-secondary">ยืนยันยกเลิกใบเสนอราคา</button>
            </form>
        </details>
    @endif

    @if ($comparisonQuote)
        @php($difference = $quotation->total_satang - $comparisonQuote->total_satang)
        <section class="quotation-screen-only panel mb-5 p-5" aria-labelledby="quotation-revision-title">
            <h2 id="quotation-revision-title" class="text-lg font-bold">เปรียบเทียบราคา</h2>
            <dl class="mt-3 grid gap-3 sm:grid-cols-3">
                <div>
                    <dt class="detail-label">{{ match (true) {
                        $comparisonQuote->voided_at !== null => 'ยอดของฉบับที่ยกเลิก',
                        $comparisonQuote->approved_at !== null => 'ยอดที่ลูกค้าอนุมัติก่อนฉบับนี้',
                        default => 'ยอดของฉบับก่อนหน้า',
                    } }}</dt>
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
