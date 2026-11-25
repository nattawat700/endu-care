@php
    use App\Enums\BranchPermission;
    use App\Models\PosSale;
    use App\Models\RepairQuotation;
    use App\Support\PosCart;

    $draft = $sale->status === 'draft';
    $paid = $sale->status === 'paid';
    $money = fn (int $value) => RepairQuotation::formatBaht($value);
    $canManage = auth()->user()->can('permission', [$branch, BranchPermission::ManagePos]);
    $canReceive = auth()->user()->can('permission', [$branch, BranchPermission::ManagePaymentDocuments]);
    $inCounter = $shift?->branch_counter_id === $sale->branch_counter_id;
    $editable = $draft && ($sale->price_group === 'retail' || auth()->user()->can('permission', [$branch, BranchPermission::SetPosPriceGroup]));
    $received = $sale->tenders->firstWhere('direction', 'received');
@endphp

<x-layout :title="$sale->reference()" :active-branch="$branch" active-module="POS">
    <div class="endu-page-head">
        <div>
            <a href="{{ route('pos.history', ['branch' => $branch, 'status' => $sale->status]) }}" class="endu-text-action">← ขายหน้าร้าน</a>
            <h1>{{ $sale->reference() }}</h1>
            <p>{{ $branch->name }} · {{ $sale->counter->name }} · {{ PosSale::STATUSES[$sale->status] }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($sale->paid_at)
                @can('permission', [$branch, BranchPermission::IssueServiceDocuments])
                    <a class="button button-secondary" href="{{ route('documents.source', [$branch, 'pos', $sale->id]) }}">เอกสารขายและเคลม</a>
                @endcan
            @endif
            @if ($editable)<a class="button button-secondary" href="{{ route('pos.edit', [$branch, $sale]) }}">แก้ไขตะกร้า</a>@endif
            @if ($paid)<a class="button button-secondary" href="{{ route('pos.receipt', [$branch, $sale]) }}">ดูใบเสร็จ</a>@endif
            <a class="button button-primary" href="{{ route('pos.create', $branch) }}" data-pos-new>เปิดบิลใหม่ <kbd class="ml-1 text-xs">Alt+N</kbd></a>
        </div>
    </div>
    @include('organizations.errors')
    <div class="grid items-start gap-5 xl:grid-cols-[minmax(0,2fr)_minmax(290px,1fr)]">
        <section class="panel min-w-0 overflow-hidden">
            <div class="space-y-1 border-b border-slate-200 p-4 text-sm sm:p-5">
                <p><strong>ลูกค้า</strong> {{ $sale->buyer['name'] }}</p>
                <p><strong>พนักงานรับเครดิต</strong> {{ $sale->salesperson->name }}</p>
                <p><strong>กลุ่มราคา</strong> {{ PosCart::GROUPS[$sale->price_group] }}</p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-50">
                        <tr><th class="px-4 py-2">รายการ</th><th class="px-3 py-2">จำนวน</th><th class="px-3 py-2 text-right">ราคา/หน่วย</th><th class="px-3 py-2 text-right">ส่วนลด</th><th class="px-4 py-2 text-right">รวม (บาท)</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        @foreach ($sale->lines as $line)
                            <tr class="align-top">
                                <td class="px-4 py-3">
                                    <p class="font-semibold">{{ $line->name }}</p>
                                    <p class="text-xs text-slate-600">{{ $line->sku }} · {{ \App\Models\CatalogProduct::KINDS[$line->kind] }}</p>
                                    @if ($line->imei || $line->serial_number)<p class="mt-1 text-xs text-slate-600">{{ $line->imei ? 'IMEI '.$line->imei : '' }} {{ $line->serial_number ? 'Serial '.$line->serial_number : '' }}</p>@endif
                                </td>
                                <td class="px-3 py-3">{{ $line->quantity }} {{ $line->unit_name }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-right tabular-nums">{{ $money($line->unit_satang) }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-right tabular-nums">{{ $money($line->discount_satang) }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">{{ $money($line->quantity * $line->unit_satang - $line->discount_satang) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
        <div class="space-y-4">
            <section class="panel p-4 sm:p-5">
                <h2 class="font-semibold">ยอดชำระ</h2>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between"><dt>รวมก่อนส่วนลด</dt><dd>{{ $money($sale->subtotal_satang) }}</dd></div>
                    <div class="flex justify-between"><dt>ส่วนลดรายบรรทัด</dt><dd>{{ $money($sale->line_discount_satang) }}</dd></div>
                    <div class="flex justify-between"><dt>ส่วนลดท้ายบิล</dt><dd>{{ $money($sale->document_discount_satang) }}</dd></div>
                    <div class="mt-2 flex items-center justify-between gap-2 border-t border-slate-200 pt-2"><dt class="font-semibold">ยอดสุทธิ</dt><dd class="text-lg font-bold tabular-nums">{{ $money($sale->total_satang) }} บาท</dd></div>
                    @if ($received)
                        <div class="flex justify-between"><dt>รับเงินสด</dt><dd>{{ $money($received->tendered_satang) }}</dd></div>
                        <div class="flex justify-between"><dt>เงินทอน</dt><dd>{{ $money($received->tendered_satang - $received->amount_satang) }}</dd></div>
                    @endif
                </dl>

                @if ($draft && ! $canCheckout)
                    <p class="mt-3 text-sm text-amber-900">{{ $sale->approval?->statusLabel() ?? 'ส่วนลดเกินเพดานที่ร้านกำหนด' }}</p>
                    @if ($sale->approval?->decision_reason)<p class="mt-1 text-sm">{{ $sale->approval->decision_reason }}</p>@endif
                    @if ($canManage)<a class="endu-text-action mt-2 inline-block" href="{{ route('organization-approvals.index', $branch) }}">เปิดคิวอนุมัติ</a>@endif
                    @if ($sale->approval?->status !== 'pending' || $sale->approval->review_role->value !== \App\Support\PosDiscount::rule($branch)['review_role'])
                        <form method="POST" action="{{ route('pos.approval', [$branch, $sale]) }}" class="mt-3 space-y-3">
                            @csrf
                            <input type="hidden" name="version" value="{{ $sale->version }}">
                            <label class="block"><span class="field-label">เหตุผลขอส่วนลด</span><textarea class="field-input" name="reason" maxlength="400" rows="2" required></textarea></label>
                            <button class="button button-secondary">ขออนุมัติ</button>
                        </form>
                    @endif
                @elseif ($draft && $canReceive && $inCounter)
                    <form method="POST" action="{{ route('pos.checkout', [$branch, $sale]) }}" class="mt-4 space-y-3" data-pos-payment @if (request('focus') === 'payment') data-pos-focus-payment @endif data-net="{{ $sale->total_satang }}">
                        @csrf
                        <input type="hidden" name="version" value="{{ $sale->version }}">
                        <label class="block"><span class="field-label">รับเงินสด (บาท) <kbd class="ml-1">F8</kbd></span>
                            <input name="tendered" class="field-input" inputmode="decimal" maxlength="10" required value="{{ old('tendered', RepairQuotation::formatBaht($sale->total_satang, '')) }}">
                        </label>
                        <p class="text-sm">เงินทอน <output class="tabular-nums" data-pos-change>0.00</output> บาท</p>
                        <button class="button button-primary w-full">ยืนยันรับเงินสด</button>
                    </form>
                @elseif ($draft && $canReceive)
                    <a class="endu-text-action mt-3 inline-block" href="{{ route('cash-shifts.index', $branch) }}">เปิดกะที่เคาน์เตอร์นี้เพื่อรับเงิน</a>
                @elseif ($draft)
                    <p class="mt-3 text-sm text-slate-700">ส่งเลขที่บิลให้แคชเชียร์ที่เคาน์เตอร์นี้รับเงิน</p>
                @endif
            </section>

            @if ($draft || ($paid && $canManage))
                <details class="panel p-4 sm:p-5">
                    <summary class="cursor-pointer font-semibold">ยกเลิกบิล</summary>
                    @if ($paid)<p class="mt-2 text-sm text-slate-700">คืนเงินสดเต็มยอดและนำสินค้าเข้าคลังเดิมเมื่อยืนยัน</p>@endif
                    <form method="POST" action="{{ route('pos.void', [$branch, $sale]) }}" class="mt-3 space-y-3">
                        @csrf
                        <input type="hidden" name="version" value="{{ $sale->version }}">
                        <label class="block"><span class="field-label">เหตุผลยกเลิก</span><textarea class="field-input" name="reason" maxlength="400" rows="2" required></textarea></label>
                        <button class="button button-secondary">ยืนยันยกเลิกบิล</button>
                    </form>
                </details>
            @elseif ($sale->status === 'voided')
                <section class="panel p-4 sm:p-5">
                    <h2 class="font-semibold">เหตุผลยกเลิก</h2>
                    <p class="mt-2 whitespace-pre-wrap break-words text-sm">{{ $sale->void_reason }}</p>
                    @if ($returned = $sale->tenders->firstWhere('direction', 'returned'))<p class="mt-2 text-sm">คืนเงินสด {{ $money($returned->amount_satang) }} บาท · กะ #{{ $returned->cash_shift_id }}</p>@endif
                </section>
            @endif

            @if ($canManage && ($sale->creditChanges->isNotEmpty() || ($paid && $staff->isNotEmpty())))
                <details class="panel p-4 sm:p-5">
                    <summary class="cursor-pointer font-semibold">เครดิตการขาย</summary>
                    @if ($paid && $staff->isNotEmpty())
                        <form method="POST" action="{{ route('pos.credit', [$branch, $sale]) }}" class="mt-3 space-y-3">
                            @csrf
                            <input type="hidden" name="version" value="{{ $sale->version }}">
                            <label class="block"><span class="field-label">พนักงาน PC</span>
                                <select name="salesperson_id" class="field-input" required>
                                    <option value="">เลือกผู้รับเครดิต</option>
                                    @foreach ($staff as $person)<option value="{{ $person->id }}" @selected($sale->salesperson_id === $person->id)>{{ $person->name }}</option>@endforeach
                                </select>
                            </label>
                            <label class="block"><span class="field-label">เหตุผลเปลี่ยนผู้รับเครดิต</span><textarea class="field-input" name="reason" maxlength="400" rows="2" required></textarea></label>
                            <button class="button button-secondary">บันทึกเครดิตการขาย</button>
                        </form>
                    @endif
                    <ul class="mt-3 divide-y divide-slate-200">
                        @foreach ($sale->creditChanges->sortByDesc('id') as $entry)
                            <li class="py-2 text-sm">{{ $entry->previous->name }} → {{ $entry->next->name }}<br>
                                {{ $entry->actor->name }} · {{ $entry->created_at->timezone('Asia/Bangkok')->format('d/m/Y H:i') }}<br>{{ $entry->reason }}</li>
                        @endforeach
                    </ul>
                </details>
            @endif
        </div>
    </div>
</x-layout>
