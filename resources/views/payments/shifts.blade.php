<x-layout title="รับเงินและปิดกะ" active-module="PAY">
    <div class="endu-page-head">
        <div><h1>รับเงินและปิดกะ</h1><p>{{ $branch->name }}</p></div>
        <a class="button button-secondary" href="{{ route('payment-documents.index', $branch) }}">ค้นใบงานและเอกสารรับเงิน</a>
    </div>
    @include('organizations.errors')
    <section class="panel mb-5 p-5">
        @if ($activeShift)
            <h2 class="font-semibold">กะที่เปิดอยู่</h2>
            <a class="endu-text-action" href="{{ route('cash-shifts.show', [$branch, $activeShift]) }}">{{ $activeShift->counter->name }} · กะ #{{ $activeShift->id }}</a>
        @elseif ($counters->isNotEmpty())
            <h2 class="font-semibold">เปิดกะ</h2>
            <form method="POST" action="{{ route('cash-shifts.open', $branch) }}" class="mt-3 flex flex-wrap items-end gap-3">
                @csrf
                <input type="hidden" name="submission_id" value="{{ old('submission_id', $submissionId) }}">
                <x-crm-select name="counter_id" label="เคาน์เตอร์" :options="$counters->pluck('name', 'id')" />
                <x-repair-input name="opening_cash" label="เงินทอนเปิดกะ (บาท)" inputmode="decimal" default="0.00" :limit="10" required />
                <button class="button button-primary">เปิดกะ</button>
            </form>
        @else
            <p>ให้ผู้ดูแลตั้งจุดรับเงิน เครื่องพิมพ์ และเคาน์เตอร์ของสาขาก่อนเปิดกะ</p>
            @can('permission', [$branch, \App\Enums\BranchPermission::ManageBranchSettings])
                <a class="endu-text-action" href="{{ route('branch-settings.show', $branch) }}">ตั้งค่าเคาน์เตอร์</a>
            @endcan
        @endif
    </section>
    @foreach (['คิวตรวจยอด' => $pendingTransfers, 'คิวคืนเงิน' => $pendingRefunds] as $title => $queue)
        @if ($queue !== null)
            <section class="panel mb-5 p-5">
                <h2 class="font-semibold">{{ $title }}</h2>
                <ul class="mt-2 divide-y divide-slate-200">
                    @forelse ($queue as $payment)
                        <li class="py-2"><a class="endu-text-action" href="{{ route('repairs.payments.show', [$branch, $payment->repairJob, $payment]) }}">{{ $payment->paymentReference() }} · {{ $payment->repairJob->reference() }}</a></li>
                    @empty
                        <li class="py-2 text-sm text-slate-600">ไม่มีรายการ</li>
                    @endforelse
                </ul>
                {{ $queue->links() }}
            </section>
        @endif
    @endforeach
    <section class="panel p-5">
        <h2 class="font-semibold">ประวัติกะ</h2>
        <ul class="mt-2 divide-y divide-slate-200">
            @forelse ($shifts as $shift)
                <li class="py-2">
                    <a class="endu-text-action" href="{{ route('cash-shifts.show', [$branch, $shift]) }}">กะ #{{ $shift->id }} · {{ $shift->counter->name }}</a>
                    <p class="text-sm text-slate-600">{{ $shift->operator->name }} · {{ $shift->created_at->timezone('Asia/Bangkok')->format('d/m/Y H:i') }} · {{ $shift->closed_at ? 'ปิดแล้ว' : 'เปิดอยู่' }}</p>
                </li>
            @empty
                <li class="py-2 text-sm text-slate-600">ยังไม่มีกะ</li>
            @endforelse
        </ul>
        {{ $shifts->links() }}
    </section>
</x-layout>
