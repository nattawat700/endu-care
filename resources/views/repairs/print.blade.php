@php($profile = $branch->documentSeller($repair->seller_profile))

<x-document-print :title="'ใบรับเครื่อง '.$repair->reference()" :pdf-assets="$pdfAssets ?? null">
    <x-slot:controls>
        <div class="mx-auto flex max-w-3xl flex-wrap items-center justify-between gap-3 p-3">
            <a class="button button-secondary" href="{{ route('repairs.show', [$branch, $repair]) }}">← กลับใบงาน</a>
            @if ($isEnduFix)
                <span class="text-sm text-slate-600">{{ $repair->reference() }}</span>
            @endif
            <a class="button button-primary" href="{{ route('repairs.print', [$branch, $repair, 'format' => 'pdf'] + ($showUnlock ? ['show_unlock' => 1] : [])) }}" target="_blank" rel="noopener noreferrer">พิมพ์ใบรับเครื่อง</a>
        </div>
        @if ($isEnduFix)
            @if ($repair->getRawOriginal('device_passcode') !== null || $repair->getRawOriginal('device_unlock_pattern') !== null)
                <div class="mx-auto mb-4 flex max-w-3xl flex-wrap items-center justify-between gap-3 px-3">
                    <p class="text-sm text-slate-600">{{ $showUnlock ? 'ฉบับนี้แสดงข้อมูลปลดล็อกเครื่อง' : 'ฉบับนี้ซ่อนข้อมูลปลดล็อกเครื่อง' }}</p>
                    <a class="button button-secondary" href="{{ route('repairs.print', $showUnlock ? [$branch, $repair] : [$branch, $repair, 'show_unlock' => 1]) }}">{{ $showUnlock ? 'ซ่อนข้อมูลปลดล็อก' : 'แสดงข้อมูลปลดล็อกก่อนพิมพ์' }}</a>
                </div>
            @endif
        @endif
    </x-slot:controls>

    @if ($isEnduFix)
        @include('repairs.endu-fix-receipt')
    @else
        <article class="print-receipt panel mx-auto max-w-3xl p-6 sm:p-10 print:border-0 print:bg-white print:p-0 print:shadow-none">
            <header class="border-b border-slate-300 pb-5">
                @include('organizations.seller-header')
                <h1 class="mt-2 text-2xl font-semibold">ใบรับเครื่องเข้าซ่อม</h1>
                <p class="mt-2">{{ $repair->reference() }} · {{ $repair->created_at->timezone('Asia/Bangkok')->format('d/m/Y H:i') }} น.</p>
            </header>
            <dl class="mt-6 grid grid-cols-2 gap-x-6 gap-y-5">
                <div>
                    <dt class="detail-label">ชื่อลูกค้า</dt>
                    <dd class="wrap-break-word">{{ $repair->customer_name }}</dd>
                </div>
                <div>
                    <dt class="detail-label">เบอร์โทรศัพท์</dt>
                    <dd class="wrap-break-word">{{ $repair->customer_phone }}</dd>
                </div>
                <div>
                    <dt class="detail-label">อุปกรณ์และรุ่น</dt>
                    <dd class="wrap-break-word">{{ $repair->device }}</dd>
                </div>
                <div>
                    <dt class="detail-label">ผู้รับเครื่อง</dt>
                    <dd class="wrap-break-word">{{ $repair->receivedBy->name }}</dd>
                </div>
                <div class="col-span-2">
                    <dt class="detail-label">อาการที่แจ้ง</dt>
                    <dd class="whitespace-pre-wrap wrap-break-word">{{ $repair->issue }}</dd>
                </div>
            </dl>
            <div class="mt-12 grid grid-cols-2 gap-8 text-center text-sm">
                <p class="border-t border-slate-400 pt-2">ลงชื่อผู้ส่งเครื่อง</p>
                <p class="border-t border-slate-400 pt-2">ลงชื่อผู้รับเครื่อง</p>
            </div>
        </article>
    @endif
</x-document-print>
