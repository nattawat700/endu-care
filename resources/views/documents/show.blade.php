@php($title = \App\Models\IssuedDocument::KINDS[$document->kind][0])
<x-layout :title="$document->number" active-module="DOC">
    <div class="endu-page-head">
        <div>
            <a class="endu-text-action" href="{{ route('documents.source', [$branch, $document->source_type, $document->source_id]) }}">← {{ $document->source_reference }}</a>
            <h1>{{ $title }}</h1>
            <p>{{ $document->number }}</p>
        </div>
        <form method="POST" action="{{ route('documents.print', [$branch, $document]) }}" target="_blank">
            @csrf
            <button type="submit" class="button button-primary">{{ $prints->count() > 0 ? 'พิมพ์ซ้ำ' : 'พิมพ์ PDF' }}</button>
        </form>
    </div>
    @include('documents.paper', ['copy' => false, 'sellerProfile' => $document->snapshot['seller']])
    <section class="panel mt-5 p-5">
        <h2 class="text-lg font-bold">ประวัติการสร้างไฟล์พิมพ์</h2>
        <ul class="mt-2 divide-y divide-slate-200 text-sm">
            @forelse ($prints as $print)
                <li class="py-2">{{ $print->name }} · {{ \Illuminate\Support\Carbon::parse($print->printed_at)->timezone('Asia/Bangkok')->format('d/m/Y H:i:s') }}</li>
            @empty <li class="py-2 text-slate-600">ยังไม่มีประวัติพิมพ์</li>
            @endforelse
        </ul>
        {{ $prints->links() }}
    </section>
</x-layout>
