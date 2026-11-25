@php
    use App\Enums\BranchPermission;
    use App\Models\RepairPayment;
    use App\Models\RepairQuotation;

    $money = fn (int $value) => RepairQuotation::formatBaht($value).' บาท';
@endphp
<x-layout :title="$payment->reference()" active-module="REP">
    <div class="endu-page-head">
        <div>
            <a class="endu-text-action" href="{{ route('repairs.show', [$branch, $repair]) }}#repair-deposits">← มัดจำในใบงาน</a>
            <h1>หลักฐานรับมัดจำ</h1>
            <p>{{ $payment->reference() }} · {{ $repair->reference() }} · {{ $branch->name }}</p>
        </div>
        @can('permission', [$branch, BranchPermission::ManagePaymentDocuments])
            <a class="button button-secondary" href="{{ route('repairs.payments.show', [$branch, $repair, $payment]) }}">เปิดใบรับเงินต้นทาง</a>
        @endcan
    </div>
    <section class="panel p-5">
        <dl class="grid gap-4 sm:grid-cols-2">
            <div><dt class="detail-label">วันที่ยืนยันรับเงิน</dt><dd>{{ ($payment->verified_at ?? $payment->created_at)->timezone('Asia/Bangkok')->format('d/m/Y H:i') }}</dd></div>
            <div><dt class="detail-label">ช่องทางรับเงิน</dt><dd>{{ RepairPayment::CHANNELS[$payment->channel] }}</dd></div>
            <div><dt class="detail-label">ยอดรับตามหลักฐาน</dt><dd class="font-semibold tabular-nums">{{ $money($payment->amount_satang) }}</dd></div>
            <div><dt class="detail-label">คืนเงินแล้ว</dt><dd class="tabular-nums">{{ $money($refunded) }}</dd></div>
        </dl>
    </section>
</x-layout>
