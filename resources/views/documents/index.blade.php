<x-layout title="เอกสารงานบริการ" active-module="DOC">
    <div class="endu-page-head">
        <div><h1>เอกสารงานบริการ</h1><p>{{ $branch->name }}</p></div>
        @can('permission', [$branch, \App\Enums\BranchPermission::ManageBranchSettings])
            <a class="button button-secondary" href="{{ route('documents.settings', $branch) }}">ตั้งค่าชุดเลขและภาษี</a>
        @endcan
    </div>
    <div class="grid gap-5 lg:grid-cols-2">
        <section class="panel p-5">
            <h2 class="text-lg font-bold">เอกสารที่ออกแล้ว</h2>
            <ul class="my-3 divide-y divide-slate-200 text-sm">
                @forelse ($documents as $document)
                    <li class="py-3"><a class="endu-text-action" href="{{ route('documents.show', [$branch, $document]) }}">{{ $document->number }}</a>
                        · {{ \App\Models\IssuedDocument::KINDS[$document->kind][0] }} <span class="text-slate-600">· {{ $document->source_reference }}</span></li>
                @empty <li class="py-3 text-slate-600">ยังไม่มีเอกสาร</li>
                @endforelse
            </ul>
            {{ $documents->withQueryString()->links() }}
        </section>
        <section class="panel p-5">
            <h2 class="text-lg font-bold">รายการเคลม</h2>
            <ul class="my-3 divide-y divide-slate-200 text-sm">
                @forelse ($claims as $claim)
                    <li class="py-3"><a class="endu-text-action" href="{{ route('documents.source', [$branch, 'claim', $claim->id]) }}">{{ $claim->reference() }}</a>
                        · {{ \App\Models\ServiceClaim::STATUSES[$claim->status] }} <span class="text-slate-600">· {{ $claim->intake['reference'] }}</span></li>
                @empty <li class="py-3 text-slate-600">ยังไม่มีรายการเคลม</li>
                @endforelse
            </ul>
            {{ $claims->withQueryString()->links() }}
        </section>
    </div>
    <section class="panel mt-5 p-5">
        <h2 class="text-lg font-bold">ประวัติการสร้างไฟล์พิมพ์</h2>
        <ul class="my-3 divide-y divide-slate-200 text-sm">
            @forelse ($activity as $print)
                <li class="py-2 font-medium">{{ $print->number }} · {{ $print->name }} · {{ \Illuminate\Support\Carbon::parse($print->printed_at)->timezone('Asia/Bangkok')->format('d/m/Y H:i:s') }}</li>
            @empty <li class="py-2 text-slate-600">ยังไม่มีประวัติพิมพ์</li>
            @endforelse
        </ul>
        {{ $activity->withQueryString()->links() }}
    </section>
</x-layout>
