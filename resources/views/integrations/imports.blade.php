@php
    use App\Models\IntegrationImport;
    $results = ['create' => 'เพิ่ม', 'update' => 'อัปเดต', 'skip' => 'ข้าม', 'error' => 'ผิดพลาด'];
    $statuses = ['preview' => 'รอยืนยัน', 'invalid' => 'ต้องแก้ไข', 'expired' => 'ต้องตรวจไฟล์ใหม่', 'applied' => 'บันทึกแล้ว'];
@endphp
<x-layout title="นำเข้าและรายงานผล" active-module="INT">
    <div class="endu-page-head">
        <div><a class="endu-text-action" href="{{ route('integrations.index', $branch) }}">← เชื่อมต่อและอุปกรณ์</a><h1>นำเข้าและรายงานผล</h1><p>{{ $branch->name }}</p></div>
        @if ($batch)<a class="button button-secondary" href="{{ route('integrations.imports.index', $branch) }}">แก้ไฟล์และตรวจใหม่ / ประวัติ</a>@endif
    </div>
    @if ($errors->any())
        <ul class="panel mb-4 p-4 text-sm text-red-800" role="alert">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    @endif
    @if (session('import_notice'))<p class="panel mb-4 p-4" role="alert">{{ session('import_notice') }}</p>@endif
    @if ($batch)
        @php($counts = collect($batch->report)->countBy('status'))
        <section class="panel space-y-4 p-5">
            <h2 class="text-lg font-semibold">{{ IntegrationImport::KINDS[$batch->kind] }} #{{ $batch->id }} · {{ $statuses[$batch->status] }}</h2>
            <div class="flex flex-wrap gap-4 text-sm">
                @foreach ($results as $key => $label)<span>{{ $label }} {{ $counts->get($key, 0) }}</span>@endforeach
            </div>
            <div class="endu-table-scroll">
                <table class="w-full text-left text-sm">
                    <thead><tr><th class="p-2">แถว</th><th class="p-2">รหัสอ้างอิง</th><th class="p-2">ผล</th><th class="p-2">เหตุผล</th><th class="p-2">รายการในระบบ</th></tr></thead>
                    <tbody class="divide-y divide-slate-200">
                        @foreach ($batch->report as $number => $row)
                            <tr><td class="p-2">{{ $number }}</td><td class="p-2 wrap-break-word">{{ $row['reference'] }}</td>
                                <td class="p-2">{{ $results[$row['status']] }}</td><td class="p-2 wrap-break-word">{{ $row['reason'] }}</td>
                                <td class="p-2">
                                    @if ($row['record'] ?? null)
                                        @php($route = ['products' => 'catalog.show', 'customers' => 'crm.customers.show', 'opening' => 'catalog.movements.print'][$batch->kind])
                                        <a class="endu-text-action" href="{{ route($route, [$branch, $row['record']]) }}">#{{ $row['record'] }}</a>
                                    @else —
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="flex flex-wrap gap-2">
                <a class="button button-secondary" href="{{ route('integrations.imports.report', [$branch, $batch]) }}">ดาวน์โหลดรายงาน CSV</a>
                @if ($batch->status === 'preview' && (int) $batch->created_by === auth()->id())
                    <form method="POST" action="{{ route('integrations.imports.apply', [$branch, $batch]) }}">@csrf
                        <button class="button button-primary" type="submit">ยืนยันนำเข้า</button>
                    </form>
                @endif
            </div>
        </section>
    @else
        <section class="panel space-y-4 p-5">
            <h2 class="text-lg font-semibold">ตรวจไฟล์ก่อนนำเข้า</h2>
            <div class="flex flex-wrap gap-3">
                @foreach (IntegrationImport::KINDS as $key => $label)
                    <a class="endu-text-action" href="{{ route('integrations.imports.template', [$branch, 'kind' => $key]) }}">แม่แบบ{{ $label }}</a>
                @endforeach
            </div>
            <p class="text-sm text-slate-600">รองรับ CSV หรือชีตแรกใน XLSX สูงสุด 100 แถวและ 2 MB ต่อไฟล์ กำหนดคอลัมน์เบอร์โทรและ IMEI เป็นข้อความเพื่อรักษาเลขศูนย์นำหน้า</p>
            <form method="POST" enctype="multipart/form-data" action="{{ route('integrations.imports.preview', $branch) }}" class="grid gap-3 sm:grid-cols-2">
                @csrf
                <x-crm-select name="kind" label="ประเภทข้อมูล" :options="IntegrationImport::KINDS" />
                <label class="min-w-0"><span class="field-label">ไฟล์</span><input class="field-input" type="file" name="sheet" accept=".csv,.xlsx" required></label>
                <button class="button button-primary justify-self-start" type="submit">ตรวจไฟล์</button>
            </form>
        </section>
        <section class="panel mt-5 p-5">
            <h2 class="text-lg font-semibold">ประวัตินำเข้า</h2>
            <ul class="my-2 divide-y divide-slate-200">
                @forelse ($imports as $import)
                    <li class="py-3 text-sm"><a class="endu-text-action" href="{{ route('integrations.imports.show', [$branch, $import]) }}">#{{ $import->id }} · {{ IntegrationImport::KINDS[$import->kind] }}</a>
                        · {{ $statuses[$import->status] }} · {{ $import->created_at->timezone('Asia/Bangkok')->format('d/m/Y H:i') }}</li>
                @empty <li class="py-3 text-sm text-slate-600">ยังไม่มีรายการนำเข้า</li>
                @endforelse
            </ul>
            {{ $imports->links() }}
        </section>
    @endif
</x-layout>
