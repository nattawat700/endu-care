@php
    use App\Models\CashShiftMovement;
    use App\Models\RepairQuotation;
    $money = fn (int $value): string => ($value < 0 ? '−' : '').RepairQuotation::formatBaht(abs($value)).' บาท';
@endphp
<x-layout :title="'กะ #'.$shift->id" active-module="PAY">
    <div class="endu-page-head">
        <div><a class="endu-text-action" href="{{ route('cash-shifts.index', $branch) }}">← รับเงินและปิดกะ</a>
            <h1>กะ #{{ $shift->id }} · {{ $shift->counter->name }}</h1>
            <p>{{ $shift->operator->name }} · {{ $shift->created_at->timezone('Asia/Bangkok')->format('d/m/Y H:i') }} · {{ $shift->closed_at ? 'ปิดแล้ว' : 'เปิดอยู่' }}</p>
        </div>
        <a class="button button-secondary" href="{{ route('payment-documents.index', $branch) }}">ค้นใบงานรับเงิน</a>
    </div>
    @include('organizations.errors')
    <div class="grid gap-5 lg:grid-cols-2">
        <section class="panel p-5">
            <h2 class="font-semibold">ยอดกะ</h2>
            <dl class="mt-3 space-y-2 text-sm">
                <div class="flex justify-between"><dt>เงินทอนเปิดกะ</dt><dd>{{ $money($shift->opening_satang) }}</dd></div>
                @foreach (['cash_received' => 'รับเงินสดหลังหักเงินทอน', 'cash_refunds' => 'คืนเงินสด', 'top_up' => 'เติมเงินทอน', 'deposit' => 'นำเงินสดฝาก', 'expense' => 'ค่าใช้จ่ายย่อย', 'cash_expected' => 'เงินสดที่ควรอยู่ในลิ้นชัก', 'transfer_received' => 'ยอดรับโอนและ QR', 'transfer_refunds' => 'ยอดคืนโอนและ QR', 'transfer_expected' => 'ยอดโอนและ QR สุทธิ', 'other_received' => 'รับผ่านช่องทางเพิ่มเติม', 'other_refunds' => 'คืนผ่านช่องทางเพิ่มเติม'] as $key => $label)
                    <div class="flex justify-between gap-2"><dt>{{ $label }}</dt><dd class="tabular-nums">{{ $money($totals[$key]) }}</dd></div>
                @endforeach
                @if ($shift->closed_at)
                    @foreach (['counted_cash' => 'เงินสดนับจริง', 'cash_difference' => 'ผลต่างเงินสด', 'counted_transfer' => 'โอนและ QR สุทธิตรวจจริง', 'transfer_difference' => 'ผลต่างโอนและ QR'] as $key => $label)
                        <div class="flex justify-between gap-2"><dt>{{ $label }}</dt><dd>{{ $money($totals[$key]) }}</dd></div>
                    @endforeach
                @endif
            </dl>
            @if ($shift->closed_at && $totals['reason'])<p class="mt-2 text-sm wrap-break-word">เหตุผล {{ $totals['reason'] }}</p>@endif
            @if ($pending)<p class="mt-3 text-sm text-amber-800">ค้างตรวจยอดโอน {{ $pending }} รายการ</p>@endif
        </section>
        <div class="space-y-5">
            @unless ($shift->closed_at)
                @if ((int) $shift->opened_by === auth()->id())
                    <section class="panel p-5">
                        <h2 class="font-semibold">เงินเข้า–ออกลิ้นชัก</h2>
                        <form method="POST" action="{{ route('cash-shifts.move', [$branch, $shift]) }}" class="mt-3 grid gap-3 sm:grid-cols-2">
                            @csrf
                            <input type="hidden" name="submission_id" value="{{ old('submission_id', $submissionId) }}">
                            <x-crm-select name="kind" label="รายการ" :options="CashShiftMovement::KINDS" />
                            <x-repair-input name="amount" label="จำนวนเงิน (บาท)" inputmode="decimal" :limit="10" required />
                            <div class="sm:col-span-2"><x-repair-input name="reason" label="เหตุผล" :limit="400" required /></div>
                            <button class="button button-secondary justify-self-start">บันทึกรายการลิ้นชัก</button>
                        </form>
                    </section>
                @endif
                @if ($pending === 0)
                    <section class="panel p-5">
                        <h2 class="font-semibold">ปิดกะและกระทบยอด</h2>
                        <form method="POST" action="{{ route('cash-shifts.close', [$branch, $shift]) }}" class="mt-3 grid gap-3 sm:grid-cols-2">
                            @csrf @method('PATCH')
                            <x-repair-input name="counted_cash" label="เงินสดนับจริง (บาท)" inputmode="decimal" :limit="10" required />
                            <x-repair-input name="counted_transfer" label="ยอดโอนและ QR สุทธิตรวจจริง (บาท)" inputmode="decimal" :limit="11" required />
                            <div class="sm:col-span-2"><x-repair-input name="close_reason" label="เหตุผลกรณียอดต่าง" :limit="400" /></div>
                            <button class="button button-primary justify-self-start">ยืนยันปิดกะ</button>
                        </form>
                    </section>
                @endif
            @endunless
            <section class="panel p-5">
                <h2 class="font-semibold">ประวัติลิ้นชัก</h2>
                <ul class="mt-2 divide-y divide-slate-200">
                    @forelse ($movements as $entry)
                        <li class="py-2 text-sm wrap-break-word">{{ CashShiftMovement::KINDS[$entry->kind] }} · {{ $money($entry->amount_satang) }} · {{ $entry->reason }} · {{ $entry->created_at->timezone('Asia/Bangkok')->format('d/m/Y H:i') }}</li>
                    @empty <li class="py-2 text-sm">ยังไม่มีรายการ</li>
                    @endforelse
                </ul>
                {{ $movements->links() }}
            </section>
            @if ($posTenders->isNotEmpty())
                <section class="panel p-5">
                    <h2 class="font-semibold">รับและคืนเงินจาก POS</h2>
                    <ul class="mt-2 divide-y divide-slate-200">
                        @foreach ($posTenders as $entry)
                            <li class="py-2 text-sm">
                                {{ $entry->direction === 'received' ? 'รับเงินสด' : 'คืนเงินสด' }} · {{ $money($entry->amount_satang) }}
                                · {{ $entry->sale->reference() }} · {{ $entry->created_at->timezone('Asia/Bangkok')->format('d/m/Y H:i') }}
                            </li>
                        @endforeach
                    </ul>
                    {{ $posTenders->links() }}
                </section>
            @endif
        </div>
    </div>
</x-layout>
