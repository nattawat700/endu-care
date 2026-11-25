<section class="panel p-5">
    <h2 class="text-lg font-semibold">คืนเงินและหลักฐาน</h2>
    <details class="my-3">
        <summary class="endu-text-action cursor-pointer">ขอคืนเงิน</summary>
        <form method="POST" action="{{ route('repairs.payments.refunds.request', [$branch, $repair, $payment]) }}" class="mt-3 grid gap-3 sm:grid-cols-2">
            @csrf
            <input type="hidden" name="submission_id" value="{{ old('submission_id', $submissionId) }}">
            @if ($payment->lines->isNotEmpty())
                <x-crm-select name="line_id" label="คืนผ่านช่องทางเดิม" :options="$payment->lines->mapWithKeys(fn ($line) => [$line->id => $line->label.' · '.$money($line->amount_satang)])" />
            @else
                <p>คืนผ่านช่องทางเดิม {{ \App\Models\RepairPayment::CHANNELS[$payment->channel] }}</p>
            @endif
            <x-repair-input name="refund_amount" label="ยอดขอคืน (บาท)" :limit="10" inputmode="decimal" required />
            <div class="sm:col-span-2"><x-repair-input name="refund_reason" label="เหตุผลที่ขอคืนเงิน" :limit="400" required /></div>
            <button class="button button-secondary justify-self-start">ส่งคำขอคืนเงิน</button>
        </form>
    </details>
    <ul class="divide-y divide-slate-200">
        @forelse ($refunds as $refund)
            <li class="space-y-2 py-3 text-sm">
                <p class="font-semibold">REFUND-{{ $refund->id }} · อ้างอิง {{ $payment->reference() }} · {{ $money($refund->amount_satang) }} · {{ \App\Models\RepairRefund::STATUSES[$refund->status] }}</p>
                <p class="wrap-break-word">{{ $refund->reason }}</p>
                @if ($refund->reviewer)<p>ผู้พิจารณา {{ $refund->reviewer->name }} · {{ $refund->review_note }}</p>@endif
                @if ($refund->status === 'completed')
                    @can('permission', [$branch, \App\Enums\BranchPermission::IssueServiceDocuments])
                        <a class="endu-text-action" href="{{ route('documents.source', [$branch, 'refund', $refund->id]) }}">ออกหลักฐานคืนเงิน</a>
                    @endcan
                @endif
                @if ($refund->status === 'pending' && $refund->canReview($branch, auth()->user()))
                    <form method="POST" action="{{ route('repairs.payments.refunds.review', [$branch, $repair, $payment, $refund]) }}" class="space-y-2">
                        @csrf @method('PATCH')
                        <label class="block"><span class="field-label">เหตุผลอนุมัติหรือปฏิเสธ</span><input class="field-input" name="refund_review_note" maxlength="400" required></label>
                        <button class="button button-primary" name="decision" value="approved">อนุมัติคืนเงิน</button>
                        <button class="button button-secondary" name="decision" value="rejected">ไม่อนุมัติ</button>
                    </form>
                @elseif ($refund->status === 'approved' && $activeShift)
                    <form method="POST" enctype="multipart/form-data" action="{{ route('repairs.payments.refunds.complete', [$branch, $repair, $payment, $refund]) }}" class="space-y-2">
                        @csrf
                        <label class="block"><span class="field-label">หลักฐานการคืนเงินจริง</span><input class="field-input" name="refund_proof" type="file" accept="image/jpeg,image/png,image/webp" required></label>
                        <label class="block"><input type="checkbox" name="paid" value="1" required> ยืนยันว่าคืนเงินจริงแล้ว</label>
                        <button class="button button-primary">บันทึกการคืนเงินจริง</button>
                    </form>
                @elseif ($refund->status === 'approved')
                    <a class="endu-text-action" href="{{ route('cash-shifts.index', $branch) }}">เปิดกะเพื่อบันทึกการคืนเงินจริง</a>
                @elseif ($refund->proof_filename)
                    <a class="endu-text-action" target="_blank" rel="noopener noreferrer" href="{{ route('repairs.payments.refunds.proof', [$branch, $repair, $payment, $refund]) }}">เปิดหลักฐานคืนเงิน</a>
                @endif
            </li>
        @empty
            <li class="py-3 text-sm">ยังไม่มีรายการคืนเงิน</li>
        @endforelse
    </ul>
    {{ $refunds->links() }}
</section>
