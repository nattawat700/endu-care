<x-layout title="ทะเบียนลูกค้า">
    <div class="endu-page-head">
        <div>
            <h1>ทะเบียนลูกค้า</h1>
            <p>{{ $branch->name }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a class="button button-secondary" href="{{ route('crm.appointments.index', $branch) }}">นัดหมายสาขา</a>
            <a class="button button-primary" href="{{ route('crm.customers.create', $branch) }}">เพิ่มลูกค้า</a>
        </div>
    </div>
    <div class="panel p-4 sm:p-5">
        <form method="GET" action="{{ route('crm.customers.index', $branch) }}" class="flex flex-wrap items-end gap-2" role="search">
            <div class="min-w-0 grow sm:max-w-md">
                <label class="field-label" for="crm-search">ค้นชื่อหรือเบอร์โทรศัพท์</label>
                <input class="field-input" type="search" id="crm-search" name="q" value="{{ $search }}" maxlength="150">
            </div>
            <button class="button button-primary" type="submit">ค้นหา</button>
            @if ($search !== '')
                <a class="button button-secondary" href="{{ route('crm.customers.index', $branch) }}">ล้างคำค้น</a>
            @endif
        </form>

        <ul class="mt-4 divide-y divide-slate-200">
            @forelse ($customers as $customer)
                <li class="flex flex-wrap items-center justify-between gap-2 py-3">
                    <a class="endu-text-action min-w-0 font-semibold wrap-break-word" href="{{ route('crm.customers.show', [$branch, $customer]) }}">{{ $customer->name }}</a>
                    <span class="text-sm text-slate-600">{{ $customer->phone }}</span>
                </li>
            @empty
                <li class="py-3 text-sm text-slate-600">{{ $search === '' ? 'ยังไม่มีลูกค้าในทะเบียนสาขานี้' : 'ไม่พบลูกค้าตามคำค้น' }}</li>
            @endforelse
        </ul>
    </div>
    <div class="mt-4">{{ $customers->links() }}</div>
</x-layout>
