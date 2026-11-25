<x-layout title="ใบรับเงินและใบเสร็จ" active-module="DOC">
    <div class="endu-page-head">
        <div><h1>ใบรับเงินและใบเสร็จ</h1><p>{{ $branch->name }}</p></div>
        <div class="flex flex-wrap gap-2">
            @can('permission', [$branch, \App\Enums\BranchPermission::IssueServiceDocuments])
                <a class="button button-primary" href="{{ route('documents.index', $branch) }}">เอกสารงานบริการ</a>
            @endcan
            <a class="button button-secondary" href="{{ route('cash-shifts.index', $branch) }}">กะรับเงิน</a>
            @can('permission', [$branch, \App\Enums\BranchPermission::ReadPartsDocuments])
                <a class="button button-secondary" href="{{ route('catalog.stock', $branch) }}">เอกสารเบิกและคืนอะไหล่</a>
            @endcan
            <a class="button button-secondary" href="{{ route('phase-one', ['branch' => $branch->id, 'module' => 'DOC']) }}">ขอบเขตเอกสาร</a>
        </div>
    </div>
    <section class="panel mb-5 space-y-3 p-5">
        <h2 class="text-lg font-semibold">รับเงินจากใบงาน</h2>
        <form class="flex flex-wrap items-end gap-3" method="GET" action="{{ route('payment-documents.index', $branch) }}">
            <x-repair-input name="repair_reference" label="เลขใบงานของสาขา" :limit="22" placeholder="REP-000001" required />
            <button type="submit" class="button button-primary">เปิดใบงานรับเงิน</button>
        </form>
    </section>
    @include('payments.history')
</x-layout>
