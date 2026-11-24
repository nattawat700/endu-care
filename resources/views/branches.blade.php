<x-layout title="ภาพรวมสาขา" :active-branch="$activeBranch">
    <div class="endu-page-head">
        <div>
            <h1>ภาพรวมสาขา</h1>
            <p>{{ $activeBranch?->name ?? 'ENDU CARE' }} · ติดตามงานซ่อมและรายการที่ต้องจัดการของสาขา</p>
        </div>
        @if ($activeBranch && $canReadRepairs)
            <div class="endu-actions">
                <a class="button button-secondary" href="{{ route('repairs.index', $activeBranch) }}"><x-workspace-icon name="repair" /> ดูคิวงานซ่อม</a>
                @if ($canIntake)
                    <a class="button button-primary" href="{{ route('repairs.create', $activeBranch) }}"><x-workspace-icon name="plus" /> เปิดใบรับซ่อม</a>
                @endif
            </div>
        @endif
    </div>

    @if ($activeBranch && $canReadRepairs)
        @php
            $approval = \App\Enums\RepairStatus::AwaitingApproval;
            $repairing = \App\Enums\RepairStatus::Repairing;
            $ready = \App\Enums\RepairStatus::Ready;
            $received = \App\Enums\RepairStatus::Received;
        @endphp
        <div class="endu-section-heading">
            <h2>ผลการดำเนินงาน</h2>
            <time datetime="{{ $today->toDateString() }}"><x-workspace-icon name="calendar" /> {{ $today->locale('th')->translatedFormat('j M') }} {{ $today->year + 543 }}</time>
        </div>
        <section class="endu-metric-grid" aria-label="ตัวชี้วัดงานซ่อม">
            <a class="panel endu-metric" href="{{ route('repairs.index', $activeBranch) }}">
                <div class="endu-metric__top">รับเครื่องวันนี้ <span class="endu-tone-icon"><x-workspace-icon name="phone" /></span></div>
                <strong class="endu-metric__value">{{ number_format($series->last()['total']) }} <small>งาน</small></strong>
                <p>ใบงานที่เปิดวันนี้</p>
            </a>
            <a class="panel endu-metric" href="{{ route('repairs.index', [$activeBranch, 'status' => $ready->value]) }}">
                <div class="endu-metric__top">{{ $ready->label() }} <span class="endu-tone-icon endu-tone-icon--green"><x-workspace-icon name="check" /></span></div>
                <strong class="endu-metric__value">{{ number_format($counts->get($ready->value, 0)) }} <small>งาน</small></strong>
                <p>รอส่งเครื่องให้ลูกค้า</p>
            </a>
            <a class="panel endu-metric" href="{{ route('repairs.index', $activeBranch) }}">
                <div class="endu-metric__top">งานซ่อมที่เปิดอยู่ <span class="endu-tone-icon endu-tone-icon--purple"><x-workspace-icon name="repair" /></span></div>
                <div class="endu-metric__value"><strong data-open-repair-count>{{ number_format($counts->sum()) }}</strong> <small>งาน</small></div>
                <p>รับเครื่องแล้ว {{ number_format($counts->get($received->value, 0)) }} งาน</p>
            </a>
            <a class="panel endu-metric" href="{{ route('repairs.index', [$activeBranch, 'status' => $approval->value]) }}">
                <div class="endu-metric__top">{{ $approval->label() }} <span class="endu-tone-icon endu-tone-icon--gold"><x-workspace-icon name="clock" /></span></div>
                <strong class="endu-metric__value">{{ number_format($counts->get($approval->value, 0)) }} <small>งาน</small></strong>
                <p>รอยืนยันให้เริ่มซ่อม</p>
            </a>
        </section>

        <div class="endu-dashboard-grid">
            @include('repairs.intake-trend')
            <section class="panel">
                <div class="endu-panel-head">
                    <h2>คิวที่ต้องจัดการ</h2>
                    <p>เปิดใบงานตามสถานะ</p>
                </div>
                <div class="endu-action-queue">
                    @foreach ([
                        [$approval, 'ติดตามลูกค้าที่ยังไม่ยืนยัน', 'clock', 'gold'],
                        [$repairing, 'ใบงานที่อยู่กับช่าง', 'repair', 'purple'],
                        [$ready, 'เตรียมส่งมอบให้ลูกค้า', 'check', 'green'],
                        [$received, 'มอบหมายช่างหรือเริ่มตรวจเครื่อง', 'box', 'blue'],
                    ] as [$status, $description, $icon, $tone])
                        <a href="{{ route('repairs.index', [$activeBranch, 'status' => $status->value]) }}">
                            <span class="endu-tone-icon endu-tone-icon--{{ $tone }}"><x-workspace-icon :name="$icon" /></span>
                            <span><strong>{{ $status->label() }}</strong><small>{{ $description }}</small></span>
                            <b>{{ number_format($counts->get($status->value, 0)) }}</b>
                            <x-workspace-icon name="chev" />
                        </a>
                    @endforeach
                </div>
            </section>
        </div>

        <section class="panel endu-latest">
            <div class="endu-panel-head endu-panel-head--split">
                <div>
                    <h2>งานซ่อมล่าสุด</h2>
                    <p>ใบงานและสถานะล่าสุดของสาขา</p>
                </div>
                <a class="endu-text-action" href="{{ route('repairs.index', $activeBranch) }}"><x-workspace-icon name="arrow" /> ดูใบงานทั้งหมด</a>
            </div>
            <div class="endu-table-scroll">
                <table>
                    <thead><tr>
                        <th scope="col">เลขใบงาน</th>
                        <th scope="col">เครื่อง / อาการ</th>
                        @if ($canReadCustomers)<th scope="col">ลูกค้า</th>@endif
                        <th scope="col">ช่างผู้รับผิดชอบ</th>
                        <th scope="col">วันที่รับ</th>
                        <th scope="col">สถานะ</th>
                    </tr></thead>
                    <tbody>
                        @forelse ($recentRepairs as $repair)
                            <tr>
                                <td><a class="endu-text-action" href="{{ route('repairs.show', [$activeBranch, $repair]) }}">{{ $repair->reference() }}</a></td>
                                <td><strong>{{ $repair->device }}</strong><small class="endu-repair-issue">{{ \Illuminate\Support\Str::limit($repair->issue, 60) }}</small></td>
                                @if ($canReadCustomers)<td>{{ $repair->customer_name }}</td>@endif
                                <td>{{ $repair->technician?->name ?? '—' }}</td>
                                <td>{{ $repair->created_at->timezone('Asia/Bangkok')->format('d/m/Y') }}</td>
                                <td><x-repair-status :status="$repair->status" /></td>
                            </tr>
                        @empty
                            <tr><td class="endu-table-empty" colspan="{{ $canReadCustomers ? 6 : 5 }}">ยังไม่มีใบงานในสาขานี้</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <section id="branches" class="endu-branches">
        <div class="endu-section-heading"><h2>เลือกสาขา</h2></div>
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @forelse ($memberships as $membership)
                <form method="POST" action="{{ route('workspace.branch', $membership->branch) }}">
                    @csrf
                    <button type="submit" class="panel endu-branch-card w-full text-left" @if ($membership->branch->is($activeBranch)) aria-current="true" @endif>
                        <span>{{ $membership->branch->organization->name }}</span>
                        <strong>{{ $membership->branch->name }}</strong>
                        <span class="badge badge-neutral">{{ $membership->role->label() }}</span>
                    </button>
                </form>
            @empty
                <p class="panel p-6 text-slate-600 sm:col-span-2">ยังไม่มีสาขาที่คุณเข้าถึงได้</p>
            @endforelse
        </div>
    </section>
</x-layout>
