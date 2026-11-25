<section class="panel mt-5 p-5 sm:p-7" id="assignment-history" aria-labelledby="assignment-history-title">
    <h2 id="assignment-history-title" class="text-lg font-semibold">ประวัติการมอบหมายช่าง</h2>
    <ol class="mt-3 divide-y divide-slate-100">
        @forelse ($assignments as $event)
            @php($previous = $event->before_values['technician_id'] ?? null)
            @php($current = $event->after_values['technician_id'])
            <li class="py-3 text-sm">
                <p class="font-semibold">{{ $previous === null ? 'ยังไม่มอบหมาย' : $assignmentNames->get($previous, 'พนักงาน #'.$previous) }}
                    → {{ $assignmentNames->get($current, 'พนักงาน #'.$current) }}</p>
                <p class="mt-1 text-slate-600">{{ $event->actor_name ?? 'ระบบ' }} · {{ $event->occurred_at->timezone('Asia/Bangkok')->format('d/m/Y H:i') }} น.</p>
            </li>
        @empty
            <li class="py-3 text-sm text-slate-600">ยังไม่มีประวัติการมอบหมายในระบบ</li>
        @endforelse
    </ol>
    <div class="mt-3">{{ $assignments->fragment('assignment-history')->links() }}</div>
</section>
