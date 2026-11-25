@php
    use App\Enums\BranchPermission;
    $labels = ['sku' => 'SKU', 'name' => 'ชื่อ', 'kind' => 'ประเภท', 'tracking' => 'วิธีนับสต๊อก', 'category' => 'หมวด', 'brand' => 'ยี่ห้อ', 'unit' => 'หน่วยนับ', 'models' => 'รุ่นที่รองรับ', 'barcodes' => 'บาร์โค้ดตามหน่วย'] + \App\Support\CatalogProductData::PRICES;
@endphp
<x-layout title="นำเข้าและส่งออกรายการสินค้า" active-module="CAT">
    <div class="endu-page-head">
        <div><a class="endu-text-action" href="{{ route('catalog.index', $branch) }}">← สินค้าและราคา</a><h1>นำเข้าและส่งออกรายการสินค้า</h1><p>{{ $branch->name }}</p></div>
    </div>
    @if ($errors->any())
        <ul class="panel mb-4 space-y-1 p-4 text-sm text-red-800" role="alert">
            @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
        </ul>
    @endif
    <div class="grid gap-5 lg:grid-cols-2">
        @can('permission', [$branch, BranchPermission::ManageCatalog])
            <section class="panel min-w-0 space-y-3 p-5">
                <h2 class="text-lg font-semibold">นำเข้าจาก Excel หรือ CSV</h2>
                <a class="endu-text-action" href="{{ route('catalog.template', $branch) }}">ดาวน์โหลดแม่แบบ CSV เปิดด้วย Excel</a>
                <p class="text-sm text-slate-600">อ่านชีตแรก สูงสุด 100 รายการต่อไฟล์ ขนาดไม่เกิน 2 MB แถวที่ SKU ตรงกันจะอัปเดตรายการเดิม หากใส่คอลัมน์รุ่นหรือบาร์โค้ด ระบบจะแทนรายการในคอลัมน์นั้นทั้งหมด</p>
                <form method="POST" enctype="multipart/form-data" action="{{ route('catalog.import.preview', $branch) }}" class="space-y-3">
                    @csrf
                    <label class="block"><span class="field-label">ไฟล์สินค้า</span><input class="field-input" type="file" name="sheet" accept=".csv,.xlsx" required></label>
                    <button class="button button-primary" type="submit">ตรวจไฟล์ก่อนนำเข้า</button>
                </form>
            </section>
        @endcan
        <section class="panel space-y-3 p-5">
            <h2 class="text-lg font-semibold">ส่งออก CSV</h2>
            <form class="space-y-3" method="GET" action="{{ route('catalog.export', $branch) }}">
                <fieldset class="grid gap-2 sm:grid-cols-2">
                    <legend class="mb-2 text-sm text-slate-600">เลือกคอลัมน์ที่ต้องการ</legend>
                    @foreach ($allowed as $column)
                        <label class="flex items-center gap-2"><input type="checkbox" name="columns[]" value="{{ $column }}" @checked(in_array($column, ['sku', 'name', 'retail']))> {{ $labels[$column] }}</label>
                    @endforeach
                </fieldset>
                <button class="button button-primary" type="submit">ดาวน์โหลด CSV</button>
            </form>
        </section>
    </div>
    @can('permission', [$branch, BranchPermission::ManageCatalog])
        @if ($preview)
            @php($invalid = collect($preview['rows'])->contains(fn ($row) => isset($row['errors'])))
            <section class="panel mt-5 p-5">
                <h2 class="text-lg font-semibold">ผลตรวจไฟล์ก่อนนำเข้า</h2>
                <ul class="mt-3 divide-y divide-slate-200">
                    @foreach ($preview['rows'] as $number => $row)
                        <li class="py-3 text-sm wrap-break-word">
                            <strong>แถว {{ $number }} · {{ $row['sku'] ?: 'ไม่มี SKU' }}</strong>
                            @if (isset($row['errors']))
                                <span class="text-red-800">ตรวจสอบแถวนี้</span>
                                <ul class="list-disc pl-5 text-red-800">@foreach ($row['errors'] as $messages) @foreach ($messages as $message)<li>{{ $message }}</li>@endforeach @endforeach</ul>
                            @else
                                <span>{{ $row['action'] === 'create' ? 'เพิ่มใหม่' : 'อัปเดต' }}</span>
                                <p>{{ $row['data']['product']['name'] }} · {{ \App\Models\RepairQuotation::formatBaht($row['data']['product']['retail_satang']) }} บาท · {{ $row['data']['product']['unit'] }}</p>
                            @endif
                        </li>
                    @endforeach
                </ul>
                @if (! $invalid)
                    <form method="POST" action="{{ route('catalog.import.apply', $branch) }}">
                        @csrf
                        <input type="hidden" name="token" value="{{ $preview['token'] }}">
                        <button class="button button-primary" type="submit">ยืนยันนำเข้า {{ count($preview['rows']) }} รายการ</button>
                    </form>
                @endif
            </section>
        @endif
    @endcan
</x-layout>
