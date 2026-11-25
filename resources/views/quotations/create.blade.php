@php
    use App\Models\RepairQuotation;
    use App\Support\QuotationAmounts;

    $items = \App\Support\RepairPriceItems::formValues($sourceAssessment?->items ?? $latest?->items ?? [], forQuotation: true);
    $terms = old('terms', $latest?->terms);
    $baseRevision = old('base_revision', $latest?->revision ?? 0);
    $assessmentRevision = old('assessment_revision', $sourceAssessment?->revision);
    $seller = $branch->documentSeller();
    $deadline = $latest?->valid_until?->toDateString();
    $today = now('Asia/Bangkok')->toDateString();
    $defaults = ($latest?->document_details ?? []) + [
        'buyer_name' => $latest?->customer_name ?? $repair->customer_name,
        'buyer_phone' => $repair->customer_phone,
        'buyer_address' => $repair->customer_address,
        'contact_name' => auth()->user()->name,
        'contact_phone' => $seller['phone'] ?? null,
        'contact_email' => $seller['email'] ?? null,
        'valid_until' => $deadline && $deadline >= $today ? $deadline : now('Asia/Bangkok')->addDays(14)->toDateString(),
    ];
    $breakdown = $latest?->amount_breakdown ?? [];
    foreach (array_keys(QuotationAmounts::ADJUSTMENTS) as $key) {
        $defaults[$key] = RepairQuotation::formatBaht($breakdown[$key.'_satang'] ?? 0, '');
    }
    $defaults['vat_mode'] = $breakdown['vat_mode'] ?? 'none';
    $defaults['vat_rate'] = isset($breakdown['vat_rate']) ? RepairQuotation::formatBaht($breakdown['vat_rate'], '') : '';
    $defaults['other_label'] = $breakdown['other_label'] ?? '';
    $value = static function (string $key) use ($defaults): string {
        $input = old($key, $defaults[$key] ?? '');

        return is_scalar($input) ? (string) $input : '';
    };
    $stale = $errors->hasAny(['base_revision', 'assessment_revision']);
@endphp

<x-layout :title="$latest ? 'ออกใบเสนอราคาฉบับแก้' : 'ออกใบเสนอราคา'">
    <div class="endu-page-head">
        <div>
            <a class="endu-text-action" href="{{ route('repairs.quotations.index', [$branch, $repair]) }}">← ประวัติใบเสนอราคา</a>
            <h1 class="mt-2">{{ $latest ? 'ออกใบเสนอราคาฉบับแก้' : 'ออกใบเสนอราคา' }}</h1>
            <p>{{ $repair->reference() }} · {{ $repair->customer_name }} · {{ $repair->device }}</p>
        </div>
    </div>
    @if ($sourceAssessment)
        <p class="mb-4 text-sm text-slate-600">ตั้งต้นจากผลประเมินครั้งที่ {{ $sourceAssessment->revision }} · คาดว่าจะซ่อมเสร็จ {{ $sourceAssessment->expected_completion_at->timezone('Asia/Bangkok')->format('d/m/Y H:i') }} น.</p>
    @elseif ($latest)
        <p class="mb-4 text-sm text-slate-600">คัดลอกจาก {{ $latest->reference() }} เมื่อบันทึก ให้ส่งฉบับใหม่ขออนุมัติจากผู้จัดการก่อนแจ้งลูกค้า</p>
        @if ($latest->assessment)
            <p class="mb-4 text-sm text-slate-600">คาดว่าจะซ่อมเสร็จ {{ $latest->assessment->expected_completion_at->timezone('Asia/Bangkok')->format('d/m/Y H:i') }} น.</p>
        @endif
        @if ($latestAssessment)
            <a class="endu-text-action mb-4" href="{{ route('repairs.quotations.create', [$branch, $repair, 'from_assessment' => 1]) }}">เริ่มใหม่จากผลประเมินล่าสุด</a>
        @endif
    @endif
    @if ($errors->any())
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-4 text-red-800" role="alert">
            <ul class="list-disc space-y-1 pl-4">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
            @if ($stale)
                <a class="mt-2 inline-block font-semibold underline" href="{{ route('repairs.quotations.create', [$branch, $repair, 'from_assessment' => $sourceAssessment ? 1 : null]) }}">โหลดข้อมูลฉบับล่าสุด</a>
            @endif
        </div>
    @endif
    <form method="POST" data-price-form action="{{ route('repairs.quotations.store', [$branch, $repair]) }}" class="panel space-y-5 p-5 sm:p-7">
        @csrf
        <input type="hidden" name="submission_id" value="{{ $errors->has('submission_id') ? $submissionId : old('submission_id', $submissionId) }}">
        <input type="hidden" name="base_revision" value="{{ is_scalar($baseRevision) ? $baseRevision : ($latest?->revision ?? 0) }}">
        <input type="hidden" name="assessment_revision" value="{{ is_scalar($assessmentRevision) ? $assessmentRevision : '' }}">
        @include('quotations.buyer-fields')
        @can('permission', [$branch, \App\Enums\BranchPermission::ReadCatalog])
            <section class="grid gap-3 rounded-lg border border-slate-200 p-4" data-quotation-catalog="{{ route('repairs.catalog-lookup', [$branch, $repair]) }}">
                <h2 class="font-semibold">เลือกสินค้าจากทะเบียน</h2>
                <div class="grid items-end gap-2 sm:grid-cols-[1fr_1fr_auto]">
                    <label><span class="field-label">ชื่อ / SKU / บาร์โค้ด</span><input class="field-input" data-catalog-search maxlength="140"></label>
                    <label><span class="field-label">รุ่นที่รองรับ</span><input class="field-input" data-catalog-model maxlength="100"></label>
                    <button type="button" class="button button-secondary" data-catalog-find>ค้นสินค้า</button>
                </div>
                <div class="flex flex-wrap items-end gap-2" data-catalog-results hidden>
                    <label class="min-w-0 grow"><span class="field-label">ผลการค้นหา</span><select class="field-input" data-catalog-product></select></label>
                    <label><span class="field-label">กลุ่มราคา</span><select class="field-input" data-catalog-group></select></label>
                    <button type="button" class="button button-secondary" data-catalog-add>เพิ่มลงรายการ</button>
                </div>
                <p class="text-sm text-slate-600" data-catalog-status role="status"></p>
            </section>
        @endcan
        @include('repairs.price-items', ['totalLabel' => 'ยอดรวมใบเสนอราคา', 'forQuotation' => true])
        @include('quotations.amount-fields')
        @if ($comparisonQuote)
            <dl class="grid gap-3 rounded-lg bg-slate-50 p-3 sm:grid-cols-2">
                <div>
                    <dt class="detail-label">{{ $comparisonQuote->approved_at ? 'ยอดที่ลูกค้าอนุมัติล่าสุด' : 'ยอดของฉบับก่อนหน้า' }} · {{ $comparisonQuote->reference() }}</dt>
                    <dd class="tabular-nums">{{ RepairQuotation::formatBaht($comparisonQuote->total_satang) }} บาท</dd>
                </div>
                <div>
                    <dt class="detail-label" id="quotation-price-difference">ส่วนต่างจากยอดเดิม (บาท)</dt>
                    <dd><output class="font-bold tabular-nums" data-price-difference="{{ $comparisonQuote->total_satang }}" aria-labelledby="quotation-price-difference">—</output></dd>
                </div>
            </dl>
        @endif
        @if ($latest)
            <div>
                <label class="field-label" for="quote-revision-reason">เหตุผลที่แก้ใบเสนอราคา</label>
                <textarea class="field-input" id="quote-revision-reason" name="revision_reason" rows="2" required maxlength="1500">{{ is_string(old('revision_reason')) ? old('revision_reason') : '' }}</textarea>
            </div>
        @endif
        <div>
            <label class="field-label" for="quote-terms">เงื่อนไขที่ให้ลูกค้ายืนยัน</label>
            <textarea class="field-input" id="quote-terms" name="terms" rows="4" required maxlength="3000" placeholder="เช่น ระยะเวลาซ่อม เงื่อนไขประกัน หรือขอบเขตงาน">{{ is_string($terms) ? $terms : '' }}</textarea>
        </div>
        @include('quotations.payment-fields')
        <div class="flex flex-wrap justify-end gap-2 border-t border-slate-200 pt-4">
            <a class="button button-secondary" href="{{ route('repairs.quotations.index', [$branch, $repair]) }}">ยกเลิก</a>
            <button class="button button-primary" type="submit" @disabled($stale)>บันทึกร่างใบเสนอราคา</button>
        </div>
    </form>
</x-layout>
