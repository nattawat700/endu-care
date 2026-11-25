@php
    $selectedKind = $connection?->kind ?? old('kind');
    $credentials = $connection?->kind === 'accounting' ? ($connection->credentials ?? []) : [];
@endphp
<form method="POST" action="{{ $connection ? route('integrations.connections.update', [$branch, $connection]) : route('integrations.connections.store', $branch) }}" class="mt-3 grid gap-4 sm:grid-cols-2" data-integration-form>
    @csrf
    @if ($connection) @method('PATCH') @endif
    @if ($errors->any())
        <ul class="space-y-1 text-sm text-red-800 sm:col-span-2" role="alert">
            @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
        </ul>
    @endif
    @if (! $connection)
        <x-crm-select name="kind" label="ประเภทการเชื่อมต่อ" :options="\App\Models\IntegrationConnection::KINDS" :default="$selectedKind" />
        <x-repair-input name="account_ref" label="รหัสอ้างอิงบัญชี" :limit="80" required />
    @endif
    <x-repair-input name="name" label="ชื่อบัญชีที่ใช้ในระบบ" :limit="100" :default="$connection?->name" required />
    <x-crm-select name="enabled" label="สถานะ" :options="[0 => 'ปิดใช้', 1 => 'เปิดใช้']" :default="(int) ($connection?->enabled ?? false)" />
    @if (! $connection || isset(\App\Support\IntegrationEvents::TOPICS[$selectedKind]))
        <div data-connector-fields="endu store shipping">
            <label class="field-label" for="webhook_secret">กุญแจ webhook</label>
            <input class="field-input" id="webhook_secret" name="webhook_secret" type="password" minlength="32" maxlength="128" autocomplete="new-password">
            <p class="mt-1 text-xs text-slate-600">ใช้อักษรอังกฤษ ตัวเลข ขีดกลาง หรือขีดล่าง 32–128 ตัว {{ $connection ? 'เว้นว่างเพื่อใช้กุญแจเดิม' : '' }}</p>
        </div>
    @endif
    @if (! $connection || in_array($selectedKind, ['line', 'payment'], true))
        <div data-connector-fields="line payment">
            <label class="field-label" for="connector_token">โทเคนหรือกุญแจของบัญชี</label>
            <input class="field-input" id="connector_token" name="connector_token" type="password" maxlength="500" autocomplete="new-password">
            <p class="mt-1 text-xs text-slate-600">เก็บแบบเข้ารหัส {{ $connection ? 'เว้นว่างเพื่อใช้ค่าเดิม' : '' }}</p>
        </div>
    @endif
    @if (! $connection || $selectedKind === 'accounting')
        <fieldset class="grid gap-3 sm:col-span-2 sm:grid-cols-2" data-connector-fields="accounting">
            <legend class="mb-2 font-semibold">รหัสบัญชีสำหรับเอกสารคลัง</legend>
            @foreach (['inventory_account' => 'สินค้าคงคลัง', 'receiving_account' => 'คู่บัญชีรับเข้า', 'repair_account' => 'ค่าอะไหล่งานซ่อม', 'sales_cost_account' => 'ต้นทุนขาย'] as $key => $label)
                <x-repair-input :name="$key" :label="$label" :limit="80" :default="$credentials[$key] ?? null" />
            @endforeach
        </fieldset>
    @endif
    <div class="sm:col-span-2"><button class="button button-primary" type="submit">บันทึกการเชื่อมต่อ</button></div>
</form>
