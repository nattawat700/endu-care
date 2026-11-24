@php
    use App\Enums\RepairStatus;
    use App\Models\RepairQuotation;
@endphp

<section id="customer-approval" class="quotation-screen-only panel quotation-approval mt-5 scroll-mt-24 p-5" aria-labelledby="customer-approval-title">
    <h2 id="customer-approval-title" class="text-lg font-bold">การอนุมัติราคาจากลูกค้า</h2>
    @error('request_channel') <p class="field-error" role="alert">{{ $message }}</p> @enderror
    @error('confirmation') <p class="field-error" role="alert">{{ $message }}</p> @enderror

    @if ($quotation->customer_requested_at)
        <dl class="mt-3 grid gap-3 rounded-lg bg-slate-50 p-3 sm:grid-cols-3">
            <div><dt class="detail-label">ช่องทางที่ส่งขออนุมัติ</dt><dd>{{ RepairQuotation::APPROVAL_CHANNELS[$quotation->customer_request_channel] }}</dd></div>
            <div><dt class="detail-label">ผู้ส่ง</dt><dd>{{ $quotation->customerApprovalRequester->name }}</dd></div>
            <div><dt class="detail-label">เวลาที่ส่งขออนุมัติ</dt><dd>{{ $quotation->customer_requested_at->timezone('Asia/Bangkok')->format('d/m/Y H:i') }} น.</dd></div>
        </dl>
    @elseif ($canApprove && ! $quotation->isExpired())
        <form method="POST" class="mt-3 rounded-lg bg-slate-50 p-3" action="{{ route('repairs.quotations.request-customer-approval', [$branch, $repair, $quotation]) }}">
            @csrf
            <p class="mb-3 text-sm text-slate-600">หลังส่งใบเสนอราคาฉบับนี้ให้ลูกค้าแล้ว ให้บันทึกช่องทางที่ใช้ติดต่อ</p>
            <div class="flex flex-wrap items-end gap-3">
                <label class="block w-full sm:w-auto">
                    <span class="field-label">ช่องทางที่ส่งขออนุมัติ</span>
                    <select class="field-input" name="request_channel" required>
                        <option value="">เลือกช่องทาง</option>
                        @foreach (RepairQuotation::APPROVAL_CHANNELS as $channel => $label)
                            <option value="{{ $channel }}" @selected(old('request_channel') === $channel)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <button class="button button-secondary" type="submit">บันทึกการส่งขออนุมัติ</button>
            </div>
        </form>
    @endif

    @if ($quotation->approved_at)
        <dl class="mt-4 grid gap-3 sm:grid-cols-2">
            <div><dt class="detail-label">ช่องทางที่ลูกค้ายืนยัน</dt><dd>{{ RepairQuotation::APPROVAL_CHANNELS[$quotation->approval_channel] }}</dd></div>
            @if ($quotation->customer_approver_name)
                <div><dt class="detail-label">ผู้ยืนยันจากฝั่งลูกค้า</dt><dd class="wrap-break-word">{{ $quotation->customer_approver_name }}</dd></div>
            @endif
            <div><dt class="detail-label">เวลาที่ลูกค้ายืนยัน</dt><dd>{{ ($quotation->customer_confirmed_at ?? $quotation->approved_at)->timezone('Asia/Bangkok')->format('d/m/Y H:i') }} น.</dd></div>
            <div><dt class="detail-label">พนักงานผู้บันทึก</dt><dd>{{ $quotation->approvedBy->name }} · {{ $quotation->approved_at->timezone('Asia/Bangkok')->format('d/m/Y H:i') }} น.</dd></div>
            @if ($quotation->approval_note && ($canViewInternalNotes || (int) $quotation->approved_by === auth()->id()))
                <div class="sm:col-span-2"><dt class="detail-label">บันทึก</dt><dd class="whitespace-pre-wrap wrap-break-word">{{ $quotation->approval_note }}</dd></div>
            @endif
        </dl>
    @elseif ($canApprove)
        @if ($quotation->isExpired())
            <p class="mt-3 text-sm text-amber-800">ใบเสนอราคาหมดอายุ หากลูกค้ายืนยันก่อนหมดอายุ ให้ระบุวันและเวลาที่ลูกค้ายืนยัน</p>
        @endif
        <form method="POST" class="mt-4 space-y-4 border-t border-slate-200 pt-4" action="{{ route('repairs.quotations.approve', [$branch, $repair, $quotation]) }}">
            @csrf
            @method('PATCH')
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block">
                    <span class="field-label">ช่องทางที่ลูกค้ายืนยัน</span>
                    <select class="field-input" name="approval_channel" required>
                        <option value="">เลือกช่องทาง</option>
                        @foreach (RepairQuotation::APPROVAL_CHANNELS as $channel => $label)
                            <option value="{{ $channel }}" @selected(old('approval_channel', $quotation->customer_request_channel) === $channel)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('approval_channel') <span class="field-error" role="alert">{{ $message }}</span> @enderror
                </label>
                <label class="block">
                    <span class="field-label">ชื่อผู้ยืนยันจากฝั่งลูกค้า</span>
                    <input class="field-input" name="customer_approver_name" value="{{ is_string(old('customer_approver_name', $quotation->customer_name)) ? old('customer_approver_name', $quotation->customer_name) : '' }}" maxlength="150" required>
                    @error('customer_approver_name') <span class="field-error" role="alert">{{ $message }}</span> @enderror
                </label>
                <label class="block">
                    <span class="field-label">วันและเวลาที่ลูกค้ายืนยัน (เวลาไทย)</span>
                    <input class="field-input" type="datetime-local" name="approval_received_at" value="{{ is_string(old('approval_received_at')) ? old('approval_received_at') : '' }}" aria-describedby="customer-approval-time-help">
                    <span id="customer-approval-time-help" class="mt-1 block text-xs text-slate-600">เว้นว่างเพื่อใช้เวลาที่กดบันทึก</span>
                    @error('approval_received_at') <span class="field-error" role="alert">{{ $message }}</span> @enderror
                </label>
                <label class="block">
                    <span class="field-label">บันทึกเพิ่มเติม</span>
                    <textarea class="field-input" name="approval_note" rows="2" maxlength="1500">{{ is_string(old('approval_note')) ? old('approval_note') : '' }}</textarea>
                    @error('approval_note') <span class="field-error" role="alert">{{ $message }}</span> @enderror
                </label>
            </div>
            <label class="flex items-start gap-2">
                <input class="mt-1" type="checkbox" name="confirmation" value="1" required @checked(old('confirmation') === '1')>
                ลูกค้ายืนยันยอด {{ RepairQuotation::formatBaht($quotation->total_satang) }} บาท และเงื่อนไขของฉบับ {{ $quotation->reference() }}
            </label>
            <button class="button button-primary" type="submit">บันทึกการยืนยันของลูกค้า</button>
        </form>
    @elseif (! $isLatest)
        <p class="mt-2 text-slate-600">ฉบับก่อนหน้า เปิดประวัติเพื่อดูฉบับล่าสุด</p>
    @elseif (! $quotation->canRelease())
        <p class="mt-2 text-slate-600">ส่งขออนุมัติจากลูกค้าได้เมื่อผู้จัดการอนุมัติราคาแล้ว</p>
    @elseif ($repair->status === RepairStatus::Diagnosing)
        <p class="mt-2 text-slate-600">รอช่างส่งผลตรวจและเปลี่ยนสถานะใบงานเป็นรอลูกค้าอนุมัติ</p>
    @else
        <p class="mt-2 text-slate-600">สถานะใบงาน {{ $repair->status->label() }}</p>
    @endif
</section>
