@php
    use App\Enums\BranchPermission;
    use App\Models\CatalogMovement;
    use App\Models\CatalogWarehouse;
    $manageStock = $role->allows(BranchPermission::ManageCatalogStock);
    $manageParts = $role->allows(BranchPermission::ManageRepairParts);
@endphp
<x-layout title="คลังและเอกสารอะไหล่" active-module="INV">
    <div class="endu-page-head">
        <div><h1>คลังและเอกสารอะไหล่</h1><p>{{ $branch->name }}</p></div>
        <div class="flex flex-wrap gap-2">
            <a class="button button-secondary" href="{{ route('catalog.index', $branch) }}">ตรวจสินค้าคงเหลือ</a>
            @if ($role->allows(BranchPermission::ManageBranchSettings))
                <a class="button button-secondary" href="{{ route('branch-settings.warehouses.index', $branch) }}">ตั้งคลังและตำแหน่งเก็บ</a>
            @endif
            @if ($manageStock)
                <a class="button button-primary" href="{{ route('catalog.stock.receive', $branch) }}">รับสินค้าเข้าคลัง</a>
            @endif
        </div>
    </div>
    @error('name') <p class="field-error" role="alert">{{ $message }}</p> @enderror
    @if ($manageStock || $manageParts)
        <div class="mb-5 grid gap-5 lg:grid-cols-2">
            @if ($manageStock)
            <section class="panel space-y-3 p-5">
                <h2 class="text-lg font-semibold">คลังของสาขา</h2>
                <ul class="space-y-1 text-sm">
                    @forelse ($warehouses as $warehouse)
                        <li class="wrap-break-word">{{ $warehouse->name }} · {{ CatalogWarehouse::KINDS[$warehouse->kind] }}</li>
                    @empty
                        <li>ยังไม่มีคลัง</li>
                    @endforelse
                </ul>
                <form class="flex flex-wrap items-end gap-2" method="POST" action="{{ route('catalog.warehouses.store', $branch) }}">
                    @csrf
                    <x-repair-input name="name" label="ชื่อคลังใหม่" :limit="80" required />
                    <button class="button button-secondary" type="submit">เพิ่มคลัง</button>
                </form>
            </section>
            @endif
            <section class="panel space-y-3 p-5">
                <h2 class="text-lg font-semibold">เปิดใบเบิก / คืนจากใบงาน</h2>
                <form class="flex flex-wrap items-end gap-2" method="GET" action="{{ route('catalog.open-repair', $branch) }}">
                    <x-repair-input name="reference" label="เลขใบงานของสาขา" placeholder="REP-000001" :limit="22" required />
                    <button class="button button-secondary" type="submit">เปิดใบงาน</button>
                </form>
                @error('reference') <p class="field-error">{{ $message }}</p> @enderror
            </section>
        </div>
    @endif
    <section class="panel p-5">
        <h2 class="text-lg font-semibold">ประวัติเอกสารคลัง</h2>
        <nav aria-label="ประเภทเอกสารคลัง" class="my-3 flex flex-wrap gap-2 text-sm">
            <a class="endu-text-action" href="{{ route('catalog.stock', [$branch, 'job' => request('job')]) }}">ทั้งหมด</a>
            @foreach (CatalogMovement::KINDS as $value => $label)
                @continue(in_array($value, ['receive', 'sale'], true) && ! $manageStock)
                <a class="endu-text-action" href="{{ route('catalog.stock', [$branch, 'kind' => $value, 'job' => request('job')]) }}">{{ $label }}</a>
            @endforeach
        </nav>
        <ul class="divide-y divide-slate-200">
            @forelse ($movements as $movement)
                <li class="flex flex-wrap justify-between gap-2 py-3 text-sm">
                    <div>
                        <a class="endu-text-action" href="{{ route('catalog.movements.print', [$branch, $movement]) }}">{{ $movement->reference() }} · {{ CatalogMovement::KINDS[$movement->kind] }}</a>
                        <p>{{ $movement->sku }} · {{ number_format($movement->quantity) }} {{ $movement->unit_name }} · {{ $movement->warehouse_name }} @if ($movement->location_label) · {{ $movement->location_label }} @endif</p>
                    </div>
                    <span>{{ $movement->repair?->reference() ?? $movement->integrationOrder?->reference ?? '—' }} · {{ $movement->created_at->timezone('Asia/Bangkok')->format('d/m/Y H:i') }}</span>
                </li>
            @empty
                <li class="py-3 text-sm text-slate-600">ยังไม่มีเอกสารในรายการนี้</li>
            @endforelse
        </ul>
        {{ $movements->links() }}
    </section>
</x-layout>
