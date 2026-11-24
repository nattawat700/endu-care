<section class="quotation-screen-only panel quotation-approval mb-5 p-5" aria-labelledby="quotation-review-heading">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h2 id="quotation-review-heading" class="text-lg font-bold">อนุมัติราคาภายใน</h2>
        <span @class([
            'badge',
            'bg-emerald-100 text-emerald-900' => $quotation->internal_decision === \App\Enums\QuotationDecision::Approved,
            'bg-red-100 text-red-800' => $quotation->internal_decision === \App\Enums\QuotationDecision::Rejected,
            'badge-neutral' => $quotation->internal_decision === null,
        ])>{{ $quotation->internalApprovalLabel($isLatest) }}</span>
    </div>
    @error('review') <p class="field-error" role="alert">{{ $message }}</p> @enderror
    @if ($quotation->internal_requested_at)
        <dl class="mt-3 grid gap-4 sm:grid-cols-2">
            <div>
                <dt class="detail-label">ผู้ส่งคำขอ</dt>
                <dd>{{ $quotation->internalRequester->name }} · <time datetime="{{ $quotation->internal_requested_at->toIso8601String() }}">{{ $quotation->internal_requested_at->timezone('Asia/Bangkok')->format('d/m/Y H:i') }} น.</time></dd>
                <dt class="detail-label mt-2">เหตุผลที่ขออนุมัติ</dt>
                <dd class="whitespace-pre-wrap wrap-break-word">{{ $quotation->internal_request_reason }}</dd>
            </div>
            @if ($quotation->internal_decision)
                <div>
                    <dt class="detail-label">ผู้พิจารณา</dt>
                    <dd>{{ $quotation->internalReviewer->name }} · <time datetime="{{ $quotation->internal_decided_at->toIso8601String() }}">{{ $quotation->internal_decided_at->timezone('Asia/Bangkok')->format('d/m/Y H:i') }} น.</time></dd>
                    <dt class="detail-label mt-2">เหตุผลของผลพิจารณา</dt>
                    <dd class="whitespace-pre-wrap wrap-break-word">{{ $quotation->internal_decision_reason }}</dd>
                </div>
            @endif
        </dl>
    @endif

    @if ($canRequestReview)
        <form class="mt-3 space-y-2" method="POST" action="{{ route('repairs.quotations.submit-review', [$branch, $repair, $quotation]) }}">
            @csrf
            <label class="block">
                <span class="field-label">เหตุผลที่ส่งขออนุมัติ</span>
                <textarea class="field-input" name="request_reason" rows="2" maxlength="1500" required>{{ is_string(old('request_reason')) ? old('request_reason') : '' }}</textarea>
            </label>
            @error('request_reason') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            <button class="button button-primary" type="submit">ส่งขออนุมัติจากผู้จัดการ</button>
        </form>
    @elseif ($canDecide)
        <form class="mt-3 space-y-2 border-t border-slate-200 pt-3" method="POST" enctype="multipart/form-data" action="{{ route('repairs.quotations.decide-review', [$branch, $repair, $quotation]) }}">
            @csrf
            @method('PATCH')
            <label class="block">
                <span class="field-label">เหตุผลของการตัดสินใจ</span>
                <textarea class="field-input" name="decision_reason" rows="2" maxlength="1500" required>{{ is_string(old('decision_reason')) ? old('decision_reason') : '' }}</textarea>
            </label>
            @error('decision_reason') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            @error('decision') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            <label class="block">
                <span class="field-label">แนบภาพลายเซ็นเมื่ออนุมัติ (ถ้ามี)</span>
                <input class="field-input" type="file" name="signature" accept="image/png,image/jpeg,image/webp">
            </label>
            @error('signature') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            <div class="flex flex-wrap gap-2">
                <button class="button button-primary" type="submit" name="decision" value="approved">อนุมัติให้ออกใบเสนอราคา</button>
                <button class="button button-secondary" type="submit" name="decision" value="rejected">ไม่อนุมัติ</button>
            </div>
        </form>
    @endif
</section>
