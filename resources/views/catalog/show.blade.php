@php
    use App\Enums\BranchPermission;
    use App\Models\CatalogProduct;
    use App\Models\CatalogWarehouse;
    use App\Models\RepairQuotation;
    $canGroups = auth()->user()->can('permission', [$branch, BranchPermission::CatalogGroupPrices]);
@endphp
<x-layout :title="$product->sku" active-module="CAT">
    <div class="endu-page-head">
        <div class="min-w-0"><a class="endu-text-action" href="{{ route('catalog.index', $branch) }}">← สินค้าและราคา</a><h1 class="wrap-break-word">{{ $product->name }}</h1><p>{{ $product->sku }} · {{ $branch->name }}</p></div>
        @can('permission', [$branch, BranchPermission::ManageCatalog])
            <a href="{{ route('catalog.edit', [$branch, $product]) }}" class="button button-primary">แก้ไขสินค้า</a>
        @endcan
    </div>
    <div class="grid gap-5 lg:grid-cols-2">
        <section class="panel min-w-0 space-y-3 p-5">
            <h2 class="text-lg font-semibold">ข้อมูลสินค้า</h2>
            @if ($product->image)<img src="{{ route('catalog.image', [$branch, $product]) }}" alt="{{ $product->name }}" class="max-h-52 w-full rounded-md bg-slate-50 object-contain">@endif
            <dl class="grid gap-3 sm:grid-cols-2">
                @foreach (['ประเภท' => CatalogProduct::KINDS[$product->kind], 'สต๊อก' => CatalogProduct::TRACKING[$product->tracking], 'หมวด' => $product->category, 'ยี่ห้อ' => $product->brand, 'หน่วยนับ' => $product->unit] as $label => $value)
                    <div class="min-w-0"><dt class="detail-label">{{ $label }}</dt><dd class="wrap-break-word">{{ $value ?? '—' }}</dd></div>
                @endforeach
                @foreach (\App\Support\CatalogProductData::PRICES as $key => $label)
                    @continue($key === 'cost' && auth()->user()->cannot('permission', [$branch, BranchPermission::Costs]))
                    @continue(in_array($key, ['dealer', 'partner'], true) && ! $canGroups)
                    @php($amount = $product->{$key.'_satang'})
                    <div><dt class="detail-label">{{ $label }}</dt><dd>{{ $amount === null ? '—' : RepairQuotation::formatBaht($amount).' บาท' }}</dd></div>
                @endforeach
            </dl>
            @if ($product->compatibilities->isNotEmpty())
                <p class="wrap-break-word"><strong>รุ่นที่รองรับ</strong> {{ $product->compatibilities->pluck('device_model')->implode(', ') }}</p>
            @endif
            @if ($product->barcodes->isNotEmpty())
                <h3 class="font-semibold">บาร์โค้ดตามหน่วย</h3>
                <ul class="text-sm">
                    @foreach ($product->barcodes as $barcode)
                        <li class="wrap-break-word">{{ $barcode->code }} · {{ $barcode->unit }} ({{ $barcode->multiplier }} {{ $product->unit }})</li>
                    @endforeach
                </ul>
            @endif
        </section>
        <div class="min-w-0 space-y-5">
            <section class="panel p-5">
                <h2 class="mb-3 text-lg font-semibold">พิมพ์ฉลาก</h2>
                <form method="GET" action="{{ route('catalog.labels', [$branch, $product]) }}" class="grid gap-3 sm:grid-cols-2">
                    <x-crm-select name="type" label="แบบฉลาก" :options="['sku' => 'SKU', 'barcode' => 'บาร์โค้ดตามหน่วย', 'imei' => 'IMEI', 'price' => 'ป้ายราคา']" default="sku" />
                    <x-crm-select name="size" label="ขนาดฉลาก (มม.)" :options="['60x30' => '60 × 30', '100x40' => '100 × 40', '120x50' => '120 × 50']" default="100x40" />
                    <x-repair-input name="identifier" label="บาร์โค้ดตามหน่วยหรือ IMEI" :limit="40" />
                    <x-repair-input name="copies" label="จำนวนฉลาก" type="number" min="1" max="100" :limit="3" default="1" required />
                    @if ($canGroups)<x-crm-select name="group" label="กลุ่มราคาสำหรับป้าย" :options="['retail' => 'ปลีก', 'dealer' => 'ช่าง', 'partner' => 'พาร์ตเนอร์']" default="retail" />@endif
                    <button class="button button-primary self-end justify-self-start" type="submit">ดูตัวอย่างฉลาก</button>
                </form>
            </section>
            @if ($product->tracking !== 'none')
                <section class="panel p-5">
                    <h2 class="text-lg font-semibold">คงเหลือตามคลัง</h2>
                    @if ($product->tracking === 'quantity')
                        @forelse ($product->balances as $balance)
                            <p class="mt-3 wrap-break-word">{{ $balance->warehouse->name }} · {{ CatalogWarehouse::KINDS[$balance->warehouse->kind] }} · {{ number_format($balance->quantity) }} {{ $product->unit }}</p>
                            @if ($balance->locations->isNotEmpty())
                                @php($unassigned = $balance->quantity - $balance->locations->sum('quantity'))
                                <ul class="pl-4 text-sm text-slate-600">
                                    @foreach ($balance->locations->where('quantity', '>', 0) as $entry)
                                        <li class="wrap-break-word">{{ $entry->location->label() }} · {{ number_format($entry->quantity) }} {{ $product->unit }}</li>
                                    @endforeach
                                    @if ($unassigned > 0)<li>ไม่ระบุตำแหน่ง · {{ number_format($unassigned) }} {{ $product->unit }}</li>@endif
                                </ul>
                            @endif
                        @empty
                            <p class="mt-2 text-sm text-slate-600">ยังไม่มียอดรับเข้า</p>
                        @endforelse
                    @else
                        <ul class="my-2 divide-y divide-slate-200">
                            @forelse ($units as $unit)
                                <li class="wrap-break-word py-2 text-sm">
                                    {{ $unit->warehouse->name }} · {{ CatalogWarehouse::KINDS[$unit->warehouse->kind] }}
                                    @if ($unit->location) · {{ $unit->location->label() }} @endif
                                    · {{ $unit->available ? 'คงเหลือ' : 'จ่ายออกแล้ว' }}
                                    @if ($unit->imei)<br>IMEI {{ $unit->imei }}@endif
                                    @if ($unit->serial_number)<br>Serial {{ $unit->serial_number }}@endif
                                </li>
                            @empty
                                <li class="py-2 text-sm text-slate-600">ยังไม่มีเครื่องรับเข้า</li>
                            @endforelse
                        </ul>
                        {{ $units->links() }}
                    @endif
                </section>
            @endif
        </div>
    </div>
</x-layout>
