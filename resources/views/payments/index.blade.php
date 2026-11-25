@php
    use App\Models\RepairQuotation;
    $money = static fn (int $amount): string => RepairQuotation::formatBaht($amount).' บาท';
@endphp

<x-layout title="รับเงินและออกใบเสร็จ" active-module="PAY">
    <div class="endu-page-head">
        <div class="min-w-0">
            <a class="endu-text-action" href="{{ route('payment-documents.index', $branch) }}">← ใบรับเงินและใบเสร็จ</a>
            <h1>รับเงินและออกใบเสร็จ</h1>
            <p class="wrap-break-word">{{ $repair->reference() }} · {{ $repair->device }} · {{ $branch->name }}</p>
        </div>
        @can('view', $repair)
            <a class="button button-secondary" href="{{ route('repairs.show', [$branch, $repair]) }}">ดูใบงานซ่อม</a>
        @endcan
    </div>
    @include('organizations.errors')
    <div class="mb-5 grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.6fr)]">
        <section class="panel min-w-0 p-5">
            <h2 class="text-lg font-semibold">ยอดชำระของใบงาน</h2>
            <p class="mt-2 text-sm wrap-break-word">{{ $repair->customer_name }}</p>
            @if ($billing['quotation'])
                <p class="mt-1 text-sm text-slate-600">อ้างอิง {{ $billing['quotation']->reference() }}</p>
            @elseif ($billing['payable'] !== null)
                <p class="mt-1 text-sm text-slate-600">ราคาที่ตกลงในใบงาน</p>
            @endif
            <p class="mt-3 font-semibold">{{ $billing['state'] }}</p>
            @if ($billing['due'])<p class="text-sm text-slate-600">กำหนดรับชำระ {{ $billing['due']->format('d/m/Y') }}</p>@endif
            <dl class="mt-4 space-y-3 text-sm">
                @if ($billing['total'] !== null)
                    <div class="flex justify-between gap-3"><dt>ยอดเต็ม</dt><dd class="tabular-nums">{{ $money($billing['total']) }}</dd></div>
                    @if ($billing['payable'] !== null && $billing['total'] !== $billing['payable'])
                        <div class="flex justify-between gap-3"><dt>ยอดที่ต้องรับหลังหัก ณ ที่จ่าย</dt><dd class="tabular-nums">{{ $money($billing['payable']) }}</dd></div>
                    @endif
                @endif
                <div class="flex justify-between gap-3"><dt>รับเงินสะสม</dt><dd class="tabular-nums">{{ $money($billing['paid']) }}</dd></div>
                @if ($billing['pending'])
                    <div class="flex justify-between gap-3"><dt>รอตรวจยอด</dt><dd>{{ $money($billing['pending']) }}</dd></div>
                @endif
                @if ($billing['refunded'])
                    <div class="flex justify-between gap-3"><dt>คืนเงินสะสม</dt><dd>{{ $money($billing['refunded']) }}</dd></div>
                @endif
                @if ($billing['remaining'] !== null)
                    <div class="flex justify-between gap-3 border-t border-slate-200 pt-2 font-bold">
                        <dt>{{ $billing['remaining'] < 0 ? 'ยอดรับเกินตามราคาล่าสุด' : 'คงเหลือ' }}</dt>
                        <dd class="tabular-nums">{{ $money(abs($billing['remaining'])) }}</dd>
                    </div>
                @endif
            </dl>
        </section>
        <section class="panel min-w-0 p-5">
            <h2 class="text-lg font-semibold">บันทึกรับเงิน</h2>
            @if ($billing['pending'] > 0)
                <p class="mt-3 text-sm">เปิดรายการรอตรวจจากประวัติด้านล่าง ก่อนบันทึกรับยอดถัดไป</p>
            @elseif ($repair->status === \App\Enums\RepairStatus::ReturnedUnrepaired)
                <p class="mt-3 text-sm text-slate-600">ใบงานนี้ส่งคืนโดยไม่ซ่อมแล้ว ดูเอกสารรับเงินที่ออกไว้ได้จากประวัติ</p>
            @elseif ($billing['remaining'] > 0 && ! $activeShift)
                <a class="button button-primary mt-3" href="{{ route('cash-shifts.index', $branch) }}">เปิดกะก่อนรับเงิน</a>
            @elseif ($billing['remaining'] > 0)
                @include('payments.receive-form')
            @elseif ($billing['payable'] === null)
                <p class="mt-3 text-sm text-slate-600">รับเงินได้เมื่อใบเสนอราคาฉบับล่าสุดได้รับการยืนยันจากลูกค้า หรือมีราคาที่ตกลงไว้ในใบงานซ่อมด่วน</p>
            @elseif ($billing['remaining'] === 0)
                <p class="mt-3 text-sm text-slate-600">ยอดที่ต้องชำระครบแล้ว เปิดพิมพ์เอกสารได้จากประวัติ</p>
            @else
                <p class="mt-3 text-sm text-slate-600">ยอดรับเดิมสูงกว่ายอดที่ต้องชำระล่าสุด ตรวจสอบกับฝ่ายบัญชีก่อนดำเนินการต่อ</p>
            @endif
        </section>
    </div>
    @include('payments.history')
</x-layout>
