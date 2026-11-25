<x-layout :title="$order->reference" active-module="INT">
    <div class="endu-page-head"><div><a class="endu-text-action" href="{{ route('integrations.connections.show', [$branch, $connection]) }}">← {{ $connection->name }}</a>
        <h1>ออเดอร์ {{ $order->reference }}</h1><p>{{ $branch->name }} · {{ $connection->account_ref }} · {{ $order->status === 'confirmed' ? 'ยืนยันแล้ว' : 'รอยืนยัน' }}</p></div></div>
    <section class="panel space-y-4 p-5">
        @if ($errors->any())<ul class="text-sm text-red-800" role="alert">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
        <h2 class="text-lg font-semibold">รายการต้นทาง</h2>
        <ul class="divide-y divide-slate-200">
            @foreach ($order->items as $line)
                @php($link = $mappings->get($line['external_sku']))
                <li class="py-2 text-sm wrap-break-word">{{ $line['external_sku'] }} · {{ $line['quantity'] }} หน่วย
                    @if ($link && $order->status === 'pending') → {{ $link->product->sku }} · {{ $link->product->name }}
                    @elseif ($order->status === 'pending') · ยังไม่ได้จับคู่
                    @endif
                    @if ($line['imei']) · IMEI {{ $line['imei'] }} @endif
                    @if ($line['serial_number']) · Serial {{ $line['serial_number'] }} @endif
                </li>
            @endforeach
        </ul>
        @if ($order->status === 'pending' && $connection->enabled && $warehouses->isNotEmpty())
            <form method="POST" action="{{ route('integrations.orders.confirm', [$branch, $connection, $order]) }}" class="flex flex-wrap items-end gap-3">
                @csrf
                <input type="hidden" name="revision" value="{{ $order->revision }}">
                <input type="hidden" name="mapping_version" value="{{ $mappingVersion }}">
                <x-crm-select name="warehouse_id" label="คลังที่ตัดสต๊อก" :options="$warehouses->pluck('name', 'id')" />
                <button class="button button-primary" type="submit">ยืนยันออเดอร์และตัดสต๊อก</button>
            </form>
        @elseif ($order->status === 'pending' && $warehouses->isEmpty())
            <p class="text-sm">เพิ่มคลังของสาขาก่อนยืนยันออเดอร์</p>
        @endif
        @if ($movements->isNotEmpty())
            <h3 class="font-semibold">เอกสารตัดสต๊อก</h3>
            <ul class="space-y-2 text-sm">
                @foreach ($movements as $movement)
                    <li><a class="endu-text-action" href="{{ route('catalog.movements.print', [$branch, $movement]) }}">{{ $movement->reference() }}</a> · {{ $movement->sku }} · {{ $movement->warehouse_name }}</li>
                @endforeach
            </ul>
        @endif
        @if ($shipments->isNotEmpty())
            <h3 class="font-semibold">สถานะจัดส่ง</h3>
            <ul class="space-y-2 text-sm">
                @foreach ($shipments as $shipment)
                    <li>{{ $shipment->tracking_number }} · {{ \App\Models\IntegrationShipment::STATUSES[$shipment->status] }}</li>
                @endforeach
            </ul>
            {{ $shipments->links() }}
        @endif
    </section>
</x-layout>
