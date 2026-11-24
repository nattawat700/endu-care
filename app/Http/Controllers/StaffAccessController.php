<?php

namespace App\Http\Controllers;

use App\Enums\BranchPermission;
use App\Enums\BranchRole;
use App\Models\AuditEvent;
use App\Models\Branch;
use App\Models\BranchMembership;
use App\Models\StaffInvitation;
use App\Models\User;
use App\Notifications\StaffInvitationNotice;
use App\Support\StaffSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class StaffAccessController extends Controller
{
    public function index(Request $request, Branch $branch): Response
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManageStaff]);
        $role = $branch->roleFor($request->user());

        return response()->view('staff.index', [
            'branch' => $branch,
            'memberships' => $branch->memberships()->with(['user:id,name,email,suspended_at', 'user.memberships:branch_id,user_id,role'])->orderBy('id')->paginate(25),
            'invitations' => $branch->staffInvitations()->whereNull('accepted_at')->where('expires_at', '>', now())->latest('id')->limit(20)->get(),
            'history' => $branch->staffAccessEvents()->with(['user:id,name', 'actor:id,name'])->latest('id')->limit(15)->get(),
            'assignable' => array_filter(BranchRole::cases(), fn (BranchRole $candidate) => $role->canManageRole($candidate)),
            'actorRole' => $role,
            'actorAssignments' => $request->user()->memberships()->get(['branch_id', 'role'])->pluck('role', 'branch_id'),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function invite(Request $request, Branch $branch): RedirectResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManageStaff]);
        $fields = $request->validate([
            'email' => ['required', 'email', 'max:254'],
            'name' => ['required', 'string', 'max:150'],
            'role' => ['required', Rule::enum(BranchRole::class)],
        ]);
        $email = Str::lower($fields['email']);
        $token = bin2hex(random_bytes(32));
        $role = BranchRole::from($fields['role']);

        DB::transaction(function () use ($branch, $request, $fields, $email, $token, $role): void {
            $locked = Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $this->authorizeRole($request->user(), $locked, $role);
            if ($locked->memberships()->whereHas('user', fn ($users) => $users->where('email', $email))->exists()) {
                throw ValidationException::withMessages(['email' => 'อีเมลนี้เป็นพนักงานของสาขาแล้ว สามารถแก้บทบาทจากรายชื่อพนักงานได้']);
            }

            $locked->staffInvitations()->updateOrCreate(['email' => $email], [
                'name' => $fields['name'],
                'role' => $role,
                'invited_by' => $request->user()->id,
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addHours(48),
                'accepted_at' => null,
            ]);
        }, 3);

        $url = rtrim(config('app.url'), '/').route('staff-invitations.show', $token, absolute: false);
        try {
            Notification::route('mail', $email)->notify(new StaffInvitationNotice($url, $branch->name));
        } catch (TransportExceptionInterface) {
            throw ValidationException::withMessages(['email' => 'ส่งอีเมลไม่สำเร็จ กรุณาตรวจการตั้งค่าอีเมลแล้วส่งคำเชิญอีกครั้ง']);
        }

        return back()->with('status', 'ส่งคำเชิญพนักงานแล้ว');
    }

    public function cancelInvitation(Request $request, Branch $branch, StaffInvitation $staffInvitation): RedirectResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManageStaff]);
        DB::transaction(function () use ($request, $branch, $staffInvitation): void {
            $invitation = $branch->staffInvitations()->whereKey($staffInvitation->id)->whereNull('accepted_at')->lockForUpdate()->firstOrFail();
            $this->authorizeRole($request->user(), $branch, $invitation->role);
            $invitation->delete();
        }, 3);

        return back()->with('status', 'ยกเลิกคำเชิญแล้ว');
    }

    public function changeRole(Request $request, Branch $branch, BranchMembership $membership): RedirectResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManageStaff]);
        $fields = $request->validate(['role' => ['required', Rule::enum(BranchRole::class)]]);
        $role = BranchRole::from($fields['role']);

        DB::transaction(function () use ($request, $branch, $membership, $role): void {
            [$account, $current] = $this->managedMembership($request->user(), $branch, $membership);
            $this->authorizeRole($request->user(), $branch, $role);
            if ($current->role !== $role) {
                $current->update(['role' => $role]);
                StaffSession::revoke($account);
                $this->record($branch, $request->user(), $account, 'role_changed', $role);
            }
        }, 3);

        return back()->with('status', 'อัปเดตบทบาทพนักงานแล้ว');
    }

    public function remove(Request $request, Branch $branch, BranchMembership $membership): RedirectResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManageStaff]);

        DB::transaction(function () use ($request, $branch, $membership): void {
            [$account, $current] = $this->managedMembership($request->user(), $branch, $membership);
            $role = $current->role;
            $current->delete();
            StaffSession::revoke($account);
            $this->record($branch, $request->user(), $account, 'branch_removed', $role);
        }, 3);

        return back()->with('status', 'ถอนสิทธิ์สาขาแล้ว');
    }

    public function account(Request $request, Branch $branch, BranchMembership $membership): RedirectResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManageStaff]);
        $fields = $request->validate(['action' => ['required', Rule::in(['revoke_sessions', 'suspend', 'restore'])]]);
        $action = $fields['action'];

        DB::transaction(function () use ($request, $branch, $membership, $action): void {
            [$account, $current] = $this->managedMembership($request->user(), $branch, $membership);
            $actor = $request->user();
            $assignments = $account->memberships()->with('branch')->get();
            foreach ($assignments as $assignment) {
                $this->authorizeRole($actor, $assignment->branch, $assignment->role);
            }

            $before = ['suspended_at' => $account->getRawOriginal('suspended_at'), 'staff_session_version' => $account->staff_session_version];
            if ($action === 'suspend') {
                $account->suspended_at ??= now();
                $account->mfa_setup_code_hash = null;
                $account->mfa_setup_expires_at = null;
                Password::broker()->deleteToken($account);
                StaffInvitation::where('email', $account->email)->whereIn('branch_id', $assignments->pluck('branch_id'))
                    ->whereNull('accepted_at')->update(['expires_at' => now()]);
            } elseif ($action === 'restore') {
                $account->suspended_at = null;
            }

            StaffSession::revoke($account);
            $this->record($branch, $actor, $account, $action, $current->role);
            AuditEvent::create([
                'organization_id' => $branch->organization_id, 'branch_id' => $branch->id,
                'actor_id' => $actor->id, 'actor_name' => $actor->name,
                'subject_type' => 'staff_account', 'subject_reference' => 'STAFF-'.$account->id, 'operation' => $action,
                'before_values' => $before,
                'after_values' => ['suspended_at' => $account->getRawOriginal('suspended_at'), 'staff_session_version' => $account->staff_session_version],
                'occurred_at' => now(),
            ]);
        }, 3);

        return back()->with('status', match ($action) {
            'suspend' => 'ระงับบัญชีและยุติเซสชันแล้ว',
            'restore' => 'คืนสิทธิ์เข้าใช้งานบัญชีแล้ว',
            'revoke_sessions' => 'ยุติเซสชันของพนักงานแล้ว',
        });
    }

    private function authorizeRole(User $actor, Branch $branch, BranchRole $targetRole): void
    {
        abort_unless($branch->roleFor($actor)?->canManageRole($targetRole), 403);
    }

    /** @return array{User, BranchMembership} */
    private function managedMembership(User $actor, Branch $branch, BranchMembership $membership): array
    {
        $account = User::whereKey($membership->user_id)->lockForUpdate()->firstOrFail();
        abort_if($account->is($actor), 403);
        $current = $branch->memberships()->whereKey($membership->id)->lockForUpdate()->firstOrFail();
        $this->authorizeRole($actor, $branch, $current->role);

        return [$account, $current];
    }

    private function record(Branch $branch, User $actor, User $account, string $action, BranchRole $role): void
    {
        $branch->staffAccessEvents()->create([
            'actor_id' => $actor->id, 'user_id' => $account->id, 'action' => $action, 'role' => $role,
        ]);
    }
}
