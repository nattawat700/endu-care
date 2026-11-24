<?php

namespace App\Http\Controllers;

use App\Enums\ApprovalKind;
use App\Enums\BranchRole;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\OrganizationApprovalRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrganizationApprovalController extends Controller
{
    public function rules(Request $request, Organization $organization): RedirectResponse
    {
        Gate::authorize('manage', $organization);
        $validation = [];
        foreach (ApprovalKind::cases() as $kind) {
            $validation["rules.{$kind->value}.limit"] = $kind->inputRules();
            $validation["rules.{$kind->value}.review_role"] = ['required', Rule::in(ApprovalKind::reviewRoles())];
        }
        $input = $request->validate($validation);
        $rules = [];
        foreach (ApprovalKind::cases() as $kind) {
            $rule = $input['rules'][$kind->value];
            $rules[$kind->value] = ['limit' => $kind->parse($rule['limit']), 'review_role' => $rule['review_role']];
        }
        DB::transaction(function () use ($organization, $rules): void {
            $current = Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('manage', $current);
            abort_if($current->suspended_at !== null, 409);
            $current->update(['approval_rules' => $rules]);
        }, 3);

        return back()->with('status', 'บันทึกกติกาอนุมัติแล้ว');
    }

    public function index(Request $request, Branch $branch): View
    {
        $role = $this->role($request, $branch);
        $kinds = array_filter(ApprovalKind::cases(), fn (ApprovalKind $kind) => $kind->canRequest($role));
        abort_if($kinds === [], 403);
        $approvals = $branch->approvalRequests()->where(fn ($requests) => $requests
            ->where('requested_by', $request->user()->id)->orWhere('review_role', $role))
            ->with(['requester:id,name', 'reviewer:id,name'])
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")->orderByDesc('id')->paginate(20);
        $rules = $branch->organization->approval_rules ?? [];
        $submissionId = (string) Str::uuid();

        return view('organizations.approvals', compact('branch', 'approvals', 'rules', 'kinds', 'role', 'submissionId'));
    }

    public function store(Request $request, Branch $branch): RedirectResponse
    {
        $role = $this->role($request, $branch);
        $fields = $request->validate([
            'kind' => ['required', Rule::enum(ApprovalKind::class)],
            'submission_id' => ['required', 'uuid'],
            'reference' => ['required', 'string', 'max:100'],
            'reason' => ['required', 'string', 'max:1500'],
        ]);
        $kind = ApprovalKind::from($fields['kind']);
        abort_unless($kind->canRequest($role), 403);
        $amount = $kind->parse($request->validate(['amount' => $kind->inputRules(1)])['amount']);
        if ($amount < 1) {
            throw ValidationException::withMessages(['amount' => 'กรุณาระบุจำนวนมากกว่าศูนย์']);
        }

        $approval = DB::transaction(function () use ($request, $branch, $kind, $fields, $amount): OrganizationApprovalRequest {
            $organization = Organization::whereKey($branch->organization_id)->lockForUpdate()->firstOrFail();
            abort_if($organization->suspended_at !== null, 404);
            $existing = $branch->approvalRequests()->where('submission_id', $fields['submission_id'])->first();
            if ($existing !== null) {
                abort_unless((int) $existing->requested_by === $request->user()->id, 404);

                return $existing;
            }

            $rule = $organization->approval_rules[$kind->value] ?? null;
            if ($rule === null) {
                throw ValidationException::withMessages(['kind' => 'กรุณาให้ผู้ดูแลตั้งกติกาอนุมัติของกิจการก่อน']);
            }

            $pending = $amount > $rule['limit'];
            if ($pending && ! $branch->memberships()->where('role', $rule['review_role'])
                ->where('user_id', '<>', $request->user()->id)->whereHas('user', fn ($users) => $users->whereNull('suspended_at'))->exists()) {
                throw ValidationException::withMessages(['kind' => 'สาขานี้ยังไม่มีผู้อนุมัติตามบทบาทที่กำหนด กรุณาติดต่อผู้ดูแล']);
            }

            return $branch->approvalRequests()->create($fields + [
                'requested_by' => $request->user()->id, 'amount' => $amount, 'limit' => $rule['limit'],
                'review_role' => $rule['review_role'], 'status' => $pending ? 'pending' : 'within_limit',
            ]);
        }, 3);

        return redirect()->route('organization-approvals.index', $branch)->with('status', $approval->status === 'pending'
            ? 'ส่งคำขอเข้าคิวของผู้อนุมัติแล้ว' : 'บันทึกรายการในเพดานแล้ว');
    }

    public function decide(Request $request, Branch $branch, OrganizationApprovalRequest $approvalRequest): RedirectResponse
    {
        $role = $this->role($request, $branch);
        abort_unless($approvalRequest->review_role === $role && (int) $approvalRequest->requested_by !== $request->user()->id, 403);
        $fields = $request->validate([
            'status' => ['required', Rule::in(['approved', 'rejected'])],
            'decision_reason' => ['required', 'string', 'max:1500'],
        ]);

        DB::transaction(function () use ($request, $branch, $approvalRequest, $fields): void {
            $organization = Organization::whereKey($branch->organization_id)->lockForUpdate()->firstOrFail();
            abort_if($organization->suspended_at !== null, 404);
            $approval = $branch->approvalRequests()->whereKey($approvalRequest->id)->lockForUpdate()->firstOrFail();
            abort_unless($approval->status === 'pending', 409);
            $approval->update($fields + ['reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);
        }, 3);

        return back()->with('status', 'บันทึกผลการพิจารณาแล้ว');
    }

    private function role(Request $request, Branch $branch): BranchRole
    {
        $role = $branch->roleFor($request->user());
        abort_if($role === null, 404);

        return $role;
    }
}
