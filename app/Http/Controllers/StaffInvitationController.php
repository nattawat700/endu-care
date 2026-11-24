<?php

namespace App\Http\Controllers;

use App\Models\StaffInvitation;
use App\Models\User;
use App\Support\StaffPassword;
use App\Support\StaffSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class StaffInvitationController extends Controller
{
    public function show(Request $request, string $token): Response
    {
        $invitation = StaffInvitation::active($token)->load('branch.organization');
        $account = User::where('email', $invitation->email)->first();

        if ($account !== null && $request->user() === null) {
            return redirect()->guest(route('login'))->with('status', 'เข้าสู่ระบบด้วยอีเมลที่ได้รับคำเชิญเพื่อรับสิทธิ์สาขาใหม่');
        }

        $this->authorizeAccount($request, $account);

        return response()->view('staff.accept-invitation', compact('invitation', 'token', 'account'))
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $initial = StaffInvitation::active($token);

        DB::transaction(function () use ($request, $initial, $token): void {
            $account = User::where('email', $initial->email)->lockForUpdate()->first();
            $invitation = StaffInvitation::whereKey($initial->id)->where('token_hash', hash('sha256', $token))
                ->whereNull('accepted_at')->where('expires_at', '>', now())->lockForUpdate()->firstOrFail();
            $this->authorizeAccount($request, $account);
            $branch = $invitation->branch;
            $inviter = $invitation->inviter;
            abort_unless($branch->roleFor($inviter)?->canManageRole($invitation->role), 404);

            if ($account === null) {
                $fields = $request->validate(['password' => StaffPassword::rules()]);
                $account = User::createOrFirst(['email' => $invitation->email], [
                    'name' => $invitation->name, 'password' => $fields['password'],
                ]);
                if (! $account->wasRecentlyCreated) {
                    throw ValidationException::withMessages(['password' => 'อีเมลนี้มีบัญชีแล้ว กรุณาเปิดคำเชิญอีกครั้งและเข้าสู่ระบบ']);
                }

                $account->email_verified_at = now();
                $account->save();
            }

            $membership = $branch->memberships()->firstOrCreate(['user_id' => $account->id], ['role' => $invitation->role]);
            if (! $membership->wasRecentlyCreated) {
                throw ValidationException::withMessages(['invitation' => 'บัญชีนี้มีสิทธิ์ในสาขาแล้ว กรุณาเปิดหน้าเลือกสาขา']);
            }

            $invitation->update(['accepted_at' => now()]);
            $branch->staffAccessEvents()->create([
                'actor_id' => $inviter->id, 'user_id' => $account->id, 'action' => 'invitation_accepted', 'role' => $invitation->role,
            ]);
            if (! $account->wasRecentlyCreated) {
                StaffSession::revoke($account);
            }
        }, 3);

        if ($request->user() !== null) {
            StaffSession::logout($request);
        }

        return redirect()->route('login')->with('status', 'รับคำเชิญแล้ว กรุณาเข้าสู่ระบบ');
    }

    private function authorizeAccount(Request $request, ?User $account): void
    {
        $signedIn = $request->user();
        abort_unless(
            ($account === null && $signedIn === null)
                || ($account !== null && $account->suspended_at === null && $signedIn?->is($account)),
            403,
            'กรุณาใช้บัญชีอีเมลที่ได้รับคำเชิญ',
        );
    }
}
