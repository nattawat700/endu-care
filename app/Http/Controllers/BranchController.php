<?php

namespace App\Http\Controllers;

use App\Enums\BranchPermission;
use App\Enums\RepairStatus;
use App\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    public function select(Request $request, Branch $branch): RedirectResponse
    {
        abort_unless($branch->roleFor($request->user()) !== null, 404);
        $request->session()->put('active_branch_id', $branch->id);

        return redirect()->route('dashboard');
    }

    public function index(Request $request): View
    {
        $memberships = $request->user()->activeBranchMemberships()
            ->with('branch.organization')
            ->orderBy('branch_id')
            ->get();
        $selected = $request->query('branch');
        $membership = $selected === null
            ? ($memberships->firstWhere('branch_id', $request->session()->get('active_branch_id')) ?? $memberships->first())
            : $memberships->first(fn ($membership) => (string) $membership->branch_id === $selected);
        abort_if($selected !== null && $membership === null, 404);

        $role = $membership?->role;
        $canReadBranch = $role?->allows(BranchPermission::ReadRepairs) ?? false;
        $canReadAssigned = $role?->allows(BranchPermission::ReadAssignedRepairs) ?? false;
        $canReadCustomers = $role?->allows(BranchPermission::CustomerData) ?? false;
        $repairs = $canReadBranch || $canReadAssigned
            ? $membership->branch->repairs()->when(! $canReadBranch, fn ($query) => $query->where('technician_id', $request->user()->id))
            : null;
        $now = CarbonImmutable::now('Asia/Bangkok');
        $today = $membership?->branch->businessDate($now) ?? $now->startOfDay();
        $days = collect(range(6, 0))->map(fn ($offset) => $today->subDays($offset));

        $series = $days->map(fn ($day) => ['label' => $day->format('d/m'), 'total' => 0]);
        $counts = collect();
        $recentRepairs = collect();
        if ($repairs !== null) {
            $counts = (clone $repairs)
                ->whereNotIn('status', [RepairStatus::Delivered->value, RepairStatus::ReturnedUnrepaired->value])
                ->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->toBase()
                ->pluck('total', 'status');
            $recentRepairs = (clone $repairs)->with('technician:id,name')->latest('id')->limit(5)
                ->get(['id', 'device', 'issue', 'technician_id', 'status', 'created_at', ...($canReadCustomers ? ['customer_name'] : [])]);

            $trend = (clone $repairs)->where(function ($query) use ($days, $today): void {
                $query->where(fn ($recorded) => $recorded->where('business_date', '>=', $days->first()->toDateString())
                    ->where('business_date', '<', $today->addDay()->toDateString()))
                    ->orWhere(fn ($legacy) => $legacy->whereNull('business_date')
                        ->where('created_at', '>=', $days->first()->utc())->where('created_at', '<', $today->addDay()->utc()));
            });
            foreach ($days as $index => $day) {
                $trend->selectRaw("COUNT(CASE WHEN (business_date >= ? AND business_date < ?) OR (business_date IS NULL AND created_at >= ? AND created_at < ?) THEN 1 END) AS day_{$index}", [
                    $day->toDateString(), $day->addDay()->toDateString(), $day->utc(), $day->addDay()->utc(),
                ]);
            }
            $totals = $trend->toBase()->first();
            $series = $series->map(fn ($point, $index) => [...$point, 'total' => (int) $totals->{"day_{$index}"}]);
        }

        return view('branches', [
            'memberships' => $memberships,
            'activeBranch' => $membership?->branch,
            'canIntake' => $role?->allows(BranchPermission::CreateRepair) ?? false,
            'canReadCustomers' => $canReadCustomers,
            'canReadRepairs' => $repairs !== null,
            'counts' => $counts,
            'series' => $series,
            'today' => $today,
            'recentRepairs' => $recentRepairs,
        ]);
    }
}
