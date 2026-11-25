@php
    use App\Models\RepairQuotation;

    $money = fn (int $value) => RepairQuotation::formatBaht($value).' บาท';
@endphp

<section class="panel mb-5 p-5" aria-labelledby="repair-deposits">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 id="repair-deposits" class="text-lg font-semibold">มัดจำและยอดคงเหลือ</h2>
        @if ($role->allows(\App\Enums\BranchPermission::ManagePaymentDocuments))
            <a class="endu-text-action text-sm" href="{{ route('repairs.payments.index', [$branch, $repair]) }}">ดูรายการรับเงินทั้งหมด</a>
        @endif
    </div>
    <dl class="mt-4 grid gap-4 sm:grid-cols-3">
        <div><dt class="detail-label">ยอดมัดจำที่รับแล้ว</dt><dd class="font-semibold tabular-nums">{{ $money($deposits['received']) }}</dd></div>
        <div><dt class="detail-label">มัดจำสุทธิหลังคืนเงิน</dt><dd class="font-semibold tabular-nums">{{ $money($deposits['received'] - $deposits['refunds']) }}</dd></div>
        <div><dt class="detail-label">{{ ($billing['remaining'] ?? 0) < 0 ? 'ยอดรับเกิน' : 'ยอดคงเหลือ' }}</dt>
            <dd class="font-semibold tabular-nums">{{ $billing['remaining'] === null ? 'รอยอดที่ตกลง' : $money(abs($billing['remaining'])) }}</dd>
        </div>
    </dl>
    @if ($deposits['pending'] > 0)<p class="mt-3 text-sm text-amber-800">มัดจำรอตรวจยอด {{ $money($deposits['pending']) }}</p>@endif
    @if ($deposits['refunds'] > 0)<p class="mt-2 text-sm text-slate-600">คืนเงินมัดจำแล้ว {{ $money($deposits['refunds']) }}</p>@endif
    <ul class="mt-4 divide-y divide-slate-200 border-t border-slate-200">
        @forelse ($deposits['receipts'] as $deposit)
            <li class="flex flex-wrap items-center justify-between gap-2 py-2.5 text-sm">
                <a class="endu-text-action" href="{{ route('repairs.payments.deposit-proof', [$branch, $repair, $deposit]) }}">หลักฐานรับมัดจำ {{ $deposit->reference() }}</a>
                <span class="text-slate-700">{{ ($deposit->verified_at ?? $deposit->created_at)->timezone('Asia/Bangkok')->format('d/m/Y H:i') }} · {{ $money($deposit->amount_satang) }}</span>
            </li>
        @empty
            <li class="py-2.5 text-sm text-slate-600">ยังไม่มีหลักฐานรับมัดจำที่ยืนยันแล้ว</li>
        @endforelse
    </ul>
    {{ $deposits['receipts']->fragment('repair-deposits')->links() }}
</section>
