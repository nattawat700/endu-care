<div class="grid gap-4 rounded-lg bg-slate-50 p-4 sm:grid-cols-2">
    <div>
        <h2 class="text-sm font-bold">สาขาที่ออกใบเสนอราคา</h2>
        <p class="mt-1 text-sm text-slate-700">{{ $seller['name'] }}</p>
        @can('permission', [$branch, \App\Enums\BranchPermission::ManageBranchSettings])
            <a class="endu-text-action mt-1 text-sm" href="{{ route('branch-settings.document.preview', $branch) }}">ตรวจหัวเอกสารสาขา</a>
        @endcan
    </div>
    <label class="block">
        <span class="field-label">วันหมดอายุใบเสนอราคา</span>
        <input class="field-input" type="date" name="valid_until" value="{{ $value('valid_until') }}" min="{{ $today }}" required>
    </label>
</div>

<fieldset class="grid gap-3 border-t border-slate-200 pt-4 sm:grid-cols-2">
    <legend class="pr-3 font-bold">ผู้ซื้อและผู้ประสานงาน</legend>
    @foreach ([
        'buyer_name' => ['ชื่อผู้ซื้อ / บริษัท', 'text', 150], 'buyer_phone' => ['โทรศัพท์ผู้ซื้อ', 'tel', 30],
        'buyer_email' => ['อีเมลผู้ซื้อ', 'email', 254], 'buyer_tax_id' => ['เลขผู้เสียภาษีผู้ซื้อ', 'text', 13],
        'buyer_office' => ['สำนักงานใหญ่ / สาขาของผู้ซื้อ', 'text', 100],
        'contact_name' => ['ชื่อผู้ประสานงาน', 'text', 150], 'contact_phone' => ['โทรศัพท์ผู้ประสานงาน', 'tel', 30],
        'contact_email' => ['อีเมลผู้ประสานงาน', 'email', 254],
    ] as $key => [$label, $type, $max])
        <label class="block">
            <span class="field-label">{{ $label }}</span>
            <input class="field-input" name="{{ $key }}" type="{{ $type }}" maxlength="{{ $max }}" value="{{ $value($key) }}" @required($key === 'buyer_name') @if ($key === 'buyer_tax_id') inputmode="numeric" pattern="[0-9]{13}" @endif>
        </label>
    @endforeach
    <label class="block sm:col-span-2">
        <span class="field-label">ที่อยู่ผู้ซื้อ</span>
        <textarea class="field-input" name="buyer_address" maxlength="750" rows="2">{{ $value('buyer_address') }}</textarea>
    </label>
</fieldset>
