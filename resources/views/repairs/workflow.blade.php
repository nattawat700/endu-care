@if ($nextStatuses !== [] || $errors->hasAny(['next_status', 'workflow_version', 'workflow_note', 'recipient', 'retention_reason', 'warranty_days', 'warranty_terms']))
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
                    @if ($next->isClosed())
                        <label class="block"><span class="field-label">ผู้รับเครื่อง</span>
                            <input name="recipient" class="field-input" maxlength="150" value="{{ old('next_status') === $next->value ? old('recipient', $repair->customer_name) : $repair->customer_name }}" required>
                        </label>
                        @if ($next === \App\Enums\RepairStatus::Delivered)
                            <label class="block"><span class="field-label">ระยะประกันงาน (วัน)</span>
                                <input name="warranty_days" type="number" class="field-input" min="0" max="3650" value="{{ old('warranty_days', 0) }}" required>
                            </label>
                            <label class="block"><span class="field-label">เงื่อนไขประกันงาน</span><textarea name="warranty_terms" class="field-input" maxlength="1000" rows="2">{{ old('warranty_terms') }}</textarea></label>
                        @else
                            <x-crm-select name="return_reason" label="ประเภทการส่งคืน" :options="\App\Support\RepairClosing::RETURN_REASONS" />
                            @if (($billing['paid'] ?? 0) > 0 && $role->allows(\App\Enums\BranchPermission::ApproveDepositRetention))
                                <label class="block"><span class="field-label">เหตุผลอนุมัติให้คงยอดที่รับไว้ (ถ้ามี)</span>
                                    <textarea name="retention_reason" class="field-input" rows="2" maxlength="500">{{ old('retention_reason') }}</textarea>
                                </label>
                            @endif
                            @if (($billing['paid'] ?? 0) > 0)
                                <a class="endu-text-action text-sm" href="{{ route('repairs.payments.index', [$branch, $repair]) }}">เปิดรายการต้นทางเพื่อคืนเงิน</a>
                            @endif
                        @endif
                        @foreach (['recipient', 'retention_reason', 'warranty_days', 'warranty_terms'] as $field)
                            @error($field) <p class="field-error" role="alert">{{ $message }}</p> @enderror
                        @endforeach
                    @endif
                    <button @class(['button', 'button-secondary' => $next->value === 'returned_unrepaired', 'button-primary' => $next->value !== 'returned_unrepaired']) type="submit">{{ $repair->status->actionLabel($next) }}</button>
                </form>
            @endforeach
        </div>
    </section>
@endif
