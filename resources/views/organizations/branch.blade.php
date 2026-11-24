<x-layout :title="$branch->name">
    <div class="endu-page-head">
        <div>
            <a class="endu-text-action" href="{{ route('organizations.show', $branch->organization) }}">← {{ $branch->organization->name }}</a>
            <h1 class="mt-2">ตั้งค่าสาขา</h1>
            <p>{{ $branch->name }} · {{ $branch->code }}</p>
        </div>
        <a class="button button-secondary" href="{{ route('organization-approvals.index', $branch) }}">คำขออนุมัติรายการ</a>
    </div>
    @include('organizations.errors')

    <div class="grid gap-5 xl:grid-cols-2">
        <section class="panel p-5">
            <h2 class="text-lg font-bold">ข้อมูลสาขา</h2>
            <form method="POST" action="{{ route('branch-settings.update', $branch) }}" class="mt-4 space-y-3">
                @csrf
                @method('PATCH')
                @include('organizations.branch-fields', ['editableBranch' => $branch])
                <button class="button button-primary" type="submit">บันทึกข้อมูลสาขา</button>
            </form>
        </section>
        <section class="panel p-5">
            <h2 class="text-lg font-bold">วันทำการและกะเริ่มต้น</h2>
            <form method="POST" action="{{ route('branch-settings.schedule', $branch) }}" class="mt-4 space-y-4">
                @csrf
                @method('PATCH')
                <fieldset>
                    <legend class="field-label">วันทำการ (เวลาไทย)</legend>
                    <div class="flex flex-wrap gap-3 text-sm">
                        @foreach (\App\Http\Controllers\BranchSettingsController::WORKING_DAYS as $day => $label)
                            <label class="flex items-center gap-1"><input type="checkbox" name="working_days[]" value="{{ $day }}" @checked(in_array($day, $branch->working_days ?? range(1, 7), true))> {{ $label }}</label>
                        @endforeach
                    </div>
                </fieldset>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block"><span class="field-label">ชื่อกะเริ่มต้น</span><input class="field-input" name="shift_name" value="{{ $branch->shift_name ?? 'กะหลัก' }}" maxlength="80" required></label>
                    <label class="block"><span class="field-label">จุดตัดวันทำการ</span><input class="field-input" type="time" name="day_cutoff" value="{{ $branch->day_cutoff ?? '00:00' }}" required></label>
                    <label class="block"><span class="field-label">เวลาเริ่มกะ</span><input class="field-input" type="time" name="shift_start" value="{{ $branch->shift_start ?? '09:00' }}" required></label>
                    <label class="block"><span class="field-label">เวลาจบกะ</span><input class="field-input" type="time" name="shift_end" value="{{ $branch->shift_end ?? '18:00' }}" required></label>
                </div>
                <p class="text-sm text-slate-600">ใบรับซ่อมที่บันทึกก่อนจุดตัดวันจะนับเป็นวันทำการก่อนหน้า</p>
                <button class="button button-primary" type="submit">บันทึกวันทำการและกะ</button>
            </form>
        </section>
        <section class="panel p-5 xl:col-span-2">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-bold">หัวเอกสารสาขา</h2>
                    <p class="mt-1 text-sm text-slate-600">ใช้ข้อมูลในส่วนนี้กับใบรับซ่อมและใบเสนอราคาของสาขา หากเว้นชื่อ ที่อยู่ หรือเบอร์โทร ระบบจะใช้ข้อมูลทั่วไปของสาขา</p>
                </div>
                <a class="button button-secondary" href="{{ route('branch-settings.document.preview', $branch) }}">ดูตัวอย่างหัวเอกสาร</a>
            </div>
            @include('organizations.document-form', ['action' => route('branch-settings.document', $branch), 'profile' => $branch->document_profile ?? [], 'nameLabel' => 'ชื่อสาขาบนเอกสาร'])
        </section>
        @include('organizations.counter-settings')
    </div>
</x-layout>
