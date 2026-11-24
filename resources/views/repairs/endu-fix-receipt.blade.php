@php
    $coordinates = ['1' => '16,5', '2' => '50,5', '3' => '84,5',
        '4' => '16,11.7', '5' => '50,11.7', '6' => '84,11.7',
        '7' => '16,18.4', '8' => '50,18.4', '9' => '84,18.4'];
    $pattern = $showUnlock ? ($repair->device_unlock_pattern ?? []) : [];
@endphp

<div class="overflow-x-auto print:overflow-visible">
    <article class="endu-fix-receipt" aria-label="ใบส่งซ่อม {{ $profile['name'] }}">
        <header class="endu-fix-receipt__header">
            <div>
                @if (isset($profile['logo']))
                    <img class="endu-fix-receipt__logo endu-fix-receipt__logo--custom" src="{{ route('branch-settings.document.logo', [$branch, $profile['logo']]) }}" alt="โลโก้ {{ $profile['name'] }}">
                @elseif (isset($profile['default_logo']))
                    <img class="endu-fix-receipt__logo" src="{{ asset($profile['default_logo']) }}" alt="โลโก้ {{ $profile['name'] }}">
                @endif
            </div>
            <div class="endu-fix-receipt__heading">
                <h1>ใบส่งซ่อม</h1>
                <p>REPAIR SERVICE FORM</p>
            </div>
            <dl class="endu-fix-receipt__meta">
                <div><dt>วันที่ :</dt><dd>{{ $repair->created_at->timezone('Asia/Bangkok')->format('d/m/Y') }}</dd></div>
                <div><dt>ออกโดย :</dt><dd title="{{ $repair->receivedBy->name }}">{{ $repair->receivedBy->name }}</dd></div>
            </dl>
        </header>

        <div class="endu-fix-receipt__address endu-fix-receipt__address--organization">
            <div><strong>{{ $profile['name'] }}</strong>
                @if (isset($profile['office']) && $profile['office'] !== $profile['name']) · {{ $profile['office'] }} @endif
                @isset($profile['tax_id']) · เลขผู้เสียภาษี {{ $profile['tax_id'] }} @endisset
                @if (isset($profile['registration_id']) && ($profile['tax_id'] ?? null) !== $profile['registration_id'])
                    · เลขทะเบียน / เลขประจำตัว {{ $profile['registration_id'] }}
                @endif
            </div>
            <div>{{ implode(' · ', array_filter([$profile['address'] ?? null, isset($profile['phone']) ? 'โทรศัพท์ '.$profile['phone'] : null, $profile['email'] ?? null])) }}</div>
        </div>

        <div class="endu-fix-receipt__tables">
            <section aria-labelledby="receipt-customer-data">
                <h2 id="receipt-customer-data">ข้อมูลลูกค้า / ข้อมูลสินค้า</h2>
                <dl class="endu-fix-receipt__rows endu-fix-receipt__rows--customer">
                    @foreach ([
                        'ชื่อลูกค้า' => $repair->customer_name, 'เบอร์โทร' => $repair->customer_phone,
                        'ที่อยู่' => $repair->customer_address, 'สินค้า' => $repair->product,
                        'รุ่น' => $repair->device, 'สี' => $repair->color, 'IMEI' => $repair->imei,
                        'Serial No.' => $repair->serial_number,
                    ] as $label => $value)
                        <div class="endu-fix-receipt__row">
                            <dt>{{ $label }}</dt><dd><span class="endu-fix-receipt__value" title="{{ $value }}">{{ $value }}</span></dd>
                        </div>
                    @endforeach
                    <div class="endu-fix-receipt__terms">
                        <dt>เงื่อนไข</dt>
                        <dd class="endu-fix-receipt__value" title="{{ $repair->intake_terms }}">{{ $repair->intake_terms }}</dd>
                    </div>
                </dl>
            </section>

            <section aria-labelledby="receipt-service-data">
                <h2 id="receipt-service-data">รายละเอียดการส่งซ่อม</h2>
                <dl class="endu-fix-receipt__rows endu-fix-receipt__rows--service">
                    @foreach ([
                        'เลขที่กรมธรรม์' => $repair->policy_number, 'สภาพเครื่อง' => $repair->device_condition,
                        'อุปกรณ์ที่นำมาด้วย' => $repair->accessories, 'อาการเสีย' => $repair->issue,
                    ] as $label => $value)
                        <div class="endu-fix-receipt__row">
                            <dt>{{ $label }}</dt><dd><span class="endu-fix-receipt__value" title="{{ $value }}">{{ $value }}</span></dd>
                        </div>
                    @endforeach
                    <div class="endu-fix-receipt__warranty">
                        <dt>อยู่ในเงื่อนไข :</dt>
                        @foreach (['covered' => 'ในประกัน', 'not_covered' => 'นอกประกัน'] as $status => $label)
                            @php($checked = $repair->warranty_status === $status)
                            <dd data-warranty-value="{{ $status }}">
                                <span class="endu-fix-receipt__checkbox" aria-hidden="true">
                                    @if ($checked)
                                        <svg viewBox="0 0 10 10" fill="none" focusable="false" data-warranty-check>
                                            <path d="M2 5.5 4 7.5 8 2.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                        </svg>
                                    @endif
                                </span>
                                <span class="sr-only">{{ $checked ? 'เลือก' : 'ไม่ได้เลือก' }}</span>
                                {{ $label }}
                            </dd>
                        @endforeach
                    </div>
                    <div class="endu-fix-receipt__row endu-fix-receipt__passcode">
                        <dt>รหัสเครื่อง</dt><dd><span class="endu-fix-receipt__value">{{ $showUnlock ? $repair->device_passcode : '' }}</span></dd>
                    </div>
                    <div class="endu-fix-receipt__pattern">
                        <dt>รูปแบบรหัสเครื่อง</dt>
                        <dd>
                            <svg viewBox="0 0 100 22" role="img" aria-label="ตารางรูปแบบรหัสเครื่อง">
                                @if ($pattern !== [])
                                    <polyline points="{{ implode(' ', array_map(fn ($point) => $coordinates[$point], $pattern)) }}" fill="none" stroke="#222" stroke-width="0.47" stroke-linecap="round" stroke-linejoin="round" />
                                @endif
                                @foreach ($coordinates as $point => $coordinate)
                                    @php([$x, $y] = explode(',', $coordinate))
                                    <circle cx="{{ $x }}" cy="{{ $y }}" r="0.85" fill="#151515" />
                                    @if ($pattern !== [] && ($step = array_search((string) $point, $pattern, true)) !== false)
                                        <text x="{{ $x + 1.5 }}" y="{{ $y - 1.15 }}" font-size="2.6">{{ $step + 1 }}</text>
                                    @endif
                                @endforeach
                            </svg>
                        </dd>
                    </div>
                </dl>
            </section>
        </div>

        <footer class="endu-fix-receipt__signatures">
            @foreach (['ผู้รับเครื่อง', 'ผู้ตรวจ', 'ผู้อนุมัติ'] as $label)
                <p><span>{{ $label }}</span><span class="endu-fix-receipt__signature-line"></span><span>ลงชื่อ</span></p>
            @endforeach
        </footer>
    </article>
</div>
