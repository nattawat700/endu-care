<x-layout title="เปิดใบรับซ่อม">
    <a href="{{ route('repairs.index', $branch) }}" class="text-sm font-semibold text-sky-800 hover:underline">← คิวงานซ่อม</a>
    <h1 class="mt-3 text-3xl font-semibold tracking-tight">เปิดใบรับซ่อม</h1>
    <p class="mt-2 text-sm text-slate-600">{{ $branch->name }}</p>

    <form method="POST" action="{{ route('repairs.store', $branch) }}" class="panel mt-6 max-w-3xl p-5 sm:p-7">
        @csrf
        <input type="hidden" name="submission_id" value="{{ $errors->has('submission_id') ? $submissionId : old('submission_id', $submissionId) }}">
        @error('submission_id') <p role="alert" class="field-error mb-4">แบบฟอร์มหมดอายุ กรุณาตรวจข้อมูลแล้วบันทึกใหม่</p> @enderror
        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <label class="field-label" for="customer-name">ชื่อลูกค้า @if ($isEnduFix)<span aria-hidden="true">*</span>@endif</label>
                <input class="field-input" id="customer-name" name="customer_name" value="{{ old('customer_name') }}" maxlength="150" required @error('customer_name') aria-invalid="true" aria-describedby="customer-name-error" @enderror>
                @error('customer_name') <p id="customer-name-error" class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="field-label" for="customer-phone">เบอร์โทรศัพท์ @if ($isEnduFix)<span aria-hidden="true">*</span>@endif</label>
                <input class="field-input" id="customer-phone" name="customer_phone" type="tel" value="{{ old('customer_phone') }}" maxlength="30" required @error('customer_phone') aria-invalid="true" aria-describedby="customer-phone-error" @enderror>
                @error('customer_phone') <p id="customer-phone-error" class="field-error">{{ $message }}</p> @enderror
            </div>
            @if ($isEnduFix)
                @include('repairs.endu-fix-intake-fields')
            @else
                <div class="sm:col-span-2">
                    <label class="field-label" for="device">อุปกรณ์และรุ่น</label>
                    <input class="field-input" id="device" name="device" value="{{ old('device') }}" maxlength="150" placeholder="เช่น iPhone 13 สีดำ" required @error('device') aria-invalid="true" aria-describedby="device-error" @enderror>
                    @error('device') <p id="device-error" class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label class="field-label" for="issue">อาการที่แจ้ง</label>
                    <textarea class="field-input" id="issue" name="issue" rows="5" maxlength="2000" required @error('issue') aria-invalid="true" aria-describedby="issue-error" @enderror>{{ old('issue') }}</textarea>
                    @error('issue') <p id="issue-error" class="field-error">{{ $message }}</p> @enderror
                </div>
            @endif
        </div>
        <div class="mt-6 flex flex-wrap justify-end gap-2">
            <a class="button button-secondary" href="{{ route('repairs.index', $branch) }}">ยกเลิก</a>
            <button class="button button-primary" type="submit">บันทึกใบรับซ่อม</button>
        </div>
    </form>
</x-layout>
