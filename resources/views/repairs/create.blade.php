<x-layout title="เปิดใบรับซ่อม">
    <a href="{{ route('repairs.index', $branch) }}" class="text-sm font-semibold text-sky-800 hover:underline">← คิวงานซ่อม</a>
    <h1 class="mt-3 text-3xl font-semibold tracking-tight">เปิดใบรับซ่อม</h1>
    <p class="mt-2 text-sm text-slate-600">{{ $branch->name }}</p>
    @can('permission', [$branch, \App\Enums\BranchPermission::ManageCustomers])
        <p class="mt-2 text-sm">
            @if ($customer)
                ผูกกับ <a class="endu-text-action" href="{{ route('crm.customers.show', [$branch, $customer]) }}">{{ $customer->name }}</a>
                @if ($registeredDevice) · {{ $registeredDevice->model }} @endif
                <a class="endu-text-action ml-2" href="{{ route('repairs.create', $branch) }}">เปิดใบรับซ่อมแบบกรอกเอง</a>
            @else
                <a class="endu-text-action" href="{{ route('crm.customers.index', $branch) }}">เลือกจากทะเบียนลูกค้า</a>
            @endif
        </p>
    @endcan

    <form method="POST" action="{{ route('repairs.store', $branch) }}" class="panel mt-6 max-w-3xl p-5 sm:p-7">
        @csrf
        <input type="hidden" name="submission_id" value="{{ $errors->has('submission_id') ? $submissionId : old('submission_id', $submissionId) }}">
        @if ($customer)
            <input type="hidden" name="customer_id" value="{{ $customer->id }}">
        @endif
        @if ($registeredDevice)
            <input type="hidden" name="customer_device_id" value="{{ $registeredDevice->id }}">
        @endif
        @error('submission_id') <p role="alert" class="field-error mb-4">แบบฟอร์มหมดอายุ กรุณาตรวจข้อมูลแล้วบันทึกใหม่</p> @enderror
        <fieldset class="mb-5 grid gap-4 border-b border-slate-200 pb-5 sm:grid-cols-2">
            <legend class="font-semibold">ประเภทและคิวงาน</legend>
            @if ($canAssign)
                <div>
                    <label class="field-label" for="work-kind">ประเภทใบงาน</label>
                    <select class="field-input" id="work-kind" name="work_kind" data-repair-kind>
                        @foreach (\App\Enums\RepairKind::cases() as $kind)
                            <option value="{{ $kind->value }}" @selected(old('work_kind', 'assessment') === $kind->value)>{{ $kind->label() }}</option>
                        @endforeach
                    </select>
                    @error('work_kind') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div data-quick-price>
                    <x-repair-input name="fixed_price" label="ราคาที่ตกลง (บาท) สำหรับงานด่วน" :limit="10" inputmode="decimal" />
                </div>
                <div>
                    <label class="field-label" for="intake-technician">ช่างผู้รับผิดชอบ</label>
                    <select class="field-input" id="intake-technician" name="technician_id" data-required-for-quick>
                        <option value="">ยังไม่มอบหมาย</option>
                        @foreach ($technicians as $technician)
                            <option value="{{ $technician->user_id }}" @selected((string) old('technician_id') === (string) $technician->user_id)>{{ $technician->user->name }}</option>
                        @endforeach
                    </select>
                    @error('technician_id') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            @endif
            <x-repair-input name="due_at" label="วันและเวลานัด (เวลาไทย)" type="datetime-local" :limit="16" data-required-for-quick />
        </fieldset>
        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <label class="field-label" for="customer-name">ชื่อลูกค้า @if ($isEnduFix)<span aria-hidden="true">*</span>@endif</label>
                <input class="field-input" id="customer-name" name="customer_name" value="{{ old('customer_name', $customer?->name) }}" maxlength="150" required @error('customer_name') aria-invalid="true" aria-describedby="customer-name-error" @enderror>
                @error('customer_name') <p id="customer-name-error" class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="field-label" for="customer-phone">เบอร์โทรศัพท์ @if ($isEnduFix)<span aria-hidden="true">*</span>@endif</label>
                <input class="field-input" id="customer-phone" name="customer_phone" type="tel" value="{{ old('customer_phone', $customer?->phone) }}" maxlength="30" required @error('customer_phone') aria-invalid="true" aria-describedby="customer-phone-error" @enderror>
                @error('customer_phone') <p id="customer-phone-error" class="field-error">{{ $message }}</p> @enderror
            </div>
            @if ($isEnduFix)
                @include('repairs.endu-fix-intake-fields')
            @else
                <div class="sm:col-span-2">
                    <label class="field-label" for="device">อุปกรณ์และรุ่น</label>
                    <input class="field-input" id="device" name="device" value="{{ old('device', $registeredDevice?->model) }}" maxlength="150" placeholder="เช่น iPhone 13 สีดำ" required @error('device') aria-invalid="true" aria-describedby="device-error" @enderror>
                    @error('device') <p id="device-error" class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label class="field-label" for="issue">อาการที่แจ้ง</label>
                    <textarea class="field-input" id="issue" name="issue" rows="5" maxlength="2000" required @error('issue') aria-invalid="true" aria-describedby="issue-error" @enderror>{{ old('issue') }}</textarea>
                    @error('issue') <p id="issue-error" class="field-error">{{ $message }}</p> @enderror
                </div>
                <x-repair-input name="imei" label="IMEI" :limit="15" inputmode="numeric" :default="$registeredDevice?->imei" :readonly="$registeredDevice?->imei !== null" />
                <x-repair-input name="serial_number" label="Serial No." :limit="80" :default="$registeredDevice?->serial_number" :readonly="$registeredDevice?->serial_number !== null" />
                <x-repair-input name="device_condition" label="สภาพเครื่อง" :limit="500" :rows="2" />
                <x-repair-input name="accessories" label="อุปกรณ์ที่นำมาด้วย" :limit="500" :rows="2" />
            @endif
            <x-repair-input name="storage_location" label="จุดเก็บเครื่อง" :limit="120" placeholder="เช่น ตู้ A / ชั้น 2 / ช่อง 03" />
        </div>
        <div class="mt-6 flex flex-wrap justify-end gap-2">
            <a class="button button-secondary" href="{{ route('repairs.index', $branch) }}">ยกเลิก</a>
            <button class="button button-primary" type="submit">บันทึกใบรับซ่อม</button>
        </div>
    </form>
</x-layout>
