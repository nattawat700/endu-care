<x-layout title="เชื่อมต่อและอุปกรณ์" active-module="INT">
    <div class="endu-page-head">
        <div><h1>เชื่อมต่อและอุปกรณ์</h1><p>{{ $branch->organization->name }} · {{ $branch->name }}</p></div>
        <div class="flex flex-wrap gap-2">
            <a class="button button-primary" href="{{ route('integrations.imports.index', $branch) }}">นำเข้าและรายงานผล</a>
            <a class="button button-secondary" href="{{ route('integrations.devices', $branch) }}">ทดสอบสแกนเนอร์และเครื่องพิมพ์</a>
        </div>
    </div>
    <section class="panel p-5">
        <h2 class="text-lg font-semibold">บัญชีเชื่อมต่อของสาขา</h2>
        <ul class="my-3 divide-y divide-slate-200">
            @forelse ($connections as $connection)
                <li class="flex flex-wrap justify-between gap-2 py-3 text-sm">
                    <div><a class="endu-text-action font-semibold" href="{{ route('integrations.connections.show', [$branch, $connection]) }}">{{ $connection->name }}</a>
                        <span> · {{ \App\Models\IntegrationConnection::KINDS[$connection->kind] }} · {{ $connection->account_ref }}</span></div>
                    <span>{{ $connection->enabled ? ($connection->last_received_at ? 'เปิดใช้ · มีเหตุการณ์เข้าแล้ว' : 'เปิดใช้') : 'ปิดใช้' }}</span>
                </li>
            @empty
                <li class="py-3 text-sm text-slate-600">ยังไม่มีบัญชีเชื่อมต่อ</li>
            @endforelse
        </ul>
        {{ $connections->links() }}
    </section>
    <section class="panel mt-5 p-5">
        <h2 class="text-lg font-semibold">เพิ่มบัญชีเชื่อมต่อ</h2>
        @include('integrations.connection-form', ['connection' => null])
    </section>
</x-layout>
