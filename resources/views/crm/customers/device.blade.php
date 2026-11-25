<x-layout :title="$device->model">
    <div class="endu-page-head">
        <div class="min-w-0">
            <a class="endu-text-action" href="{{ route('crm.customers.show', [$branch, $customer]) }}">← {{ $customer->name }}</a>
            <h1 class="wrap-break-word">{{ $device->model }}</h1>
            <p>{{ $branch->name }}</p>
        </div>
        <a class="button button-primary" href="{{ route('repairs.create', ['branch' => $branch, 'customer_id' => $customer->id, 'customer_device_id' => $device->id]) }}">รับซ่อมเครื่องนี้</a>
    </div>
    <div class="grid gap-5 lg:grid-cols-2">
        <section class="panel min-w-0 p-5">
            <h2 class="text-lg font-bold">ข้อมูลเครื่องและประกัน</h2>
            <dl class="mt-3 grid gap-3 sm:grid-cols-2">
                <div class="min-w-0"><dt class="detail-label">Serial No.</dt><dd class="wrap-break-word">{{ $device->serial_number ?? '—' }}</dd></div>
                <div><dt class="detail-label">IMEI</dt><dd>{{ $device->imei ?? '—' }}</dd></div>
            </dl>
            <form class="mt-4 grid gap-3 sm:grid-cols-2" method="POST" action="{{ route('crm.devices.update', [$branch, $customer, $device]) }}">
                @csrf
                @method('PATCH')
                <x-repair-input name="model" label="รุ่น" :limit="150" :default="$device->model" required />
                <x-repair-input name="warranty_until" label="วันหมดประกัน" type="date" :limit="10" :default="$device->warranty_until?->format('Y-m-d')" />
                <div class="sm:col-span-2">
                    <x-repair-input name="warranty_note" label="รายละเอียดประกัน" :rows="3" :limit="1000" :default="$device->warranty_note" />
                </div>
                <button class="button button-primary justify-self-start" type="submit">บันทึกข้อมูลเครื่อง</button>
            </form>
        </section>
        <section class="panel min-w-0 p-5">
            <h2 class="text-lg font-bold">ประวัติซ่อมของเครื่อง</h2>
            @include('crm.customers.repair-history')
            <form class="mt-4 space-y-3 border-t border-slate-200 pt-4" method="POST" action="{{ route('crm.devices.link-repair', [$branch, $customer, $device]) }}">
                @csrf
                <h3 class="font-semibold">เชื่อมใบรับซ่อมเดิม</h3>
                <p class="text-sm text-slate-600">ใช้เลขใบงานของสาขานี้ พร้อม Serial No. หรือ IMEI ที่ตรงกัน หากใบงานยังไม่ได้ผูกลูกค้า เบอร์โทรต้องตรงกับทะเบียนด้วย</p>
                <x-repair-input name="reference" label="เลขใบงาน" placeholder="REP-000001" :limit="22" required />
                <button class="button button-secondary" type="submit">เชื่อมใบงาน</button>
            </form>
        </section>
    </div>
</x-layout>
