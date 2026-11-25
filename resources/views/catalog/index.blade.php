@php
    use App\Enums\BranchPermission;
    use App\Models\CatalogProduct;
    use App\Models\RepairQuotation;
@endphp
<x-layout :title="$context === 'pos' ? 'สินค้าและราคาหน้าร้าน' : 'สินค้าและราคา'" :active-module="$context === 'pos' ? 'POS' : 'CAT'">
    <div class="endu-page-head">
        <div><h1>{{ $context === 'pos' ? 'สินค้าและราคาหน้าร้าน' : 'สินค้าและราคา' }}</h1><p>{{ $branch->name }}</p></div>
        <div class="flex flex-wrap gap-2">
            <a class="button button-secondary" href="{{ route('catalog.transfer', $branch) }}">{{ $role->allows(BranchPermission::ManageCatalog) ? 'นำเข้า / ส่งออก' : 'ส่งออกรายการ' }}</a>
            @if ($role->allows(BranchPermission::ReadPartsDocuments))
                <a class="button button-secondary" href="{{ route('catalog.stock', $branch) }}">คลังและเอกสารอะไหล่</a>
            @endif
            @if ($role->allows(BranchPermission::ManageCatalog))
                <a class="button button-primary" href="{{ route('catalog.create', $branch) }}">เพิ่มสินค้า</a>
            @endif
        </div>
    </div>
    <form method="GET" class="panel mb-5 grid gap-4 p-4 sm:grid-cols-2 xl:grid-cols-3">
        @foreach (['q' => 'ชื่อ / SKU / บาร์โค้ด / IMEI / Serial', 'category' => 'หมวด', 'brand' => 'ยี่ห้อ', 'unit' => 'หน่วยนับ', 'model' => 'รุ่นที่รองรับ'] as $key => $label)
            <label class="min-w-0">
                <span class="field-label">{{ $label }}</span>
                <input class="field-input" type="search" name="{{ $key }}" value="{{ $filters[$key] }}" maxlength="140">
            </label>
        @endforeach
        <div class="flex flex-wrap items-end gap-2">
            <button class="button button-primary" type="submit">ค้นสินค้า</button>
            <a class="button button-secondary" href="{{ route($context === 'pos' ? 'catalog.pos' : 'catalog.index', $branch) }}">ล้างตัวกรอง</a>
        </div>
    </form>
    @if ($scan)<p class="panel mb-4 p-3 text-sm">บาร์โค้ด {{ $scan->code }} · หน่วย {{ $scan->unit }} · 1 หน่วยเท่ากับ {{ $scan->multiplier }} หน่วยฐาน · ราคาปลีก {{ $scanRetail }} บาท/หน่วยที่สแกน</p>@endif
    <section class="panel endu-table-scroll">
        <table class="w-full text-left text-sm">
            <thead class="bg-slate-100"><tr>
                <th class="p-3" scope="col">สินค้า</th><th class="p-3" scope="col">หมวด / ยี่ห้อ</th><th class="p-3" scope="col">ราคาปลีกต่อหน่วยฐาน</th><th class="p-3" scope="col">พร้อมขาย</th>
            </tr></thead>
            <tbody class="divide-y divide-slate-200">
                @forelse ($products as $product)
                    <tr>
                        <td class="p-3"><div class="flex items-center gap-3">
                            @if ($product->image)<img class="size-12 rounded-md bg-slate-100 object-contain" loading="lazy" src="{{ route('catalog.image', [$branch, $product]) }}" alt="">@endif
                            <div class="min-w-0"><a class="endu-text-action wrap-break-word" href="{{ route('catalog.show', [$branch, $product]) }}">{{ $product->name }}</a>
                                <p class="text-xs text-slate-600">{{ $product->sku }} · {{ CatalogProduct::KINDS[$product->kind] }} · {{ $product->unit }}</p></div>
                        </div></td>
                        <td class="p-3">{{ $product->category ?? '—' }} / {{ $product->brand ?? '—' }}</td>
                        <td class="p-3 whitespace-nowrap">{{ RepairQuotation::formatBaht($product->retail_satang) }} บาท</td>
                        <td class="p-3">{{ match ($product->tracking) { 'quantity' => number_format($product->balances_sum_quantity ?? 0), 'serialized' => number_format($product->available_units_count), default => '—' } }}</td>
                    </tr>
                @empty
                    <tr><td class="p-5 text-slate-600" colspan="4">ไม่พบสินค้าตามตัวกรอง</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
    <div class="mt-4">{{ $products->links() }}</div>
</x-layout>
