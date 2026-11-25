@props(['status'])

<span @class([
    'badge',
    'badge-neutral' => $status->value === 'received',
    'bg-amber-100 text-amber-900' => in_array($status->value, ['awaiting_approval', 'waiting_parts'], true),
    'bg-violet-100 text-violet-900' => $status->value === 'awaiting_qc',
    'bg-emerald-100 text-emerald-900' => $status->value === 'ready' || $status->value === 'delivered',
    'bg-slate-200 text-slate-700' => $status->value === 'returned_unrepaired',
    'badge-assigned' => $status->value === 'diagnosing' || $status->value === 'repairing',
])>{{ $status->label() }}</span>
