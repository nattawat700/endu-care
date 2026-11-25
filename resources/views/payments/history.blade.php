@php
    use App\Models\RepairPayment;
    use App\Models\RepairQuotation;
@endphp

<section class="panel min-w-0 p-5">
    <h2 class="text-lg font-semibold">ประวัติเอกสารรับเงิน</h2>
    <ul class="my-3 divide-y divide-slate-200">
        @forelse ($payments as $payment)
            @php($job = $repair ?? $payment->repairJob)
            <li class="flex flex-wrap justify-between gap-3 py-3 text-sm">
                <div>
                    <a class="endu-text-action font-semibold" href="{{ route('repairs.payments.show', [$branch, $job, $payment]) }}">{{ $payment->status === 'confirmed' ? $payment->reference().' · '.$payment->title() : $payment->paymentReference().' · '.RepairPayment::STATUSES[$payment->status] }}</a>
                    <p class="mt-1 text-slate-600">{{ $job->reference() }} · {{ $payment->created_at->timezone('Asia/Bangkok')->format('d/m/Y H:i') }} · {{ RepairPayment::CHANNELS[$payment->channel] }}</p>
                </div>
                <div class="flex items-start gap-3">
                    <p class="font-semibold tabular-nums">{{ RepairQuotation::formatBaht($payment->amount_satang) }} บาท</p>
                    @if ($payment->slip_filename)
                        <a class="endu-text-action" href="{{ route('repairs.payments.slip', [$branch, $job, $payment]) }}" target="_blank" rel="noopener noreferrer">เปิดสลิป</a>
                    @endif
                </div>
            </li>
        @empty
            <li class="py-3 text-sm text-slate-600">ยังไม่มีรายการรับเงิน</li>
        @endforelse
    </ul>
    {{ $payments->links() }}
</section>
