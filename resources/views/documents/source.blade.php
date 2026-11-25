@php
    use App\Enums\BranchPermission;
    use App\Enums\RepairStatus;
    use App\Models\IssuedDocument;
    use App\Models\ServiceClaim;

    $reference = $source === 'refund' ? 'REFUND-'.$record->id : $record->reference();
    $claimable = ($source === 'repair' && $record->status === RepairStatus::Delivered) || ($source === 'pos' && $record->status === 'paid');
@endphp

<x-layout :title="$reference" active-module="DOC">
    <div class="endu-page-head">
        <div><a class="endu-text-action" href="{{ route('documents.index', $branch) }}">← เอกสารงานบริการ</a>
            <h1>{{ $reference }}</h1>
            <p>{{ $branch->name }}</p>
        </div>
    </div>
    @include('organizations.errors')
    <div class="grid gap-5 lg:grid-cols-2">
        <section class="panel p-5">
            <h2 class="text-lg font-bold">ออกเอกสารจากรายการนี้</h2>
            <ul class="mt-3 divide-y divide-slate-200">
                @foreach ($kinds as $kind)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-3 text-sm">
                        <span>{{ IssuedDocument::KINDS[$kind][0] }}</span>
                        @if (isset($documents[$kind]))
                            <a class="button button-secondary" href="{{ route('documents.show', [$branch, $documents[$kind]]) }}">{{ $documents[$kind]->number }}</a>
                        @else
                            <form method="POST" action="{{ route('documents.issue', [$branch, $source, $record->id]) }}">
                                @csrf
                                <input type="hidden" name="kind" value="{{ $kind }}">
                                <button class="button button-primary" @disabled(
                                    ($kind === 'tax_full' && ! ($branch->issuance_settings['enabled'] ?? false))
                                    || ($kind === 'tax_short' && ! ($branch->issuance_settings['abbreviated'] ?? false))
                                    || ($kind === 'claim_result' && $record->status === 'received')
                                )>ออกเอกสาร</button>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
        @if ($claimable)
            <section class="panel p-5">
                <h2 class="text-lg font-bold">รับเคลมจากรายการนี้</h2>
                <form method="POST" action="{{ route('documents.claims.store', [$branch, $source, $record->id]) }}" class="mt-4 space-y-3">
                    @csrf
                    <input type="hidden" name="submission_id" value="{{ old('submission_id', $submissionId) }}">
                    <x-repair-input name="claimant" label="ผู้แจ้งเคลม" :limit="150" required />
                    <x-repair-input name="item" label="สินค้า / เครื่อง / งานที่เคลม" :limit="180" required />
                    <x-repair-input name="issue" label="อาการหรือเหตุผลที่เคลม" :rows="3" :limit="1500" required />
                    <button class="button button-primary">บันทึกรับเคลม</button>
                </form>
            </section>
        @elseif ($source === 'claim')
            <section class="panel space-y-2 p-5 text-sm">
                <h2 class="text-lg font-bold">{{ ServiceClaim::STATUSES[$record->status] }}</h2>
                <a class="endu-text-action" href="{{ route('documents.source', [$branch, $record->repair_job_id ? 'repair' : 'pos', $record->repair_job_id ?? $record->pos_sale_id]) }}">{{ $record->intake['reference'] }}</a>
                <p>ผู้แจ้ง {{ $record->intake['claimant'] }}</p>
                <p>{{ $record->intake['item'] }}</p>
                <p class="whitespace-pre-wrap wrap-break-word">{{ $record->intake['issue'] }}</p>
                @if ($record->review)
                    <p>{{ $record->review['reviewer'] }} · {{ $record->reviewed_at->timezone('Asia/Bangkok')->format('d/m/Y H:i') }}</p>
                    <p class="whitespace-pre-wrap wrap-break-word">{{ $record->review['reason'] }}</p>
                @else
                    @can('permission', [$branch, BranchPermission::ReviewServiceClaims])
                        <form method="POST" action="{{ route('documents.claims.review', [$branch, $record]) }}" class="space-y-3 border-t border-slate-200 pt-3">
                            @csrf @method('PATCH')
                            <x-repair-input name="reason" label="เหตุผลพิจารณา" :limit="1000" :rows="2" required />
                            <button class="button button-primary" name="decision" value="approved">อนุมัติเคลม</button>
                            <button class="button button-secondary" name="decision" value="rejected">ไม่อนุมัติ</button>
                        </form>
                    @endcan
                @endif
            </section>
        @endif
    </div>
</x-layout>
