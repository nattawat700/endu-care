@php
    use App\Models\IssuedDocument;
    use App\Models\RepairQuotation;
    use App\Support\CustomerTaxProfile;
    use Illuminate\Support\Carbon;

    $data = $document->snapshot;
    $profile = $sellerProfile;
    $money = fn (int $value) => RepairQuotation::formatBaht($value).' บาท';
    $dateTime = fn (string $date) => Carbon::parse($date)->timezone('Asia/Bangkok')->format('d/m/Y H:i');
    $tax = in_array($document->kind, ['tax_full', 'tax_short'], true);
    $short = $document->kind === 'tax_short';
    $closure = isset($data['closed_at']);
@endphp

<div class="quotation-wrap">
    <article class="panel repair-quotation">
        <header class="quotation-doc-head">
            <div class="quotation-seller">@include('organizations.seller-header')</div>
            <div class="quotation-identity">
                <h2 class="quotation-title">{{ IssuedDocument::KINDS[$document->kind][0] }}</h2>
                @if ($document->kind === 'tax_full')<p class="text-sm font-semibold">เต็มรูป</p>@endif
                @if ($copy)<p class="text-sm font-semibold">สำเนา / COPY</p>@endif
                <dl class="quotation-identity-data">
                    <div><dt>เลขที่เอกสาร</dt><dd class="font-bold">{{ $document->number }}</dd></div>
                    <div><dt>วันที่ออก</dt><dd>{{ $document->created_at->timezone('Asia/Bangkok')->format('d/m/Y') }}</dd></div>
                    <div><dt>เลขอ้างอิง</dt><dd>{{ $document->source_reference }}</dd></div>
                </dl>
            </div>
        </header>
        @if ($tax)
            @if ($data['buyer'])
                <div class="quotation-parties">
                    <section>
                        <h3>ผู้ซื้อ / ผู้รับบริการ</h3>
                        <p class="font-semibold">{{ $data['buyer']['name'] }}</p>
                        <p class="whitespace-pre-wrap wrap-break-word">{{ $data['buyer']['address'] }}</p>
                        @if (! empty($data['buyer']['tax_id']))<p>เลขประจำตัวผู้เสียภาษี {{ $data['buyer']['tax_id'] }}</p>@endif
                        @if ($office = CustomerTaxProfile::office($data['buyer']))<p>{{ $office }}</p>@endif
                    </section>
                </div>
            @endif
            <table class="quotation-table">
                <thead><tr><th scope="col">รายการ</th><th scope="col" class="text-right">จำนวน</th><th scope="col" class="text-right">ราคา/หน่วย</th>
                    <th scope="col" class="text-right">ส่วนลด</th><th scope="col" class="text-right">มูลค่า</th></tr></thead>
                <tbody>
                    @foreach ($data['items'] as $item)
                        <tr>
                            <td>{{ $item['description'] }}</td>
                            <td class="text-right">{{ $item['quantity'] }} {{ $item['unit'] ?? '' }}</td>
                            <td class="text-right tabular-nums">{{ $money($item['unit_satang']) }}</td>
                            <td class="text-right tabular-nums">{{ $money($item['discount_satang'] ?? 0) }}</td>
                            <td class="text-right tabular-nums">{{ $money($item['total_satang']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="quotation-closing">
                <div class="quotation-settlement">
                    <div class="quotation-conditions">
                        @if ($short)<p>ยอดรวมนี้รวมภาษีมูลค่าเพิ่มแล้ว</p>
                        @else <p>{{ $data['mode'] === 'exclusive' ? 'ราคาต่อหน่วยก่อนภาษี' : 'ราคาต่อหน่วยรวมภาษี' }}</p>
                        @endif
                    </div>
                    <dl class="quotation-totals">
                        @foreach (['document_discount_satang' => 'ส่วนลดท้ายบิล', 'shipping_satang' => 'ค่าขนส่ง', 'other_charge_satang' => 'ค่าใช้จ่ายอื่น'] as $key => $label)
                            @if ($data['adjustments'][$key] ?? 0)<div><dt>{{ $label }}</dt><dd>{{ $money($data['adjustments'][$key]) }}</dd></div>@endif
                        @endforeach
                        @unless ($short)
                            <div><dt>มูลค่าก่อน VAT</dt><dd>{{ $money($data['base']) }}</dd></div>
                            <div><dt>VAT {{ RepairQuotation::formatBaht($data['rate'], '') }}%</dt><dd>{{ $money($data['vat']) }}</dd></div>
                        @endunless
                        <div class="quotation-total-line"><dt>รวมทั้งสิ้น</dt><dd>{{ $money($data['gross']) }}</dd></div>
                        @if ($data['adjustments']['withholding_satang'] ?? 0)
                            <div><dt>หัก ณ ที่จ่าย</dt><dd>{{ $money($data['adjustments']['withholding_satang']) }}</dd></div>
                            <div><dt>ยอดรับ</dt><dd>{{ $money($data['paid']) }}</dd></div>
                        @endif
                    </dl>
                </div>
            </div>
        @elseif ($closure)
            <dl class="quotation-parties">
                <section><h3>ส่งมอบ / ส่งคืนเครื่อง</h3>
                    <p>ผู้รับ {{ $data['recipient'] }}</p>
                    <p>เครื่อง {{ $data['device'] }}</p>
                    <p>{{ $dateTime($data['closed_at']) }} · ผู้บันทึก {{ $data['closed_by'] }}</p>
                    @if ($data['return_reason'])<p>{{ $data['return_reason'] }}</p>@endif
                    @if ($data['note'])<p class="whitespace-pre-wrap wrap-break-word">{{ $data['note'] }}</p>@endif
                </section>
                <section><h3>ยอด ณ วันปิดงาน</h3>
                    <p>รับสุทธิ {{ $money($data['collected']) }} · คืนแล้ว {{ $money($data['refunded']) }}</p>
                    @if ($data['retention_reason'])<p class="whitespace-pre-wrap wrap-break-word">เหตุผลที่อนุมัติคงยอด {{ $data['retention_reason'] }}</p>@endif
                    @if ($data['received_references'])<p class="wrap-break-word">เอกสารรับเงิน {{ implode(', ', $data['received_references']) }}</p>@endif
                </section>
            </dl>
            @if ($document->kind !== 'delivery' && $data['warranty_days'])
                <div class="quotation-conditions">
                    <section><h3>ประกันงานซ่อม</h3>
                        <p>{{ $data['warranty_days'] }} วัน · {{ Carbon::parse($data['warranty_start'])->format('d/m/Y') }} ถึง {{ Carbon::parse($data['warranty_end'])->format('d/m/Y') }}</p>
                        @if ($data['warranty_terms'])<p class="whitespace-pre-wrap wrap-break-word">{{ $data['warranty_terms'] }}</p>@endif
                    </section>
                </div>
            @endif
            @isset($data['receipt_reference'])
                <p class="mt-4 text-sm">เอกสารแนบ {{ $data['receipt_reference'] }}</p>
            @endisset
        @elseif (in_array($document->kind, ['claim', 'claim_result'], true))
            <div class="quotation-parties">
                <section><h3>รายละเอียดรับเคลม</h3>
                    <p>ต้นทาง {{ $data['reference'] }}</p><p>ผู้แจ้ง {{ $data['claimant'] }}</p><p>{{ $data['item'] }}</p>
                    <p class="whitespace-pre-wrap wrap-break-word">{{ $data['issue'] }}</p><p>{{ $dateTime($data['received_at']) }}</p>
                </section>
                <section><h3>ผลพิจารณา</h3>
                    <p class="font-semibold">{{ $data['decision'] }}</p>
                    @if ($data['review'])
                        <p class="whitespace-pre-wrap wrap-break-word">{{ $data['review']['reason'] }}</p>
                        <p>{{ $data['review']['reviewer'] }} · {{ $dateTime($data['reviewed_at']) }}</p>
                    @endif
                </section>
            </div>
        @else
            <div class="quotation-parties">
                <section><h3>รายการต้นทาง</h3>
                    <p>{{ $data['reference'] }} @isset($data['repair']) · {{ $data['repair'] }} @endisset</p>
                    <p class="whitespace-pre-wrap wrap-break-word">{{ $data['reason'] }}</p>
                    @if (! empty($data['movements']))<p class="wrap-break-word">คืนสต๊อกอ้างอิง {{ implode(', ', $data['movements']) }}</p>@endif
                </section>
                <section><h3>ผลดำเนินการ</h3>
                    <p>คืนเงิน {{ $money($data['amount']) }} · {{ $data['channel'] }}</p><p>{{ $dateTime($data['settled_at']) }}</p>
                    @if ($data['reviewed_by'])<p>ผู้อนุมัติ {{ $data['reviewed_by'] }}</p>@endif
                    @isset($data['review'])<p class="whitespace-pre-wrap wrap-break-word">{{ $data['review'] }}</p>@endisset
                </section>
            </div>
        @endif
    </article>
</div>
