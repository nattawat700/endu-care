@php
    use App\Models\PosSale;
    use App\Models\RepairQuotation;
@endphp
<x-layout title="ประวัติการขาย" :active-branch="$branch" active-module="POS">
    @include('pos.header', ['tab' => 'history'])
    <section class="panel overflow-hidden">
        <nav aria-label="สถานะบิล" class="flex flex-wrap gap-2 border-b border-slate-200 p-4">
            @foreach (['paid' => PosSale::STATUSES['paid'], 'draft' => 'บิลที่พัก', 'voided' => PosSale::STATUSES['voided']] as $key => $label)
                <a class="button {{ $status === $key ? 'button-primary' : 'button-secondary' }}" @if ($status === $key) aria-current="page" @endif
                    href="{{ route('pos.history', ['branch' => $branch, 'status' => $key]) }}">{{ $label }}</a>
            @endforeach
            @can('permission', [$branch, \App\Enums\BranchPermission::ManagePaymentDocuments])
                <a class="button button-secondary ml-auto" href="{{ route('cash-shifts.index', $branch) }}">กะรับเงิน</a>
            @endcan
        </nav>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-50 text-slate-700">
                    <tr><th class="px-4 py-3">เลขที่บิล</th><th class="px-4 py-3">ลูกค้า</th><th class="px-4 py-3">เคาน์เตอร์</th><th class="px-4 py-3">พนักงานขาย</th><th class="px-4 py-3 text-right">ยอดสุทธิ (บาท)</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    @forelse ($sales as $sale)
                        <tr>
                            <td class="px-4 py-3">
                                <a class="endu-text-action whitespace-nowrap font-semibold" href="{{ route('pos.show', [$branch, $sale]) }}">{{ $sale->reference() }}</a>
                                @if ($status === 'draft' && $sale->approval)<p class="mt-1 text-xs">{{ $sale->approval->statusLabel() }}</p>@endif
                                <p class="mt-1 whitespace-nowrap text-xs text-slate-600">{{ $sale->updated_at->timezone('Asia/Bangkok')->format('d/m/Y H:i') }}</p>
                            </td>
                            <td class="px-4 py-3">{{ $sale->buyer['name'] }}</td>
                            <td class="px-4 py-3">{{ $sale->counter->name }}</td>
                            <td class="px-4 py-3">{{ $sale->salesperson->name }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ RepairQuotation::formatBaht($sale->total_satang) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="p-5 text-slate-600">ยังไม่มีบิลในสถานะนี้</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($sales->hasPages())<div class="border-t border-slate-200 p-4">{{ $sales->links() }}</div>@endif
    </section>
</x-layout>
