@php($title = $repair ? 'เบิกและคืนอะไหล่' : 'รับสินค้าเข้าคลัง')
<x-layout :title="$title" active-module="INV">
    <div class="endu-page-head">
        <div>@if ($repair && auth()->user()->can('view', $repair))
            <a class="endu-text-action" href="{{ route('repairs.show', [$branch, $repair]) }}">← {{ $repair->reference() }}</a>
        @else
            <a class="endu-text-action" href="{{ route('catalog.stock', $branch) }}">← คลังและเอกสารอะไหล่</a>
        @endif<h1>{{ $title }}</h1><p>{{ $branch->name }} @if ($repair) · {{ $repair->reference() }} · {{ $repair->device }} @endif</p></div>
        <a class="button button-secondary" href="{{ route('catalog.index', $branch) }}" target="_blank" rel="noopener">ค้นสินค้าและคงเหลือ</a>
    </div>
    @if ($errors->any())
        <ul class="panel mb-4 space-y-1 p-4 text-sm text-red-800" role="alert">
            @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
        </ul>
    @endif
    @if ($warehouses->isNotEmpty() && (! $repair || ! $repair->status->isClosed()))
        <form class="panel grid max-w-4xl gap-4 p-5 sm:grid-cols-2" data-stock-location-form method="POST" action="{{ $repair ? route('repairs.parts.store', [$branch, $repair]) : route('catalog.stock.store', $branch) }}">
            @csrf
            <input type="hidden" name="submission_id" value="{{ old('submission_id', $submissionId) }}">
            <x-repair-input name="code" label="SKU หรือบาร์โค้ด" :limit="40" required />
            <x-crm-select name="warehouse_id" label="คลัง" :options="$warehouses->mapWithKeys(fn ($warehouse) => [$warehouse->id => $warehouse->name.' · '.\App\Models\CatalogWarehouse::KINDS[$warehouse->kind]])" />
            @if ($warehouses->contains(fn ($warehouse) => $warehouse->locations->isNotEmpty()))
                <label><span class="field-label">ตำแหน่งเก็บ</span>
                    <select class="field-input" name="location_id">
                        <option value="">ไม่ระบุตำแหน่ง</option>
                        @foreach ($warehouses as $warehouse)
                            @if ($warehouse->locations->isNotEmpty())
                                <optgroup label="{{ $warehouse->name }}" data-stock-warehouse="{{ $warehouse->id }}">
                                    @foreach ($warehouse->locations as $location)
                                        <option value="{{ $location->id }}" @selected((string) old('location_id') === (string) $location->id)>{{ $location->label() }}</option>
                                    @endforeach
                                </optgroup>
                            @endif
                        @endforeach
                    </select>
                </label>
            @endif
            <x-repair-input name="quantity" label="จำนวนตามหน่วยที่เลือก" :limit="6" type="number" min="1" max="999999" default="1" required />
            @if (! $repair)
                <div>
                    <x-repair-input name="receipt_cost" label="ต้นทุนรับเข้าต่อหน่วยฐาน (บาท)" :limit="10" inputmode="decimal" />
                    <p class="mt-1 text-xs text-slate-600">เว้นว่างเพื่อใช้ต้นทุนที่ระบุในทะเบียนสินค้า</p>
                </div>
            @endif
            <x-repair-input name="serial_number" label="Serial No. สำหรับสินค้ารายเครื่อง" :limit="80" />
            <x-repair-input name="imei" label="IMEI สำหรับสินค้ารายเครื่อง" :limit="15" inputmode="numeric" />
            <x-repair-input name="note" label="หมายเหตุ" :limit="500" :rows="2" />
            <button class="button button-primary justify-self-start" type="submit">{{ $repair ? 'บันทึกใบเบิก' : 'บันทึกรับเข้า' }}</button>
        </form>
    @elseif ($warehouses->isEmpty())
        <p class="panel p-5">{{ $repair ? 'สาขานี้ยังไม่มีคลังขายสำหรับเบิกอะไหล่' : 'เพิ่มคลังของสาขาก่อนรับสินค้าเข้า' }}</p>
    @endif
    @if ($repair)
        <section class="panel mt-5 max-w-4xl p-5">
            <h2 class="text-lg font-semibold">คืนจากใบเบิกเดิม</h2>
            <ul class="mt-3 divide-y divide-slate-200">
                @forelse ($issues as $issue)
                    @php($remaining = $issue->quantity - (int) $issue->returns_sum_quantity)
                    <li class="space-y-2 py-3 text-sm">
                        <a class="endu-text-action" href="{{ route('catalog.movements.print', [$branch, $issue]) }}">{{ $issue->reference() }} · {{ $issue->sku }} · {{ $issue->warehouse_name }} @if ($issue->location_label) · {{ $issue->location_label }} @endif</a>
                        <p>คงค้าง {{ $remaining }} {{ $issue->unit_name }} @if ($issue->stockUnit) · {{ $issue->stockUnit->imei ?? $issue->stockUnit->serial_number }} @endif</p>
                        @if ($remaining > 0)
                            <form method="POST" action="{{ route('repairs.parts.store', [$branch, $repair]) }}" class="flex flex-wrap items-end gap-2">
                                @csrf
                                <input type="hidden" name="submission_id" value="{{ \Illuminate\Support\Str::uuid() }}">
                                <input type="hidden" name="issue_id" value="{{ $issue->id }}">
                                <label><span class="field-label">จำนวนคืน</span><input class="field-input w-28" name="quantity" type="number" required min="1" max="{{ min(999999, $remaining) }}" value="1"></label>
                                <button type="submit" class="button button-secondary">บันทึกใบคืน</button>
                            </form>
                        @endif
                    </li>
                @empty
                    <li class="py-3 text-sm text-slate-600">ยังไม่มีใบเบิกสำหรับใบงานนี้</li>
                @endforelse
            </ul>
            {{ $issues->links() }}
        </section>
    @endif
</x-layout>
