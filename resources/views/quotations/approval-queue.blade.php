<x-layout title="คำขออนุมัติราคา">
    <div class="endu-page-head">
        <div>
            <a class="endu-text-action" href="{{ route('repairs.index', $branch) }}">← คิวงานซ่อม</a>
            <h1 class="mt-2">คำขออนุมัติราคา</h1>
            <p>{{ $branch->name }} · ใบเสนอราคาฉบับล่าสุดที่รอผู้จัดการพิจารณา</p>
        </div>
    </div>
    <section class="panel endu-latest" aria-labelledby="pending-quotation-heading">
        <div class="endu-panel-head"><h2 id="pending-quotation-heading">รายการรอพิจารณา</h2></div>
        <div class="endu-table-scroll">
            <table>
                <thead><tr>
                    <th scope="col">ใบเสนอราคา</th><th scope="col">ใบงาน</th><th scope="col">เครื่อง</th>
                    <th scope="col">ผู้ขอ</th><th scope="col">เวลาที่ขอ</th><th scope="col" class="text-right">ยอดรวม (บาท)</th>
                </tr></thead>
                <tbody>
                    @forelse ($quotations as $quotation)
                        <tr>
                            <td><a class="endu-text-action" href="{{ route('repairs.quotations.show', [$branch, $quotation->repairJob, $quotation]) }}">{{ $quotation->reference() }}</a></td>
                            <td>{{ $quotation->repairJob->reference() }}</td>
                            <td>{{ $quotation->device }}</td>
                            <td>{{ $quotation->internalRequester->name }}</td>
                            <td>{{ $quotation->internal_requested_at->timezone('Asia/Bangkok')->format('d/m/Y H:i') }}</td>
                            <td class="text-right tabular-nums">{{ $quotation->formatBaht($quotation->total_satang) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="endu-table-empty">ไม่มีคำขอรอพิจารณาในสาขานี้</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
    @if ($quotations->hasPages())
        <nav aria-label="หน้าคำขออนุมัติราคา" class="mt-4 flex justify-end gap-2">
            @if ($quotations->previousPageUrl()) <a class="button button-secondary" href="{{ $quotations->previousPageUrl() }}">ก่อนหน้า</a> @endif
            @if ($quotations->nextPageUrl()) <a class="button button-secondary" href="{{ $quotations->nextPageUrl() }}">ถัดไป</a> @endif
        </nav>
    @endif
</x-layout>
