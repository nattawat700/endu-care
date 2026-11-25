@php
    use App\Models\CustomerAppointment;

    $title = $appointment->exists ? 'รายละเอียดนัดหมาย' : 'เพิ่มนัดหมาย';
    $customerUrl = route('crm.customers.show', [$branch, $customer]);
    $canEdit = ! $appointment->exists || $appointment->status === 'scheduled';
    $updateUrl = $appointment->exists ? route('crm.appointments.update', [$branch, $appointment]) : null;
@endphp

<x-layout :title="$title">
    <div class="endu-page-head">
        <div class="min-w-0">
            <a class="endu-text-action wrap-break-word" href="{{ $customerUrl }}">← {{ $customer->name }}</a>
            <h1>{{ $title }}</h1>
            <p>{{ $branch->name }} · เวลาไทย</p>
        </div>
        <a class="button button-secondary" href="{{ route('crm.appointments.index', $branch) }}">นัดหมายสาขา</a>
    </div>
    <div class="panel max-w-3xl space-y-4 p-5 sm:p-7">
        @if ($appointment->exists)
            <dl class="grid gap-3 sm:grid-cols-2">
                <div><dt class="detail-label">นัดหมาย</dt><dd>{{ CustomerAppointment::KINDS[$appointment->kind] }}</dd></div>
                <div><dt class="detail-label">สถานะล่าสุด</dt><dd>{{ CustomerAppointment::STATUSES[$appointment->status] }}</dd></div>
                <div><dt class="detail-label">วันและเวลานัด</dt><dd>{{ $appointment->starts_at->timezone('Asia/Bangkok')->format('d/m/Y H:i') }}</dd></div>
                <div><dt class="detail-label">ผู้รับผิดชอบ</dt><dd class="wrap-break-word">{{ $appointment->responsible->name }}</dd></div>
                @if ($appointment->note)
                    <div class="sm:col-span-2"><dt class="detail-label">หมายเหตุ</dt><dd class="whitespace-pre-wrap wrap-break-word">{{ $appointment->note }}</dd></div>
                @endif
            </dl>
        @endif
        @error('action') <p class="field-error" role="alert">{{ $message }}</p> @enderror
        @if ($canEdit)
            <form method="POST" action="{{ $updateUrl ?? route('crm.appointments.store', [$branch, $customer]) }}"
                @class(['grid gap-4 sm:grid-cols-2', 'border-t border-slate-200 pt-4' => $appointment->exists])>
                @csrf
                @if ($appointment->exists)
                    @method('PATCH')
                    <input type="hidden" name="action" value="reschedule">
                    <input type="hidden" name="version" value="{{ $appointment->version }}">
                @else
                    <input type="hidden" name="submission_id" value="{{ $errors->has('submission_id') ? $submissionId : old('submission_id', $submissionId) }}">
                @endif
                <x-crm-select name="kind" label="ประเภทนัดหมาย" :options="CustomerAppointment::KINDS" :default="$appointment->kind ?? 'receive'" />
                <x-repair-input name="starts_at" label="วันและเวลานัด" type="datetime-local" :limit="16" :default="$appointment->starts_at?->timezone('Asia/Bangkok')->format('Y-m-d\TH:i')" required />
                <x-crm-select name="responsible_user_id" label="ผู้รับผิดชอบ" :options="$staff->pluck('user.name', 'user_id')" :default="$appointment->responsible_user_id" />
                <div class="sm:col-span-2">
                    <x-repair-input name="note" label="หมายเหตุ" :rows="3" :limit="500" :default="$appointment->note" />
                </div>
                <button class="button button-primary justify-self-start" type="submit">{{ $appointment->exists ? 'บันทึกการเลื่อนนัด' : 'บันทึกนัดหมาย' }}</button>
            </form>
            @if ($appointment->exists)
                <form method="POST" action="{{ $updateUrl }}" class="flex flex-wrap gap-2 border-t border-slate-200 pt-4">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="version" value="{{ $appointment->version }}">
                    <button class="button button-secondary" type="submit" name="action" value="completed">บันทึกว่าเข้ารับบริการแล้ว</button>
                    <button class="button button-secondary" type="submit" name="action" value="cancelled">ยกเลิกนัดหมาย</button>
                </form>
            @endif
        @endif
    </div>
</x-layout>
