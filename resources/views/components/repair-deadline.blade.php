@props(['repair', 'now'])

@if ($repair->due_at)
    <div class="flex flex-wrap items-center gap-2">
        <time class="whitespace-nowrap" datetime="{{ $repair->due_at->toIso8601String() }}">{{ $repair->due_at->timezone('Asia/Bangkok')->format('d/m/Y H:i') }}</time>
        @if (! $repair->status->isClosed())
            @if ($repair->due_at->lt($now))
                <span class="badge bg-red-100 text-red-900">เกินนัด</span>
            @elseif ($repair->due_at->lt(\App\Models\RepairJob::endOfDueDay($now)))
                <span class="badge bg-amber-100 text-amber-900">ถึงกำหนดวันนี้</span>
            @endif
        @endif
    </div>
@else
    <span class="text-slate-600">ยังไม่นัด</span>
@endif
