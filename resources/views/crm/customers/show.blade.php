@php use App\Models\Customer; @endphp

<x-layout :title="$customer->name">
    <div class="endu-page-head">
        <div class="min-w-0">
            <a class="endu-text-action" href="{{ route('crm.customers.index', $branch) }}">← ทะเบียนลูกค้า</a>
            <h1 class="wrap-break-word">{{ $customer->name }}</h1>
            <p>{{ $branch->name }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a class="button button-secondary" href="{{ route('crm.customers.edit', [$branch, $customer]) }}">แก้ไขข้อมูล</a>
            <a class="button button-secondary" href="{{ route('crm.appointments.create', [$branch, $customer]) }}">เพิ่มนัดหมาย</a>
            <a class="button button-primary" href="{{ route('repairs.create', ['branch' => $branch, 'customer_id' => $customer->id]) }}">เปิดใบรับซ่อม</a>
        </div>
    </div>
    @include('organizations.errors')
    <div class="grid gap-5 lg:grid-cols-2">
        @can('permission', [$branch, \App\Enums\BranchPermission::PrivateCustomerDetails])
            @include('crm.customers.tax-profile')
        @endcan
        <section class="panel min-w-0 p-5">
            <h2 class="text-lg font-bold">ข้อมูลติดต่อ</h2>
            <dl class="mt-3 grid gap-3 sm:grid-cols-2">
                @foreach ([
                    'เบอร์โทรลูกค้า' => $customer->phone,
                    'ชื่อผู้ติดต่อ' => $customer->contact_name,
                    'เบอร์โทรผู้ติดต่อ' => $customer->contact_phone,
                    'อีเมล' => $customer->email,
                    'ที่อยู่รับ–ส่ง' => $customer->delivery_address,
                    'ที่อยู่ออกเอกสาร' => $customer->billing_address,
                    'ช่องทางแจ้งงานซ่อม' => Customer::CHANNELS[$customer->service_channel],
                    'ความประสงค์รับข่าวการตลาด' => Customer::MARKETING[$customer->marketing_preference],
                ] as $label => $value)
                    <div class="min-w-0">
                        <dt class="detail-label">{{ $label }}</dt>
                        <dd class="whitespace-pre-wrap wrap-break-word">{{ $value ?? '—' }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>
        <section class="panel min-w-0 p-5">
            <h2 class="text-lg font-bold">เครื่องของลูกค้า</h2>
            <ul class="mt-2 divide-y divide-slate-200">
                @forelse ($devices as $device)
                    <li class="py-3">
                        <a class="endu-text-action wrap-break-word" href="{{ route('crm.devices.show', [$branch, $customer, $device]) }}">{{ $device->model }}</a>
                        <p class="text-sm wrap-break-word text-slate-600">
                            @if ($device->serial_number) Serial {{ $device->serial_number }} @endif
                            @if ($device->imei) IMEI {{ $device->imei }} @endif
                        </p>
                    </li>
                @empty
                    <li class="py-3 text-sm text-slate-600">ยังไม่มีเครื่องในทะเบียน</li>
                @endforelse
            </ul>
            {{ $devices->links() }}
            <details class="mt-3 border-t border-slate-200 pt-3" @if ($errors->any()) open @endif>
                <summary class="cursor-pointer font-semibold">เพิ่มเครื่อง</summary>
                <form method="POST" action="{{ route('crm.devices.store', [$branch, $customer]) }}" class="mt-4 grid gap-3 sm:grid-cols-2">
                    @csrf
                    <x-repair-input name="model" label="รุ่น" :limit="150" required />
                    <x-repair-input name="serial_number" label="Serial No." :limit="80" />
                    <x-repair-input name="imei" label="IMEI" :limit="15" inputmode="numeric" />
                    <x-repair-input name="warranty_until" label="วันหมดประกัน" type="date" :limit="10" />
                    <div class="sm:col-span-2">
                        <x-repair-input name="warranty_note" label="รายละเอียดประกัน" :limit="1000" :rows="2" />
                    </div>
                    <button class="button button-primary justify-self-start" type="submit">บันทึกเครื่อง</button>
                </form>
            </details>
        </section>
        <section class="panel min-w-0 p-5">
            <h2 class="text-lg font-bold">ประวัติใบรับซ่อม</h2>
            @include('crm.customers.repair-history')
            @if ($outstanding->isNotEmpty())
                <h3 class="mt-4 font-semibold">ยอดค้างของใบงานในหน้านี้</h3>
                <ul class="text-sm">
                    @foreach ($outstanding as $jobId => $amount)
                        <li class="mt-1"><a class="endu-text-action" href="{{ route('repairs.show', [$branch, $jobId]) }}">{{ \App\Models\RepairJob::referenceFor($jobId) }}</a>
                            · {{ \App\Models\RepairQuotation::formatBaht($amount) }} บาท</li>
                    @endforeach
                </ul>
            @endif
        </section>
        <section class="panel min-w-0 p-5">
            <h2 class="text-lg font-bold">ประวัติเคลม</h2>
            <ul class="mt-3 divide-y divide-slate-200 text-sm">
                @forelse ($claims as $claim)
                    <li class="py-2"><a class="endu-text-action" href="{{ route('documents.source', [$branch, 'claim', $claim->id]) }}">{{ $claim->reference() }}</a>
                        · {{ \App\Models\ServiceClaim::STATUSES[$claim->status] }}</li>
                @empty <li class="py-2 text-slate-600">ยังไม่มีรายการเคลม</li>
                @endforelse
            </ul>
            {{ $claims->withQueryString()->links() }}
        </section>
        <section class="panel min-w-0 p-5">
            <h2 class="text-lg font-bold">ประวัติซื้อหน้าร้าน</h2>
            <ul class="mt-2 divide-y divide-slate-200">
                @forelse ($sales as $sale)
                    <li class="py-2 text-sm">
                        @can('permission', [$branch, \App\Enums\BranchPermission::ManagePos])
                            <a class="endu-text-action" href="{{ route('pos.show', [$branch, $sale]) }}">{{ $sale->reference() }}</a>
                        @else
                            <strong>{{ $sale->reference() }}</strong>
                        @endcan
                        · {{ $sale->paid_at->timezone('Asia/Bangkok')->format('d/m/Y') }}
                        · {{ \App\Models\RepairQuotation::formatBaht($sale->total_satang) }} บาท
                        · {{ \App\Models\PosSale::STATUSES[$sale->status] }}
                    </li>
                @empty <li class="py-2 text-sm text-slate-600">ยังไม่มีประวัติซื้อหน้าร้าน</li>
                @endforelse
            </ul>
            {{ $sales->links() }}
        </section>
        <section class="panel min-w-0 p-5">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-lg font-bold">นัดหมาย</h2>
                <a class="endu-text-action" href="{{ route('crm.appointments.index', $branch) }}">ดูนัดหมายสาขา</a>
            </div>
            @include('crm.appointments.list', ['showCustomer' => false])
        </section>
    </div>
</x-layout>
