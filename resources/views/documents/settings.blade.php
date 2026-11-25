@php
    use App\Models\IssuedDocument;
    use App\Models\RepairQuotation;

    $settings = $branch->issuance_settings ?? [];
@endphp

<x-layout title="ตั้งค่าเอกสารสาขา" active-module="ORG">
    <div class="endu-page-head">
        <div><h1>ตั้งค่าเอกสารสาขา</h1><p>{{ $branch->name }}</p></div>
        <a class="button button-secondary" href="{{ route('branch-settings.show', $branch) }}">กลับการตั้งค่าสาขา</a>
    </div>
    @include('organizations.errors')
    <div class="grid gap-5 xl:grid-cols-2">
        <section class="panel p-5">
            <h2 class="text-lg font-bold">ใบกำกับภาษี</h2>
            <form method="POST" action="{{ route('documents.settings.tax', $branch) }}" class="mt-4 space-y-3">
                @csrf @method('PATCH')
                <input type="hidden" name="enabled" value="0">
                <label class="block text-sm"><input type="checkbox" name="enabled" value="1" @checked($settings['enabled'] ?? false)> เปิดใบกำกับภาษีเต็มรูปสำหรับสาขานี้</label>
                <input type="hidden" name="abbreviated" value="0">
                <label class="block text-sm"><input type="checkbox" name="abbreviated" value="1" @checked($settings['abbreviated'] ?? false)> อนุมัติให้ใช้แบบใบกำกับภาษีอย่างย่อ</label>
                <p class="text-sm text-slate-600">ราคาที่ไม่มี VAT แยกในต้นทางจะถือว่ารวมภาษีตามอัตราที่ตั้งไว้</p>
                <x-repair-input name="rate" label="อัตรา VAT (%)" :limit="6" :default="isset($settings['rate']) ? RepairQuotation::formatBaht($settings['rate'], '') : ''" />
                <x-repair-input name="office_number" label="รหัสสถานประกอบการผู้ขาย 5 หลัก" :limit="5" :default="$settings['office_number'] ?? ''" placeholder="00000 สำหรับสำนักงานใหญ่" />
                <button class="button button-primary">บันทึกการตั้งค่าภาษี</button>
            </form>
        </section>
        <section class="panel p-5">
            <h2 class="text-lg font-bold">ชุดเลขเอกสาร</h2>
            @foreach (IssuedDocument::KINDS as $kind => [$title, $default])
                <form method="POST" action="{{ route('documents.settings.series', $branch) }}" class="grid items-end gap-2 border-b border-slate-200 py-3 sm:grid-cols-[1fr_1fr_auto]">
                    @csrf @method('PATCH')
                    <input type="hidden" name="kind" value="{{ $kind }}">
                    <h3 class="text-sm font-semibold sm:col-span-3">{{ $title }}</h3>
                    <label class="block"><span class="field-label">คำนำหน้า</span><input class="field-input" name="prefix" value="{{ $series[$kind]->prefix ?? $default }}" maxlength="12" required></label>
                    <label class="block"><span class="field-label">เลขถัดไป</span><input class="field-input" type="number" name="next_number" min="{{ $series[$kind]->next_number ?? 1 }}" max="999999999999" value="{{ $series[$kind]->next_number ?? 1 }}" required></label>
                    <button class="button button-secondary">บันทึก</button>
                </form>
            @endforeach
        </section>
    </div>
</x-layout>
