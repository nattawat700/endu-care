<?php

namespace App\Http\Controllers;

use App\Enums\BranchRole;
use App\Models\Organization;
use App\Models\User;
use App\Support\StaffSession;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrganizationController extends Controller
{
    public function index(Request $request): View
    {
        $organizations = Organization::whereHas('branches.memberships', fn ($memberships) => $memberships
            ->whereBelongsTo($request->user())->whereIn('role', [BranchRole::Owner, BranchRole::Hq]))
            ->orderBy('name')->paginate(25);

        return view('organizations.index', compact('organizations'));
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Organization::class);
        $fields = $request->validate([
            'code' => ['required', 'string', 'alpha_dash:ascii', 'max:32'],
            'name' => ['required', 'string', 'max:150'],
            'operator_type' => ['required', Rule::in(array_keys(Organization::OPERATOR_TYPES))],
            'owner_email' => ['required', 'email', 'max:254'],
            'branch_code' => ['required', 'string', 'alpha_dash:ascii', 'max:32'],
            'branch_name' => ['required', 'string', 'max:150'],
        ]);
        $actor = $request->user();

        $organization = DB::transaction(function () use ($fields, $actor): Organization {
            $owner = User::where('email', Str::lower($fields['owner_email']))->whereNull('suspended_at')->lockForUpdate()->first();
            if ($owner === null) {
                throw ValidationException::withMessages(['owner_email' => 'กรุณาระบุอีเมลของบัญชีเจ้าของที่เปิดใช้งานแล้ว']);
            }

            $organization = Organization::firstOrCreate(['code' => Str::lower($fields['code'])], [
                'name' => $fields['name'], 'operator_type' => $fields['operator_type'], 'owner_id' => $owner->id,
            ]);
            if (! $organization->wasRecentlyCreated) {
                throw ValidationException::withMessages(['code' => 'รหัสกิจการนี้ถูกใช้งานแล้ว']);
            }

            $branch = $organization->branches()->create(['code' => Str::lower($fields['branch_code']), 'name' => $fields['branch_name']]);
            $branch->memberships()->create(['user_id' => $owner->id, 'role' => BranchRole::Owner]);
            if (! $owner->is($actor)) {
                $branch->memberships()->create(['user_id' => $actor->id, 'role' => BranchRole::Hq]);
                StaffSession::revoke($owner);
            }

            return $organization;
        }, 3);

        return redirect()->route('organizations.show', $organization)->with('status', 'สร้างกิจการและสาขาแรกแล้ว');
    }

    public function show(Request $request, Organization $organization): View
    {
        Gate::authorize('manage', $organization);
        $branches = $organization->branches()
            ->whereHas('memberships', fn ($memberships) => $memberships->whereBelongsTo($request->user())
                ->whereIn('role', [BranchRole::Owner, BranchRole::Hq]))
            ->orderBy('code')->get();
        $organization->load('owner:id,name');
        $history = $organization->statusEvents()->with('actor:id,name')->latest('id')->paginate(10);

        return view('organizations.show', compact('organization', 'branches', 'history'));
    }

    public function update(Request $request, Organization $organization): RedirectResponse
    {
        Gate::authorize('manage', $organization);
        abort_if($organization->suspended_at !== null, 409);
        $fields = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'operator_type' => ['required', Rule::in(array_keys(Organization::OPERATOR_TYPES))],
        ]);
        DB::transaction(fn () => Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail()->update($fields), 3);

        return back()->with('status', 'บันทึกข้อมูลกิจการแล้ว');
    }

    public function setStatus(Request $request, Organization $organization): RedirectResponse
    {
        Gate::authorize('manage', $organization);
        $fields = $request->validate(['suspended' => ['required', 'boolean'], 'reason' => ['required', 'string', 'max:500']]);
        $suspend = $request->boolean('suspended');

        DB::transaction(function () use ($request, $organization, $fields, $suspend): void {
            $current = Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('manage', $current);
            if (($current->suspended_at !== null) === $suspend) {
                return;
            }

            $current->update(['suspended_at' => $suspend ? now() : null]);
            $current->statusEvents()->create([
                'actor_id' => $request->user()->id, 'suspended' => $suspend, 'reason' => $fields['reason'],
            ]);
        }, 3);

        return back()->with('status', $suspend ? 'ระงับกิจการแล้ว' : 'เปิดใช้งานกิจการแล้ว');
    }
}
