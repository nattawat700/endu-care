<section class="panel p-5 sm:p-7">
    <h2 class="text-lg font-semibold">วันนัดล่าสุด</h2>
    <div class="mt-2 text-sm"><x-repair-deadline :repair="$repair" :now="$now" /></div>
    @if (! $repair->status->isClosed() && $role->allows(\App\Enums\BranchPermission::EditRepairDeadline))
        <form method="POST" action="{{ route('repairs.change-deadline', [$branch, $repair]) }}" class="mt-4 space-y-3">
            @csrf
            @method('PATCH')
            <input type="hidden" name="due_version" value="{{ $repair->due_version }}">
            <input type="hidden" name="assignment_version" value="{{ $repair->assignment_version }}">
            <x-repair-input name="due_at" label="วันและเวลานัดใหม่ (เวลาไทย)" type="datetime-local" :limit="16"
                :default="$repair->due_at?->timezone('Asia/Bangkok')->format('Y-m-d\TH:i')" />
            <button class="button button-secondary" type="submit">บันทึกวันนัด</button>
        </form>
    @endif
</section>
