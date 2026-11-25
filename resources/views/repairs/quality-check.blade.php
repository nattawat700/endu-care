<section class="panel mt-5 p-5 sm:p-7" id="repair-qc" aria-labelledby="repair-qc-title">
    <h2 id="repair-qc-title" class="text-lg font-semibold">ตรวจคุณภาพก่อนส่งมอบ (QC)</h2>
    @if ($repair->status === \App\Enums\RepairStatus::AwaitingQualityCheck && $role->allows(\App\Enums\BranchPermission::InspectRepair))
        <form method="POST" action="{{ route('repairs.quality-checks.store', [$branch, $repair]) }}" class="mt-4 space-y-4">
            @csrf
            <input type="hidden" name="workflow_version" value="{{ $repair->workflow_version }}">
            <input type="hidden" name="assignment_version" value="{{ $repair->assignment_version }}">
            @error('qc_results') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            @foreach ($qualityChecklist as $key => $label)
                <fieldset class="rounded-lg border border-slate-200 px-3 pb-3">
                    <legend class="px-1 text-sm font-semibold">{{ $label }}</legend>
                    <div class="mt-1 flex flex-wrap gap-x-6 gap-y-2">
                        @foreach (['pass' => 'ผ่าน', 'fail' => 'ไม่ผ่าน'] as $value => $text)
                            <label class="flex items-center gap-2">
                                <input type="radio" name="qc_results[{{ $key }}]" value="{{ $value }}" @checked(old('qc_results.'.$key) === $value) required>
                                {{ $text }}
                            </label>
                        @endforeach
                    </div>
                    @error('qc_results.'.$key) <p class="field-error" role="alert">{{ $message }}</p> @enderror
                </fieldset>
            @endforeach
            <x-repair-input name="qc_note" label="บันทึกผลหรือสิ่งที่ต้องแก้ไข" :rows="2" :limit="2000" />
            <button class="button button-primary" type="submit">บันทึกผล QC</button>
        </form>
    @endif

    @if ($qualityChecks->isEmpty())
        <p class="mt-4 text-sm text-slate-600">ยังไม่มีผลตรวจ QC</p>
    @else
        <ol class="mt-4 divide-y divide-slate-200" aria-label="ประวัติผลตรวจ QC">
            @foreach ($qualityChecks as $check)
                <li class="py-3">
                    <details>
                        <summary class="cursor-pointer font-semibold">
                            <span @class(['badge', 'bg-emerald-100 text-emerald-900' => $check->passed, 'bg-red-100 text-red-900' => ! $check->passed])>{{ $check->passed ? 'ผ่าน' : 'ไม่ผ่าน' }}</span>
                            {{ $check->inspector->name }} · {{ $check->created_at->timezone('Asia/Bangkok')->format('d/m/Y H:i') }} น.
                        </summary>
                        <div class="mt-3">
                            <ul class="space-y-1 pl-4 text-sm">
                                @foreach ($check->items as $item)
                                    <li><strong>{{ $item['result'] === 'pass' ? 'ผ่าน' : 'ไม่ผ่าน' }}</strong> · {{ $item['label'] }}</li>
                                @endforeach
                            </ul>
                            @if ($check->note)<p class="mt-2 whitespace-pre-wrap wrap-break-word text-sm">{{ $check->note }}</p>@endif
                        </div>
                    </details>
                </li>
            @endforeach
        </ol>
    @endif
    <div class="mt-3">{{ $qualityChecks->fragment('repair-qc')->links() }}</div>
</section>
