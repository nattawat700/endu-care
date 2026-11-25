@php
    use App\Models\CatalogWarehouse;
@endphp
<x-layout title="คลังและตำแหน่งเก็บ" active-module="ORG">
    <div class="endu-page-head">
        <div>
            <a class="endu-text-action" href="{{ route('branch-settings.show', $branch) }}">← ตั้งค่าสาขา</a>
            <h1>คลังและตำแหน่งเก็บ</h1>
            <p>{{ $branch->organization->name }} · {{ $branch->name }}</p>
        </div>
        <a class="button button-secondary" href="{{ route('catalog.stock', $branch) }}">เอกสารคลัง</a>
    </div>
    @include('organizations.errors')
    <section class="panel mb-5 p-5">
        <h2 class="text-lg font-semibold">เพิ่มคลัง</h2>
        <form method="POST" action="{{ route('branch-settings.warehouses.store', $branch) }}" class="mt-3 grid items-end gap-3 sm:grid-cols-[2fr_1fr_auto]">
            @csrf
            <x-repair-input name="name" label="ชื่อคลังใหม่" :limit="80" required />
            <x-crm-select name="kind" label="ประเภทคลังใหม่" :options="CatalogWarehouse::KINDS" default="sale" />
            <button class="button button-primary justify-self-start" type="submit">เพิ่มคลัง</button>
        </form>
        <p class="mt-3 text-sm text-slate-600">ยอดในคลังพักตรวจแสดงแยกจากยอดพร้อมขาย และพักไว้จากการเบิกงานซ่อมหรือตัดสต๊อกออเดอร์</p>
    </section>
    <div class="space-y-4">
        @forelse ($warehouses as $warehouse)
            <details class="panel min-w-0 p-5" @if ($warehouses->count() === 1) open @endif>
                <summary class="cursor-pointer text-base font-semibold wrap-break-word">{{ $warehouse->name }} · {{ CatalogWarehouse::KINDS[$warehouse->kind] }} · {{ $warehouse->locations->count() }} ตำแหน่ง</summary>
                <form method="POST" action="{{ route('branch-settings.warehouses.update', [$branch, $warehouse]) }}" class="mt-4 grid items-end gap-3 sm:grid-cols-[2fr_1fr_auto]">
                    @csrf @method('PATCH')
                    <label><span class="field-label">ชื่อคลัง</span><input class="field-input" name="name" value="{{ $warehouse->name }}" maxlength="80" required></label>
                    <label><span class="field-label">ประเภทคลัง</span><select class="field-input" name="kind" required>
                        @foreach (CatalogWarehouse::KINDS as $key => $name)<option value="{{ $key }}" @selected($warehouse->kind === $key)>{{ $name }}</option>@endforeach
                    </select></label>
                    <button class="button button-secondary justify-self-start" type="submit">บันทึกคลัง</button>
                </form>
                <div class="mt-5 space-y-3 border-t border-slate-200 pt-4">
                    <h2 class="font-semibold">ตำแหน่งเก็บ</h2>
                    <form method="POST" action="{{ route('branch-settings.locations.store', [$branch, $warehouse]) }}" class="grid items-end gap-3 sm:grid-cols-[1fr_2fr_auto]">
                        @csrf
                        <label><span class="field-label">รหัสตำแหน่งใหม่</span><input class="field-input" name="code" placeholder="A-01" maxlength="32" required></label>
                        <label><span class="field-label">ชื่อตำแหน่งใหม่</span><input class="field-input" name="name" placeholder="ชั้น A ช่อง 01" maxlength="80" required></label>
                        <button class="button button-primary justify-self-start" type="submit">เพิ่มตำแหน่ง</button>
                    </form>
                    @foreach ($warehouse->locations as $location)
                        <form method="POST" action="{{ route('branch-settings.locations.update', [$branch, $warehouse, $location]) }}" class="grid items-end gap-3 border-t border-slate-100 pt-3 sm:grid-cols-[1fr_2fr_auto]">
                            @csrf @method('PATCH')
                            <label><span class="field-label">รหัสตำแหน่ง</span><input class="field-input" name="code" value="{{ $location->code }}" maxlength="32" required></label>
                            <label><span class="field-label">ชื่อตำแหน่ง</span><input class="field-input" name="name" value="{{ $location->name }}" maxlength="80" required></label>
                            <button class="button button-secondary justify-self-start" type="submit">บันทึกตำแหน่ง</button>
                        </form>
                    @endforeach
                </div>
            </details>
        @empty
            <p class="panel p-5 text-sm text-slate-600">ยังไม่มีคลังในสาขานี้</p>
        @endforelse
    </div>
    <div class="mt-4">{{ $warehouses->links() }}</div>
</x-layout>
