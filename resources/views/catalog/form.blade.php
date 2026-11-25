@php
    use App\Enums\BranchPermission;
    use App\Models\CatalogProduct;
    use App\Models\RepairQuotation;
    $title = $product->exists ? 'แก้ไขสินค้า' : 'เพิ่มสินค้า';
@endphp
<x-layout :title="$title" active-module="CAT">
    <div class="endu-page-head">
        <div><a class="endu-text-action" href="{{ route('catalog.index', $branch) }}">← สินค้าและราคา</a><h1>{{ $title }}</h1><p>{{ $branch->name }}</p></div>
    </div>
    <form class="panel max-w-4xl space-y-5 p-5 sm:p-7" method="POST" enctype="multipart/form-data"
        action="{{ $product->exists ? route('catalog.update', [$branch, $product]) : route('catalog.store', $branch) }}">
        @csrf
        @if ($product->exists)
            @method('PATCH')
            <input type="hidden" name="version" value="{{ old('version', $product->version) }}">
            @error('version') <p class="field-error" role="alert">{{ $message }} <a class="endu-text-action" href="{{ route('catalog.edit', [$branch, $product]) }}">โหลดใหม่</a></p> @enderror
        @endif
        <div class="grid gap-4 sm:grid-cols-2">
            <x-repair-input name="sku" label="SKU" :limit="32" :default="$product->sku" required />
            <x-repair-input name="name" label="ชื่อสินค้า / บริการ" :limit="140" :default="$product->name" required />
            <x-crm-select name="kind" label="ประเภทสินค้า" :options="CatalogProduct::KINDS" :default="$product->kind" />
            <x-crm-select name="tracking" label="วิธีนับสต๊อก" :options="CatalogProduct::TRACKING" :default="$product->tracking" />
            <x-repair-input name="category" label="หมวด" :limit="80" :default="$product->category" />
            <x-repair-input name="brand" label="ยี่ห้อ" :limit="80" :default="$product->brand" />
            <x-repair-input name="unit" label="หน่วยนับฐาน" :limit="30" :default="$product->unit" required />
            <div class="min-w-0">
                <label class="field-label" for="catalog-image">รูปสินค้า</label>
                <input class="field-input" id="catalog-image" type="file" name="image" accept="image/jpeg,image/png,image/webp">
                <p class="mt-1 text-xs text-slate-600">JPG, PNG หรือ WebP ไม่เกิน 2 MB</p>
                @error('image') <p class="field-error">{{ $message }}</p> @enderror
                @if ($product->image)<label class="text-sm"><input type="checkbox" name="remove_image" value="1"> นำรูปเดิมออก</label>@endif
            </div>
        </div>
        <fieldset class="grid gap-4 border-t border-slate-200 pt-4 sm:grid-cols-2">
            <legend class="font-semibold">ราคา (บาท)</legend>
            @foreach (\App\Support\CatalogProductData::PRICES as $key => $label)
                @continue($key === 'cost' && auth()->user()->cannot('permission', [$branch, BranchPermission::Costs]))
                @continue(in_array($key, ['dealer', 'partner'], true) && auth()->user()->cannot('permission', [$branch, BranchPermission::CatalogGroupPrices]))
                @php($amount = $product->{$key.'_satang'})
                <x-repair-input :name="$key" :label="$label" inputmode="decimal" :limit="10" :default="$amount === null ? null : RepairQuotation::formatBaht($amount, '')" :required="$key === 'retail'" />
            @endforeach
        </fieldset>
        <div class="grid gap-4 border-t border-slate-200 pt-4 sm:grid-cols-2">
            <div>
                <x-repair-input name="models" label="รุ่นที่รองรับ" :rows="4" :limit="4000" :default="$product->compatibilities->pluck('device_model')->implode(PHP_EOL)" />
                <p class="mt-1 text-xs text-slate-600">หนึ่งรุ่นต่อบรรทัด เช่น iPhone 12 และ iPhone 12 Pro คนละบรรทัด</p>
            </div>
            <div>
                <x-repair-input name="barcodes" label="บาร์โค้ดตามหน่วย" :rows="4" :limit="4000"
                    :default="$product->barcodes->map(fn ($barcode) => $barcode->code.'|'.$barcode->unit.'|'.$barcode->multiplier)->implode(PHP_EOL)" />
                <p class="mt-1 text-xs text-slate-600">หนึ่งรหัสต่อบรรทัด รูปแบบ รหัส|หน่วย|จำนวนต่อหน่วย เช่น 88501234|กล่อง|10</p>
            </div>
        </div>
        <button class="button button-primary" type="submit">บันทึกสินค้า</button>
    </form>
</x-layout>
