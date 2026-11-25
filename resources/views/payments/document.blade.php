@php
    use App\Enums\BranchPermission;
    use App\Models\RepairPayment;
    use App\Models\RepairQuotation;
    $money = fn (int $amount): string => RepairQuotation::formatBaht($amount).' บาท';
    $title = $payment->status === 'confirmed' ? $payment->title().' '.$payment->reference() : $payment->paymentReference();
@endphp

<x-layout :title="$title" active-module="PAY">
    <div class="endu-page-head quotation-screen-only">
        <div><a class="endu-text-action" href="{{ route('repairs.payments.index', [$branch, $repair]) }}">← ประวัติรับเงินของใบงาน</a></div>
        <div class="flex flex-wrap gap-2">
            @if ($payment->slip_filename)
                <a class="button button-secondary" href="{{ route('repairs.payments.slip', [$branch, $repair, $payment]) }}" target="_blank" rel="noopener noreferrer">เปิดสลิปโอน</a>
            @endif
            @if ($payment->status === 'confirmed')
                @can('permission', [$branch, BranchPermission::IssueServiceDocuments])
                    @if ($payment->document_type !== 'deposit')
                        <a class="button button-secondary" href="{{ route('documents.source', [$branch, 'payment', $payment->id]) }}">ออกใบกำกับภาษี</a>
                    @endif
                @endcan
                <a class="button button-primary" href="{{ route('repairs.payments.print', [$branch, $repair, $payment, 'format' => 'pdf']) }}" target="_blank" rel="noopener noreferrer">พิมพ์{{ $payment->title() }}</a>
            @endif
        </div>
    </div>
    <div class="quotation-screen-only mb-5 space-y-5">
        @include('organizations.errors')
        <section class="panel p-5">
            <h1 class="text-lg font-semibold">{{ $payment->paymentReference() }} · {{ RepairPayment::STATUSES[$payment->status] }}</h1>
            <p class="text-sm text-slate-600">{{ $repair->reference() }} · {{ $payment->shift?->counter?->name ?? 'รายการก่อนเริ่มใช้ระบบกะ' }}</p>
            @can('viewAny', \App\Models\AuditEvent::class)
                <a class="endu-text-action mt-2 inline-block text-sm" href="{{ route('audit.index', ['branch' => $branch->id, 'subject' => 'repair_payment', 'reference' => $payment->reference()]) }}">ดูประวัติเอกสารนี้</a>
            @endcan
            <ul class="my-3 divide-y divide-slate-200">
                @foreach ($payment->lines as $line)
                    <li class="flex flex-wrap items-center gap-3 py-2 text-sm">
                        <span>{{ $line->label }} · {{ $money($line->amount_satang) }}</span>
                        @if ($line->tendered_satang !== null)
                            <span>รับเงินสด {{ $money($line->tendered_satang) }} · ทอน {{ $money($line->changeSatang()) }}</span>
                        @endif
                        @if ($line->slip_filename)
                            <span class="wrap-break-word">{{ $line->transfer_reference }}</span>
                            <a class="endu-text-action" target="_blank" rel="noopener noreferrer" href="{{ route('repairs.payments.slip', [$branch, $repair, $payment, 'line' => $line->id]) }}">เปิดสลิป {{ $line->label }}</a>
                        @endif
                    </li>
                @endforeach
            </ul>
            @if ($payment->verifier)
                <p class="text-sm">ผู้ตรวจ {{ $payment->verifier->name }} · {{ $payment->verified_at->timezone('Asia/Bangkok')->format('d/m/Y H:i') }}</p>
            @endif
            @if ($payment->review_note)<p class="text-sm wrap-break-word">{{ $payment->review_note }}</p>@endif
            @if ($payment->status === 'pending')
                @can('permission', [$branch, BranchPermission::VerifyPayments])
                    <form method="POST" action="{{ route('repairs.payments.verify', [$branch, $repair, $payment]) }}" class="mt-4 space-y-3 border-t border-slate-200 pt-4">
                        @csrf @method('PATCH')
                        <x-repair-input name="review_note" label="หมายเหตุตรวจยอด / เหตุผลปฏิเสธ" :limit="400" />
                        @foreach (['cash' => 'คืนเงินสดส่วนที่ได้รับแล้ว', 'other' => 'คืนเงินส่วนช่องทางเพิ่มเติมที่ได้รับแล้ว'] as $channel => $label)
                            @if ($payment->lines->contains('channel', $channel))
                                <label class="block text-sm"><input type="checkbox" name="returned_{{ $channel }}" value="1"> {{ $label }} (เมื่อปฏิเสธ)</label>
                            @endif
                        @endforeach
                        <div class="flex flex-wrap gap-2">
                            <button class="button button-primary" name="decision" value="confirmed">ยืนยันตรวจยอดโอนและ QR</button>
                            <button class="button button-secondary" name="decision" value="rejected">ปฏิเสธรายการ</button>
                        </div>
                    </form>
                @endcan
            @endif
        </section>
        @if ($payment->status === 'confirmed')
            @include('payments.refunds')
        @endif
    </div>
    @if ($payment->status === 'confirmed')
        <div class="quotation-wrap">
            @include('payments.paper')
        </div>
    @endif
</x-layout>
