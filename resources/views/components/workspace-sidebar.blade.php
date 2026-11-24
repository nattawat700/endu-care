@props(['activeBranch', 'activeModule', 'activeRole', 'canOpenAudit', 'dashboardUrl'])

<a class="endu-workspace-brand" href="{{ $dashboardUrl }}" aria-label="ENDU CARE ภาพรวม">
    <img src="{{ asset('images/endu-care-workspace.png') }}" width="640" height="98" alt="ENDU CARE">
</a>
<a class="endu-workspace-branch" href="{{ $dashboardUrl }}#branches">
    <span class="endu-workspace-branch__icon"><x-workspace-icon name="store" /></span>
    <span>
        <small>สาขาที่ทำงาน</small>
        <strong>{{ $activeBranch?->name ?? 'เลือกสาขา' }}</strong>
    </span>
</a>
<nav class="endu-workspace-nav" aria-label="เมนูระบบ">
    @foreach (config('phase-one-navigation') as $heading => $items)
        <div>
            <p>{{ $heading }}</p>
            @foreach ($items as $module => $item)
                @continue($module === 'REP' && $activeBranch && ! ($activeRole?->allows(\App\Enums\BranchPermission::ReadRepairs) || $activeRole?->allows(\App\Enums\BranchPermission::ReadAssignedRepairs)))
                @continue($module === 'APV' && (! $activeBranch || ! $activeRole
                    || ! collect(\App\Enums\ApprovalKind::cases())->contains(fn ($kind) => $kind->canRequest($activeRole))))
                @php
                    $url = match ($module) {
                        'OPS' => $dashboardUrl,
                        'SEC' => $canOpenAudit ? route('audit.index') : route('phase-one', ['module' => 'SEC', 'branch' => $activeBranch?->id]),
                        'ORG' => route('organizations.index'),
                        'APV' => route('organization-approvals.index', $activeBranch),
                        'IAM' => $activeBranch && $activeRole?->allows(\App\Enums\BranchPermission::ManageStaff)
                            ? route('staff.index', $activeBranch) : route('phase-one', ['module' => $module, 'branch' => $activeBranch?->id]),
                        'REP' => $activeBranch ? route('repairs.index', $activeBranch) : $dashboardUrl.'#branches',
                        default => route('phase-one', ['module' => $module, 'branch' => $activeBranch?->id]),
                    };
                @endphp
                <a href="{{ $url }}" @if ($activeModule === $module) aria-current="page" @endif>
                    <x-workspace-icon :name="$item['icon']" />
                    {{ $item['label'] }}
                </a>
            @endforeach
        </div>
    @endforeach
</nav>
<div class="endu-workspace-footer">
    <a href="{{ route('phase-one', ['branch' => $activeBranch?->id]) }}" @if ($activeModule === 'catalog') aria-current="page" @endif>
        <span aria-hidden="true"></span> ขอบเขตฟังก์ชัน
    </a>
    <strong>เฟส 1</strong>
</div>
