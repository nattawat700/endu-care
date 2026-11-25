<ul class="divide-y divide-slate-200">
    @forelse ($repairs as $repair)
        <li class="flex flex-wrap justify-between gap-2 py-3">
            <div class="min-w-0 wrap-break-word">
                <a class="endu-text-action font-semibold" href="{{ route('repairs.show', [$branch, $repair]) }}">{{ $repair->reference() }}</a>
                <p class="text-sm text-slate-600">{{ $repair->device }} · {{ $repair->status->label() }}</p>
                @if ($repair->warranty_status || $repair->policy_number)
                    <p class="mt-1 text-sm">
                        @if ($repair->warranty_status) {{ $repair->warranty_status === 'covered' ? 'ในประกัน' : 'นอกประกัน' }} @endif
                        @if ($repair->policy_number) เลขที่กรมธรรม์ {{ $repair->policy_number }} @endif
                    </p>
                @endif
            </div>
            <p class="text-sm text-slate-600">{{ $repair->created_at->timezone('Asia/Bangkok')->format('d/m/Y') }}</p>
        </li>
    @empty
        <li class="py-3 text-sm text-slate-600">ยังไม่มีใบรับซ่อมที่เชื่อม</li>
    @endforelse
</ul>
{{ $repairs->links() }}
