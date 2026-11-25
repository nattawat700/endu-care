@php
    use App\Enums\BranchPermission;
    use App\Models\RepairQuotation;
    use Illuminate\Support\Carbon;

    $details = $repair->closure->details;
@endphp
<section class="panel mb-5 p-5" aria-labelledby="closure-heading">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h2 id="closure-heading" class="text-lg font-bold">บันทึกปิดใบงาน</h2>
        @can('permission', [$branch, BranchPermission::IssueServiceDocuments])
            <a class="button button-secondary" href="{{ route('documents.source', [$branch, 'repair', $repair->id]) }}">ออกเอกสารและรับเคลม</a>
        @endcan
    </div>
    <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
        @can('permission', [$branch, BranchPermission::CustomerData])
            <div><dt class="detail-label">ผู้รับเครื่อง</dt><dd>{{ $details['recipient'] }}</dd></div>
        @endcan
        <div><dt class="detail-label">วันส่งมอบ / ส่งคืน</dt><dd>{{ $repair->closure->closed_at->timezone('Asia/Bangkok')->format('d/m/Y H:i') }}</dd></div>
        @if ($billing !== null)
            <div><dt class="detail-label">สถานะรับเงิน ณ เวลาปิดงาน</dt><dd>{{ $details['billing_state'] }} · รับสุทธิ {{ RepairQuotation::formatBaht($details['collected']) }} บาท</dd></div>
        @endif
        @if ($details['warranty_days'])
            <div><dt class="detail-label">ประกันงานซ่อม</dt><dd>{{ $details['warranty_days'] }} วัน · {{ Carbon::parse($details['warranty_start'])->format('d/m/Y') }} ถึง {{ Carbon::parse($details['warranty_end'])->format('d/m/Y') }}</dd></div>
        @endif
    </dl>
    @if ($details['warranty_terms'])<p class="mt-3 whitespace-pre-wrap text-sm">{{ $details['warranty_terms'] }}</p>@endif
    @if ($details['return_reason'])<p class="mt-3 text-sm">{{ $details['return_reason'] }}</p>@endif
    @if ($details['note'])<p class="mt-3 whitespace-pre-wrap text-sm">{{ $details['note'] }}</p>@endif
    @if ($role->allows(BranchPermission::ApproveDepositRetention) && $details['retention_reason'])
        <p class="mt-3 whitespace-pre-wrap text-sm">เหตุผลอนุมัติคงยอด {{ $details['retention_reason'] }}</p>
    @endif
</section>
