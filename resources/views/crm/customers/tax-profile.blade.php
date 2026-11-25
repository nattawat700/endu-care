@php($profile = $customer->tax_profile ?? [])
<section class="panel min-w-0 p-5">
    <h2 class="text-lg font-bold">ข้อมูลออกใบกำกับภาษี</h2>
    <form class="mt-3 space-y-3" method="POST" action="{{ route('crm.customers.tax-profile', [$branch, $customer]) }}">
        @csrf @method('PATCH')
        <x-crm-select name="type" label="ประเภทผู้ซื้อ" :options="['individual' => 'บุคคลธรรมดา', 'company' => 'นิติบุคคล']" :default="$profile['type'] ?? 'individual'" />
        <x-repair-input name="name" label="ชื่อบนใบกำกับภาษี" :limit="180" :default="$profile['name'] ?? ''" required />
        <x-repair-input name="address" label="ที่อยู่บนใบกำกับภาษี" :limit="500" :rows="2" :default="$profile['address'] ?? ''" required />
        <x-repair-input name="tax_id" label="เลขประจำตัวผู้เสียภาษี" :limit="13" inputmode="numeric" :default="$profile['tax_id'] ?? ''" />
        <div class="grid gap-3 sm:grid-cols-2">
            <x-crm-select name="office" label="สถานประกอบการ (นิติบุคคล)" :options="['head' => 'สำนักงานใหญ่', 'branch' => 'สาขา']" :default="$profile['office'] ?? 'head'" />
            <x-repair-input name="office_number" label="เลขสาขา (กรณีสาขา)" :limit="5" inputmode="numeric" :default="$profile['office_number'] ?? ''" />
        </div>
        <button class="button button-primary">บันทึกข้อมูลภาษี</button>
    </form>
</section>
