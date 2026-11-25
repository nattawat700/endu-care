@php use App\Models\CustomerAppointment; @endphp

<x-layout title="นัดหมายสาขา">
    <div class="endu-page-head">
        <div>
            <a class="endu-text-action" href="{{ route('crm.customers.index', $branch) }}">← ทะเบียนลูกค้า</a>
            <h1>นัดหมายสาขา</h1>
            <p>{{ $branch->name }}</p>
        </div>
        <a class="button button-primary" href="{{ route('crm.customers.index', $branch) }}">เลือกลูกค้าเพื่อนัดหมาย</a>
    </div>
    <section class="panel p-5">
        <nav class="flex flex-wrap gap-2" aria-label="กรองสถานะนัดหมาย">
            @foreach (CustomerAppointment::STATUSES as $value => $label)
                <a href="{{ route('crm.appointments.index', ['branch' => $branch, 'status' => $value]) }}"
                    @class(['button', 'button-primary' => $filter === $value, 'button-secondary' => $filter !== $value])
                    @if ($filter === $value) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </nav>
        @include('crm.appointments.list', ['showCustomer' => true])
    </section>
</x-layout>
