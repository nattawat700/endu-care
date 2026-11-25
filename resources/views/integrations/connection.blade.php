@php
    use App\Models\IntegrationConnection;
    use App\Models\IntegrationShipment;
    $topic = \App\Support\IntegrationEvents::TOPICS[$connection->kind] ?? null;
    $eventLabels = ['applied' => 'รับและบันทึกแล้ว', 'ignored' => 'ข้าม', 'rejected' => 'ไม่ผ่าน'];
    $reasons = ['applied'=>'บันทึกแล้ว', 'stale_revision'=>'ฉบับเก่าหรือซ้ำ', 'stale_status'=>'สถานะเก่าหรือปิดงานแล้ว',
        'confirmed_order'=>'ออเดอร์ยืนยันแล้ว', 'unexpected_topic'=>'หัวข้อไม่ตรงกับการเชื่อมต่อ', 'invalid_payload'=>'ข้อมูลไม่ตรงตามแบบ',
        'future_time'=>'เวลาเหตุการณ์อยู่ในอนาคต', 'order_not_found'=>'ไม่พบออเดอร์ที่ยืนยัน หรืออ้างอิงคนละรายการ'];
@endphp
<x-layout :title="$connection->name" active-module="INT">
    <div class="endu-page-head">
        <div class="min-w-0"><a class="endu-text-action" href="{{ route('integrations.index', $branch) }}">← เชื่อมต่อและอุปกรณ์</a>
            <h1 class="wrap-break-word">{{ $connection->name }}</h1><p>{{ $branch->organization->name }} · {{ $branch->name }} · {{ IntegrationConnection::KINDS[$connection->kind] }}</p>
        </div>
        @if ($connection->kind === 'accounting')
            <a class="button button-primary" href="{{ route('integrations.accounting.index', [$branch, $connection]) }}">ตรวจและส่งออกชุดบัญชี</a>
        @endif
    </div>
    <section class="panel min-w-0 space-y-3 p-5">
        <h2 class="text-lg font-semibold">การตั้งค่าบัญชี</h2>
        <p class="text-sm wrap-break-word">รหัสบัญชี {{ $connection->account_ref }} · {{ $connection->enabled ? 'เปิดใช้' : 'ปิดใช้' }}
            @if ($connection->last_received_at) · เหตุการณ์ล่าสุด {{ $connection->last_received_at->timezone('Asia/Bangkok')->format('d/m/Y H:i') }} @endif
        </p>
        @if (in_array($connection->kind, ['line', 'payment'], true))
            <p class="text-sm text-slate-600">{{ isset($connection->credentials['token']) ? 'บันทึกกุญแจแล้ว' : 'ยังไม่มีกุญแจ' }} · สถานะตั้งค่าภายในระบบ</p>
        @endif
        @include('integrations.connection-form')
    </section>

    @if ($topic)
        <section class="panel mt-5 min-w-0 space-y-3 p-5">
            <h2 class="text-lg font-semibold">Webhook</h2>
            <p class="wrap-break-word font-mono text-sm">{{ route('integrations.webhook', $connection->public_id) }}</p>
            <p class="text-sm">POST JSON · topic <code>{{ $topic }}</code> · account <code>{{ $connection->account_ref }}</code></p>
            <details>
                <summary class="cursor-pointer font-semibold">รูปแบบคำขอและลายเซ็น</summary>
                <p class="mt-2 text-sm">ส่ง <code>X-Endu-Timestamp</code> เป็น Unix time หน่วยวินาที และ <code>X-Endu-Signature</code> เป็น <code>sha256=</code> ตามด้วยผล HMAC-SHA256 แบบ hex ของ <code>timestamp.public_id.raw_body</code> โดยใช้กุญแจ webhook ของบัญชีนี้</p>
                <p class="mt-2 text-sm">ใช้ public_id <code>{{ $connection->public_id }}</code> และลงลายเซ็นจาก JSON ชุดเดียวกับที่ส่ง ระบบรับเวลาคลาดเคลื่อนได้ 5 นาที ใช้ event_id เดิมเมื่อต้องส่งซ้ำ และใช้ event_id ใหม่เมื่อต้องส่งข้อมูลที่แก้แล้ว</p>
                @php
                    $sample = match ($connection->kind) {
                        'endu' => ['external_sku' => 'SKUZA-001', 'name' => 'อะไหล่ตัวอย่าง', 'device_model' => 'iPhone 13', 'revision' => 1],
                        'store' => ['reference' => 'ORDER-001', 'revision' => 1, 'items' => [['external_sku' => 'SHOP-001', 'quantity' => 1]]],
                        'shipping' => ['tracking_number' => 'TRACK-001', 'status' => 'in_transit', 'occurred_at' => now()->utc()->format('Y-m-d\TH:i:s\Z')],
                    };
                @endphp
                <pre class="mt-3 overflow-auto rounded-md bg-slate-50 p-3 text-xs">{{ json_encode(['event_id'=>'EVENT-001', 'account'=>$connection->account_ref, 'topic'=>$topic, 'data'=>$sample], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                @if ($connection->kind === 'shipping')<p class="mt-2 text-sm">ผูกกับออเดอร์ที่ยืนยันแล้วด้วย <code>store_account</code> และ <code>order_ref</code> ใน data</p>@endif
            </details>
            <h3 class="font-semibold">ประวัติรับเหตุการณ์</h3>
            <ul class="divide-y divide-slate-200">
                @forelse ($events as $event)
                    <li class="py-2 text-sm wrap-break-word">{{ $event->external_id }} · {{ $eventLabels[$event->status] }} · {{ $reasons[$event->reason] ?? $event->reason }}
                        · {{ $event->created_at->timezone('Asia/Bangkok')->format('d/m/Y H:i') }}</li>
                @empty <li class="py-2 text-sm text-slate-600">ยังไม่มีเหตุการณ์</li>
                @endforelse
            </ul>
            {{ $events->links() }}
        </section>
    @endif

    @if (in_array($connection->kind, ['endu', 'store'], true))
        <section class="panel mt-5 min-w-0 space-y-3 p-5">
            <h2 class="text-lg font-semibold">จับคู่สินค้าและรุ่น</h2>
            @if ($offers->isNotEmpty())
                <ul class="divide-y divide-slate-200">
                    @foreach ($offers as $offer)
                        <li class="py-2 text-sm wrap-break-word">{{ $offer->external_sku }} · {{ $offer->name }} · {{ $offer->device_model ?? '—' }} · ฉบับ {{ $offer->revision }}</li>
                    @endforeach
                </ul>
                {{ $offers->links() }}
            @endif
            <form method="POST" action="{{ route('integrations.connections.links', [$branch, $connection]) }}" class="grid gap-3 sm:grid-cols-2">
                @csrf
                <x-repair-input name="external_sku" label="SKU ต้นทาง" :limit="80" required />
                <x-repair-input name="local_sku" label="SKU ในสาขา" :limit="32" required />
                <x-repair-input name="external_model" label="รุ่นต้นทาง" :limit="100" />
                <x-repair-input name="local_model" label="รุ่นที่รองรับในทะเบียน" :limit="100" />
                <button class="button button-secondary justify-self-start" type="submit">บันทึกคู่สินค้า</button>
            </form>
            <ul class="divide-y divide-slate-200">
                @forelse ($links as $link)
                    <li class="py-2 text-sm wrap-break-word">{{ $link->external_sku }} → <a class="endu-text-action" href="{{ route('catalog.show', [$branch, $link->product]) }}">{{ $link->product->sku }} · {{ $link->product->name }}</a>
                        @if ($link->external_model) · {{ $link->external_model }} → {{ $link->local_model }} @endif</li>
                @empty <li class="py-2 text-sm text-slate-600">ยังไม่มีคู่สินค้า</li>
                @endforelse
            </ul>
            {{ $links->links() }}
        </section>
    @endif
    @if ($connection->kind === 'store')
        <section class="panel mt-5 p-5">
            <h2 class="text-lg font-semibold">ออเดอร์จากบัญชีนี้</h2>
            <ul class="mt-3 divide-y divide-slate-200">
                @forelse ($orders as $order)
                    <li class="py-2 text-sm"><a class="endu-text-action" href="{{ route('integrations.orders.show', [$branch, $connection, $order]) }}">{{ $order->reference }}</a> · {{ $order->status === 'confirmed' ? 'ยืนยันแล้ว' : 'รอยืนยัน' }}</li>
                @empty <li class="py-2 text-sm text-slate-600">ยังไม่มีออเดอร์</li>
                @endforelse
            </ul>
            {{ $orders->links() }}
        </section>
    @endif
    @if ($connection->kind === 'shipping')
        <section class="panel mt-5 p-5">
            <h2 class="text-lg font-semibold">เลขติดตามและสถานะล่าสุด</h2>
            <ul class="mt-3 divide-y divide-slate-200">
                @forelse ($shipments as $shipment)
                    <li class="py-2 text-sm wrap-break-word">{{ $shipment->tracking_number }} · {{ IntegrationShipment::STATUSES[$shipment->status] }}
                        · {{ $shipment->occurred_at->timezone('Asia/Bangkok')->format('d/m/Y H:i') }}
                        @if ($shipment->order) · ออเดอร์ {{ $shipment->order->reference }} @endif</li>
                @empty <li class="py-2 text-sm text-slate-600">ยังไม่มีสถานะจัดส่ง</li>
                @endforelse
            </ul>
            {{ $shipments->links() }}
        </section>
    @endif
</x-layout>
