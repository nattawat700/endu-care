<section class="panel endu-latest" aria-labelledby="deadline-alert-heading">
    <div class="endu-panel-head endu-panel-head--split">
        <div>
            <h2 id="deadline-alert-heading">แจ้งเตือนงานที่ฉันรับผิดชอบ</h2>
            <p>งานถึงกำหนดวันนี้และงานเกินนัด เรียงตามวันนัดล่าสุด</p>
        </div>
        <div class="flex flex-wrap gap-3">
            <a class="endu-text-action" href="{{ route('repairs.index', [$activeBranch, 'due' => 'overdue', 'mine' => 1]) }}">งานเกินนัด ({{ number_format($deadlineCounts['overdue']) }})</a>
            <a class="endu-text-action" href="{{ route('repairs.index', [$activeBranch, 'due' => 'today', 'mine' => 1]) }}">งานถึงกำหนดวันนี้ ({{ number_format($deadlineCounts['today']) }})</a>
        </div>
    </div>
    <ul class="divide-y divide-slate-100 border-t border-slate-100 px-5" aria-labelledby="deadline-alert-heading">
        @forelse ($deadlineAlerts as $repair)
            <li class="flex flex-wrap items-center justify-between gap-2 py-3 text-sm">
                <div class="min-w-0">
                    <a class="endu-text-action font-semibold" href="{{ route('repairs.show', [$activeBranch, $repair]) }}">{{ $repair->reference() }}</a>
                    <span class="ml-2 wrap-break-word">{{ $repair->device }}</span>
                </div>
                <x-repair-deadline :repair="$repair" :now="$now" />
            </li>
        @empty
            <li class="py-4 text-sm text-slate-600">ไม่มีงานถึงกำหนดวันนี้หรือเกินนัดในความรับผิดชอบของคุณ</li>
        @endforelse
    </ul>
</section>
