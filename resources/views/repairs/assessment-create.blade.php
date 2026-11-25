@php
    $assessment = $repair->latestAssessment;
    $items = \App\Support\RepairPriceItems::formValues($assessment?->items ?? []);
    $formVersion = fn (string $name, int $current) => is_scalar(old($name)) ? old($name) : $current;
@endphp

<x-layout title="บันทึกผลตรวจและประเมินราคา">
    <div class="endu-page-head">
        <div>
            <a class="endu-text-action" href="{{ route('repairs.show', [$branch, $repair]) }}#repair-assessment">← กลับใบงานซ่อม</a>
            <h1 class="mt-2">บันทึกผลตรวจและประเมินราคา</h1>
            <p>{{ $repair->reference() }} · {{ $repair->device }}</p>
        </div>
    </div>
    @if ($assessment)
        <p class="mb-4 text-sm text-slate-600">แก้ไขจากผลประเมินครั้งที่ {{ $assessment->revision }} เมื่อบันทึก ระบบจะเก็บเป็นผลประเมินครั้งใหม่</p>
    @endif
    @if ($canRequestPriceChange)
        <p class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">หากพบงานเพิ่ม ให้ระบุงานที่ต้องทำและรายการค่าแรงกับอะไหล่ทั้งหมด แล้วกดแจ้งงานเพิ่มเพื่อพักงานรออนุมัติราคาใหม่</p>
    @endif
    @if ($errors->any())
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-4 text-red-800" role="alert">
            <ul class="list-disc space-y-1 pl-4">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
            @error('assessment_revision') <a class="mt-2 inline-block font-semibold underline" href="{{ route('repairs.assessment.create', [$branch, $repair]) }}">โหลดผลประเมินล่าสุด</a> @enderror
        </div>
    @endif
    <form method="POST" data-price-form action="{{ route('repairs.assessment.store', [$branch, $repair]) }}" class="panel space-y-5 p-5 sm:p-7">
        @csrf
        <input type="hidden" name="submission_id" value="{{ $errors->has('submission_id') ? $submissionId : old('submission_id', $submissionId) }}">
        <input type="hidden" name="assessment_revision" value="{{ $formVersion('assessment_revision', $assessment?->revision ?? 0) }}">
        <input type="hidden" name="assignment_version" value="{{ $formVersion('assignment_version', $repair->assignment_version) }}">
        <input type="hidden" name="workflow_version" value="{{ $formVersion('workflow_version', $repair->workflow_version) }}">
        <input type="hidden" name="due_version" value="{{ $formVersion('due_version', $repair->due_version) }}">
        <h2 class="text-lg font-bold">ผลตรวจเครื่อง</h2>
        <div class="grid gap-4 sm:grid-cols-2">
            <label class="block">
                <span class="field-label">สาเหตุที่ตรวจพบ</span>
                <textarea class="field-input" name="cause" rows="4" maxlength="2000" required>{{ is_string(old('cause', $assessment?->cause)) ? old('cause', $assessment?->cause) : '' }}</textarea>
            </label>
            <label class="block">
                <span class="field-label">วิธีซ่อมที่เสนอ</span>
                <textarea class="field-input" name="repair_plan" rows="4" maxlength="2000" required>{{ is_string(old('repair_plan', $assessment?->repair_plan)) ? old('repair_plan', $assessment?->repair_plan) : '' }}</textarea>
            </label>
            <label class="block">
                <span class="field-label">วันและเวลาคาดว่าจะซ่อมเสร็จ (เวลาไทย)</span>
                <input class="field-input" type="datetime-local" name="expected_completion_at" value="{{ is_string(old('expected_completion_at')) ? old('expected_completion_at') : $repair->due_at?->timezone('Asia/Bangkok')->format('Y-m-d\TH:i') }}" required>
            </label>
        </div>
        <div class="space-y-3 border-t border-slate-200 pt-4">
            @include('repairs.price-items')
        </div>
        <div class="flex flex-wrap justify-end gap-2 border-t border-slate-200 pt-4">
            <a class="button button-secondary" href="{{ route('repairs.show', [$branch, $repair]) }}#repair-assessment">ยกเลิก</a>
            <button class="button {{ $canSend || $canRequestPriceChange ? 'button-secondary' : 'button-primary' }}" type="submit" @disabled($errors->has('assessment_revision'))>บันทึกผลประเมิน</button>
            @if ($canSend)
                <button class="button button-primary" type="submit" name="send_to_reception" value="1" @disabled($errors->has('assessment_revision'))>บันทึกและส่งให้หน้าร้าน</button>
            @elseif ($canRequestPriceChange)
                <button class="button button-primary" type="submit" name="request_price_change" value="1" @disabled($errors->has('assessment_revision'))>แจ้งงานเพิ่มและพักงานซ่อม</button>
            @endif
        </div>
    </form>
</x-layout>
