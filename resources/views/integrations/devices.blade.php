<x-layout title="ทดสอบสแกนเนอร์และเครื่องพิมพ์" active-module="INT">
    <div class="endu-page-head"><div><a class="endu-text-action" href="{{ route('integrations.index', $branch) }}">← เชื่อมต่อและอุปกรณ์</a><h1>ทดสอบสแกนเนอร์และเครื่องพิมพ์</h1><p>{{ $branch->name }}</p></div></div>
    <div class="grid gap-5 lg:grid-cols-2">
        <section class="panel min-w-0 space-y-3 p-5">
            <h2 class="text-lg font-semibold">ทดสอบเครื่องสแกนแบบคีย์บอร์ด</h2>
            <p class="text-sm text-slate-600">คลิกช่องรหัสแล้วสแกน SKU บาร์โค้ด IMEI หรือ Serial ถ้าเครื่องตั้งท้ายด้วย Enter ระบบจะค้นให้ทันที</p>
            <form method="GET" action="{{ route('integrations.devices', $branch) }}" class="flex flex-wrap items-end gap-2">
                <label class="min-w-0 grow"><span class="field-label">รหัสที่สแกน</span><input class="field-input" name="code" maxlength="80" value="{{ $code }}" autocomplete="off" autofocus required data-integration-scan></label>
                <button class="button button-primary" type="submit">ตรวจรหัส</button>
            </form>
            @if ($code !== '')
                <ul class="divide-y divide-slate-200">
                    @forelse ($matches as $product)
                        <li class="py-3 text-sm wrap-break-word">
                            <a class="endu-text-action" href="{{ route('catalog.show', [$branch, $product]) }}">{{ $product->sku }} · {{ $product->name }}</a>
                            @foreach ($product->barcodes as $barcode)<p>บาร์โค้ดหน่วย {{ $barcode->unit }} · {{ $barcode->multiplier }} หน่วยฐาน</p>@endforeach
                            @foreach ($product->units as $unit)
                                <p>@if ($unit->imei) IMEI {{ $unit->imei }} · @endif
                                    @if ($unit->serial_number) Serial {{ $unit->serial_number }} · @endif
                                    {{ $unit->warehouse->name }} @if ($unit->location) · {{ $unit->location->label() }} @endif · {{ $unit->available ? 'คงเหลือ' : 'จ่ายออกแล้ว' }}</p>
                            @endforeach
                        </li>
                    @empty <li class="py-3 text-sm text-slate-600">ไม่พบรหัสในทะเบียนของสาขานี้</li>
                    @endforelse
                </ul>
            @endif
        </section>
        <section class="panel min-w-0 space-y-3 p-5">
            <h2 class="text-lg font-semibold">โปรไฟล์เครื่องพิมพ์</h2>
            @if ($errors->any())<ul class="text-sm text-red-800" role="alert">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
            @foreach ([null, ...$printers] as $printer)
                <details class="border-t border-slate-200 pt-2" @if (! $printer) open @endif>
                    <summary class="cursor-pointer font-semibold">{{ $printer?->name ?? 'เพิ่มโปรไฟล์' }}</summary>
                    <form method="POST" class="mt-3 grid gap-3 sm:grid-cols-2"
                        action="{{ $printer ? route('branch-settings.printer-profiles.update', [$branch, $printer]) : route('branch-settings.printer-profiles.store', $branch) }}">
                        @csrf
                        @if ($printer) @method('PATCH') @endif
                        <label><span class="field-label">ชื่อโปรไฟล์</span><input class="field-input" name="name" maxlength="100" value="{{ $printer?->name }}" required></label>
                        <label><span class="field-label">ชื่อเครื่องพิมพ์</span><input class="field-input" name="device_name" maxlength="150" value="{{ $printer?->device_name }}" required></label>
                        <label><span class="field-label">กระดาษ</span><select class="field-input" name="paper">
                            @foreach (\App\Models\BranchPrinterProfile::PAPERS as $key => $value)
                                <option value="{{ $key }}" @selected($printer?->paper === $key)>{{ $key === 'a5' ? 'A5 แนวนอน' : $value }}</option>
                            @endforeach
                        </select></label>
                        <div class="flex flex-wrap items-end gap-2">
                            <button class="button button-secondary" type="submit">บันทึกโปรไฟล์</button>
                            @if ($printer)<a class="button button-primary" href="{{ route('integrations.devices.print', [$branch, $printer]) }}">พิมพ์ทดสอบ</a>@endif
                        </div>
                    </form>
                </details>
            @endforeach
        </section>
    </div>
</x-layout>
