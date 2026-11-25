@php use App\Models\CustomerAppointment; @endphp

<ul class="mt-2 divide-y divide-slate-200">
    @forelse ($appointments as $appointment)
        <li class="flex flex-wrap items-center justify-between gap-2 py-3">
            <div class="min-w-0 wrap-break-word">
                <a class="endu-text-action font-semibold" href="{{ route('crm.appointments.edit', [$branch, $appointment]) }}">
                    {{ CustomerAppointment::KINDS[$appointment->kind] }} · {{ $appointment->starts_at->timezone('Asia/Bangkok')->format('d/m/Y H:i') }}
                </a>
                <p class="text-sm text-slate-600">
                    @if ($showCustomer) {{ $appointment->customer->name }} · @endif
                    ผู้รับผิดชอบ {{ $appointment->responsible->name }}
                </p>
            </div>
            <span class="text-sm">{{ CustomerAppointment::STATUSES[$appointment->status] }}</span>
        </li>
    @empty
        <li class="py-3 text-sm text-slate-600">ยังไม่มีนัดหมายในรายการนี้</li>
    @endforelse
</ul>
{{ $appointments->links() }}
