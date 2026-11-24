@php($assessment = $repair->latestAssessment)

<section id="repair-assessment" class="panel mt-5 p-5 sm:p-7" aria-labelledby="repair-assessment-title">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 id="repair-assessment-title" class="text-lg font-bold">ผลตรวจและประเมินราคา</h2>
            @if ($assessment)
                <p class="mt-1 text-sm text-slate-600">ครั้งที่ {{ $assessment->revision }} · {{ $assessment->recordedBy->name }} · {{ $assessment->created_at->timezone('Asia/Bangkok')->format('d/m/Y H:i') }} น.</p>
            @endif
        </div>
        @if ($repair->status->allowsQuotation())
            <div class="endu-actions">
                @if ($role->allows(\App\Enums\BranchPermission::EditAssessment))
                    <a class="button button-secondary" href="{{ route('repairs.assessment.create', [$branch, $repair]) }}">{{ $assessment ? 'แก้ผลประเมิน' : 'บันทึกผลตรวจและประเมินราคา' }}</a>
                @endif
                @if ($assessment && $role->allows(\App\Enums\BranchPermission::DraftQuotation))
                    <a class="button button-primary" href="{{ route('repairs.quotations.create', [$branch, $repair, 'from_assessment' => 1]) }}">ใช้ผลประเมินจัดทำใบเสนอราคา</a>
                @endif
            </div>
        @endif
    </div>

    @if ($assessment)
        <dl class="mt-4 grid gap-4 sm:grid-cols-2">
            <div><dt class="detail-label">สาเหตุที่ตรวจพบ</dt><dd class="whitespace-pre-wrap wrap-break-word">{{ $assessment->cause }}</dd></div>
            <div><dt class="detail-label">วิธีซ่อมที่เสนอ</dt><dd class="whitespace-pre-wrap wrap-break-word">{{ $assessment->repair_plan }}</dd></div>
            <div><dt class="detail-label">คาดว่าจะซ่อมเสร็จ</dt><dd>{{ $assessment->expected_completion_at->timezone('Asia/Bangkok')->format('d/m/Y H:i') }} น.</dd></div>
        </dl>
        <div class="endu-table-scroll mt-4">
            <table>
                <caption class="sr-only">รายการประเมินค่าแรงและอะไหล่</caption>
                <thead><tr><th scope="col">ประเภท</th><th scope="col">รายการ</th><th scope="col" class="text-right">จำนวน</th><th scope="col" class="text-right">ราคา/หน่วย</th><th scope="col" class="text-right">รวม (บาท)</th></tr></thead>
                <tbody>
                    @foreach ($assessment->items as $item)
                        <tr>
                            <td>{{ \App\Models\RepairQuotation::ITEM_TYPES[$item['type']] }}</td>
                            <td class="wrap-break-word">{{ $item['description'] }}</td>
                            <td class="text-right tabular-nums">{{ number_format($item['quantity']) }}</td>
                            <td class="text-right tabular-nums">{{ \App\Models\RepairQuotation::formatBaht($item['unit_satang']) }}</td>
                            <td class="text-right tabular-nums">{{ \App\Models\RepairQuotation::formatBaht($item['total_satang']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="mt-3 text-right font-bold tabular-nums">ยอดประเมินรวม {{ \App\Models\RepairQuotation::formatBaht($assessment->total_satang) }} บาท</p>
    @else
        <p class="mt-3 text-sm text-slate-600">ยังไม่มีผลตรวจและประเมินราคาที่บันทึกจากแบบฟอร์มนี้</p>
    @endif
</section>
