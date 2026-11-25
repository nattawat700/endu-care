@php($title = \App\Models\CatalogMovement::KINDS[$movement->kind])
<x-layout :title="$title" active-module="DOC">
    <div class="endu-page-head catalog-print-controls">
        <div><a class="endu-text-action" href="{{ route('catalog.stock', [$branch, 'job' => $movement->repair_job_id]) }}">← เอกสารคลัง</a><h1>{{ $title }}</h1></div>
        <div class="flex flex-wrap gap-2">
            @if ($movement->repair && (auth()->user()->can('permission', [$branch, \App\Enums\BranchPermission::ManageCatalogStock])
                || (auth()->user()->can('permission', [$branch, \App\Enums\BranchPermission::ManageRepairParts]) && auth()->user()->can('view', $movement->repair))))
                <a class="button button-secondary" href="{{ route('repairs.parts.create', [$branch, $movement->repair_job_id]) }}">กลับหน้าเบิก–คืน</a>
            @endif
            <button class="button button-primary" type="button" data-print-receipt>พิมพ์เอกสาร</button>
        </div>
    </div>
    <article class="panel mx-auto max-w-3xl space-y-5 p-6 print:max-w-none print:border-0 print:p-0">
        <header class="border-b border-slate-300 pb-4">
            @include('organizations.seller-header', ['profile' => $branch->documentSeller()])
            <h2 class="mt-3 text-2xl font-bold">{{ $title }}</h2>
        </header>
        <dl class="grid grid-cols-2 gap-4 text-sm">
            <div><dt class="detail-label">เลขที่เอกสาร</dt><dd>{{ $movement->reference() }}</dd></div>
            <div><dt class="detail-label">วันและเวลา</dt><dd>{{ $movement->created_at->timezone('Asia/Bangkok')->format('d/m/Y H:i') }}</dd></div>
            <div><dt class="detail-label">คลัง</dt><dd class="wrap-break-word">{{ $movement->warehouse_name }} @if ($movement->location_label) · {{ $movement->location_label }} @endif</dd></div>
            <div><dt class="detail-label">{{ in_array($movement->kind, ['sale', 'sale_return'], true) ? 'บิลขายหรือออเดอร์ต้นทาง' : 'ใบงานต้นทาง' }}</dt><dd class="wrap-break-word">{{ $movement->repair?->reference() ?? $movement->posSale?->reference() ?? $movement->integrationOrder?->reference ?? '—' }}</dd></div>
            @if ($movement->sourceMovement)
                <div><dt class="detail-label">เอกสารคลังต้นทาง</dt>
                    <dd><a class="endu-text-action" href="{{ route('catalog.movements.print', [$branch, $movement->sourceMovement]) }}">{{ $movement->sourceMovement->reference() }}</a></dd>
                </div>
            @endif
            <div><dt class="detail-label">ผู้บันทึก</dt><dd class="wrap-break-word">{{ $movement->actor->name }}</dd></div>
        </dl>
        <table class="w-full border-collapse text-left text-sm">
            <thead><tr class="border-b border-slate-300"><th class="p-2">SKU</th><th class="p-2">รายการ</th><th class="p-2 text-right">จำนวน</th></tr></thead>
            <tbody><tr class="border-b border-slate-200">
                <td class="p-2">{{ $movement->sku }}</td><td class="p-2 wrap-break-word">{{ $movement->product_name }}
                    @if ($movement->stockUnit?->imei)<p>IMEI {{ $movement->stockUnit->imei }}</p>@endif
                    @if ($movement->stockUnit?->serial_number)<p>Serial {{ $movement->stockUnit->serial_number }}</p>@endif
                </td>
                <td class="p-2 text-right">{{ number_format($movement->quantity) }} {{ $movement->unit_name }}</td>
            </tr></tbody>
        </table>
        @can('permission', [$branch, \App\Enums\BranchPermission::Costs])
            @php($cost = $movement->costForQuantity($movement->quantity))
            <div><p class="detail-label">ต้นทุนรวมของรายการ</p><p>{{ $cost === null ? 'ยังไม่มีต้นทุนที่ยืนยันได้' : \App\Models\RepairQuotation::formatBaht($cost).' บาท' }}</p></div>
        @endcan
        @if ($movement->note)<div><p class="detail-label">หมายเหตุ</p><p class="whitespace-pre-wrap wrap-break-word">{{ $movement->note }}</p></div>@endif
        <div class="mt-12 grid grid-cols-2 gap-12 text-center text-sm"><p class="border-t pt-2">ผู้จ่ายอะไหล่</p><p class="border-t pt-2">ผู้รับอะไหล่</p></div>
    </article>
</x-layout>
