<x-layout title="ใบเสนอราคา">
    <div class="endu-page-head">
        <div>
            <a class="endu-text-action" href="{{ route('repairs.show', [$branch, $repair]) }}">← กลับใบงานซ่อม</a>
            <h1 class="mt-2">ใบเสนอราคา</h1>
            <p>{{ $repair->reference() }} · {{ $repair->device }}</p>
        </div>
        @if ($repair->status->allowsQuotation() && $canDraftQuote)
            <a class="button button-primary" href="{{ route('repairs.quotations.create', [$branch, $repair]) }}"><x-workspace-icon name="plus" /> {{ $latest ? 'ออกฉบับแก้' : 'ออกใบเสนอราคา' }}</a>
        @endif
    </div>
    @if ($latest && $repair->status === \App\Enums\RepairStatus::AwaitingApproval && ! $latest->approved_at && ! $latest->voided_at)
        <p class="mb-4 rounded-lg border border-sky-200 bg-sky-50 p-3 text-sm text-sky-900">
            <a class="font-semibold underline" href="{{ route('repairs.quotations.show', [$branch, $repair, $latest]) }}{{ $latest->canRelease() ? '#customer-approval' : '' }}">เปิด {{ $latest->reference() }}</a>
            {{ $latest->canRelease() ? 'เพื่อบันทึกการส่งขออนุมัติหรือการยืนยันของลูกค้า' : 'เพื่อตรวจขั้นตอนอนุมัติราคา' }}
        </p>
    @endif
    <section class="panel endu-latest" aria-labelledby="quotation-history">
        <div class="endu-panel-head"><h2 id="quotation-history">ประวัติใบเสนอราคา</h2></div>
        <div class="endu-table-scroll">
            <table>
                <thead><tr>
                    <th scope="col">เลขที่ / ฉบับ</th>
                    <th scope="col">วันที่ออก</th>
                    <th scope="col" class="text-right">รวม (บาท)</th>
                    <th scope="col">อนุมัติภายใน</th>
                    <th scope="col">ยืนยันจากลูกค้า</th>
                    <th scope="col">ผู้จัดทำ</th>
                </tr></thead>
                <tbody>
                    @forelse ($history as $quote)
                        <tr>
                            <td><a class="endu-text-action" href="{{ route('repairs.quotations.show', [$branch, $repair, $quote]) }}">{{ $quote->reference() }}</a></td>
                            <td>{{ $quote->created_at->timezone('Asia/Bangkok')->format('d/m/Y H:i') }}</td>
                            <td class="text-right tabular-nums">{{ $quote->formatBaht($quote->total_satang) }}</td>
                            <td><span class="badge badge-neutral">{{ $quote->internalApprovalLabel($quote->is($latest)) }}</span></td>
                            <td>
                                @if ($quote->approved_at)
                                    <span class="badge bg-emerald-100 text-emerald-900">{{ $quote->voided_at ? 'เคยยืนยันก่อนยกเลิก' : 'ลูกค้ายืนยันแล้ว' }}</span>
                                @elseif ($quote->is($latest) && $quote->canRelease() && $repair->status === \App\Enums\RepairStatus::AwaitingApproval)
                                    <span class="badge bg-amber-100 text-amber-900">{{ $quote->customer_requested_at ? 'ส่งแล้ว · รอลูกค้ายืนยัน' : 'พร้อมส่งขออนุมัติ' }}</span>
                                @endif
                                <span class="badge badge-neutral">{{ $quote->is($latest) ? 'ฉบับล่าสุด' : 'ฉบับก่อนหน้า' }}</span>
                            </td>
                            <td>{{ $quote->issuer_name }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="endu-table-empty">ยังไม่มีใบเสนอราคาสำหรับใบงานนี้ @if ($canDraftQuote && ! $repair->status->allowsQuotation() && ! $repair->status->isClosed()) เริ่มตรวจเครื่องก่อนออกใบเสนอราคา @endif</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
    @if ($history->hasPages())
        <nav aria-label="หน้าประวัติใบเสนอราคา" class="mt-4 flex justify-end gap-2">
            @if ($history->previousPageUrl()) <a class="button button-secondary" href="{{ $history->previousPageUrl() }}">ใหม่กว่า</a> @endif
            @if ($history->nextPageUrl()) <a class="button button-secondary" href="{{ $history->nextPageUrl() }}">เก่ากว่า</a> @endif
        </nav>
    @endif
</x-layout>
