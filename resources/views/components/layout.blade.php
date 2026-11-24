@props(['title', 'activeBranch' => null, 'activeModule' => null])

@php
    $activeBranch ??= request()->route('branch');
    if ($activeBranch === null && auth()->check() && session()->has('active_branch_id')) {
        $activeBranch = auth()->user()->activeBranchMemberships()->where('branch_id', session('active_branch_id'))
            ->with('branch.organization')->first()?->branch;
    }
    $activeRole = $activeBranch && auth()->check() ? $activeBranch->roleFor(auth()->user()) : null;
    $canReadRepairs = $activeRole?->allows(\App\Enums\BranchPermission::ReadRepairs)
        || $activeRole?->allows(\App\Enums\BranchPermission::ReadAssignedRepairs);
    $canOpenAudit = auth()->check() && auth()->user()->can('viewAny', \App\Models\AuditEvent::class);
    $dashboardUrl = route('dashboard', ['branch' => $activeBranch?->id]);
    $activeModule ??= match (true) {
        request()->routeIs('dashboard') => 'OPS',
        request()->routeIs('repairs.*') => 'REP',
        request()->routeIs('staff.*') => 'IAM',
        request()->routeIs('audit.*') => 'SEC',
        request()->routeIs('organizations.*', 'branch-settings.*') => 'ORG',
        request()->routeIs('organization-approvals.*') => 'APV',
        default => null,
    };
    $currentSection = match ($activeModule) {
        'OPS' => 'ภาพรวม',
        'REP' => 'งานซ่อม',
        default => $title,
    };
@endphp
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#101e33">
    <title>{{ $title }} | ENDU CARE</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="endu-app min-h-screen antialiased">
    <a href="#main" class="endu-skip print:hidden">ข้ามไปเนื้อหาหลัก</a>
    @auth
        <aside class="endu-sidebar endu-sidebar--desktop print:hidden" aria-label="เมนูระบบหลัก">
            <x-workspace-sidebar :active-branch="$activeBranch" :active-module="$activeModule" :active-role="$activeRole" :can-open-audit="$canOpenAudit" :dashboard-url="$dashboardUrl" />
        </aside>
        <dialog class="endu-menu-drawer print:hidden" id="workspace-menu" data-workspace-drawer aria-label="เมนูระบบบนมือถือ">
            <button class="endu-icon-button endu-drawer-close" type="button" data-workspace-close aria-label="ปิดเมนู"><x-workspace-icon name="close" /></button>
            <div class="endu-sidebar">
                <x-workspace-sidebar :active-branch="$activeBranch" :active-module="$activeModule" :active-role="$activeRole" :can-open-audit="$canOpenAudit" :dashboard-url="$dashboardUrl" />
            </div>
        </dialog>
    @endauth

    <div @class(['endu-shell', 'endu-shell--staff' => auth()->check()])>
        @auth
            <header class="endu-topbar print:hidden">
                <div class="endu-crumb">
                    <button class="endu-icon-button endu-mobile-toggle" type="button" data-workspace-open aria-label="เปิดเมนู" aria-expanded="false" aria-controls="workspace-menu"><x-workspace-icon name="menu" /></button>
                    <a class="endu-crumb__root" href="{{ $dashboardUrl }}">พื้นที่ทำงาน</a>
                    <span class="endu-crumb__arrow"><x-workspace-icon name="chev" /></span>
                    <strong>{{ $currentSection }}</strong>
                </div>
                <div class="endu-top-tools">
                    @if ($activeBranch && $canReadRepairs)
                        <form method="GET" action="{{ route('repairs.index', $activeBranch) }}" class="endu-header-search" role="search" aria-label="ค้นหาใบงานในสาขา">
                            <label class="sr-only" for="header-repair-search">ค้นหาเลขใบงาน</label>
                            <button type="submit" aria-label="ค้นหาใบงาน"><x-workspace-icon name="search" /></button>
                            <input id="header-repair-search" type="search" name="q" value="{{ $referenceFilter ?? '' }}" maxlength="32" placeholder="ค้นหาเลขใบงาน">
                            <kbd>Ctrl K</kbd>
                        </form>
                        <a class="endu-icon-button endu-ready-shortcut" href="{{ route('repairs.index', [$activeBranch, 'status' => 'ready']) }}" aria-label="งานพร้อมส่งมอบ"><x-workspace-icon name="bell" /></a>
                    @endif
                    <details class="endu-account">
                        <summary aria-label="เมนูบัญชี">
                            <span class="endu-avatar" aria-hidden="true">{{ \Illuminate\Support\Str::substr(auth()->user()->name, 0, 2) }}</span>
                            <span class="endu-account__copy">
                                <strong>{{ auth()->user()->name }}</strong>
                                <small>{{ $activeRole?->label() ?? 'พนักงาน' }}</small>
                            </span>
                        </summary>
                        <div class="panel endu-account__menu">
                            <a class="button button-secondary" href="{{ $dashboardUrl }}#branches">เลือกสาขา</a>
                            <form method="POST" action="{{ route('staff-sessions.revoke-others') }}">
                                @csrf
                                <button type="submit" class="button button-secondary w-full">ยุติเซสชันอื่น</button>
                            </form>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="button button-secondary w-full">ออกจากระบบ</button>
                            </form>
                        </div>
                    </details>
                </div>
            </header>
        @endauth

        <main id="main" tabindex="-1" class="endu-main">
            @if (session('status'))
                <p role="status" class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-900 print:hidden">
                    {{ session('status') }}
                </p>
            @endif
            {{ $slot }}
        </main>
    </div>
</body>
</html>
