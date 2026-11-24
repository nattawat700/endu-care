@if ($nextStatuses !== [] || $errors->hasAny(['next_status', 'workflow_version', 'workflow_note']))
    <section class="panel p-5 sm:p-7">
        <h2 class="text-lg font-semibold">อัปเดตสถานะงาน</h2>
        @error('next_status') <p role="alert" class="field-error">{{ $message }}</p> @enderror
        @error('workflow_version') <p role="alert" class="field-error">{{ $message }}</p> @enderror
        @error('workflow_note') <p role="alert" class="field-error">{{ $message }}</p> @enderror
        <div class="mt-3 space-y-4 divide-y divide-slate-200">
            @foreach ($nextStatuses as $next)
                @php($noteLabel = $repair->status->noteLabel($next))
                <form method="POST" action="{{ route('repairs.change-status', [$branch, $repair]) }}" class="space-y-3 first:pt-0 pt-4">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="next_status" value="{{ $next->value }}">
                    <input type="hidden" name="workflow_version" value="{{ $repair->workflow_version }}">
                    <input type="hidden" name="assignment_version" value="{{ $repair->assignment_version }}">
                    @if ($noteLabel !== null)
                        <div>
                            <label class="field-label" for="workflow-note-{{ $next->value }}">{{ $noteLabel }}</label>
                            <textarea class="field-input" id="workflow-note-{{ $next->value }}" name="workflow_note" rows="3" maxlength="2000" required>{{ old('next_status') === $next->value ? old('workflow_note') : '' }}</textarea>
                        </div>
                    @endif
                    <button @class(['button', 'button-secondary' => $next->value === 'returned_unrepaired', 'button-primary' => $next->value !== 'returned_unrepaired']) type="submit">{{ $repair->status->actionLabel($next) }}</button>
                </form>
            @endforeach
        </div>
    </section>
@endif
