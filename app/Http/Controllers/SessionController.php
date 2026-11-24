<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\StaffSession;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SessionController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:254'],
            'password' => ['required', 'string', 'max:72'],
        ]);

        $credentials['email'] = Str::lower($credentials['email']);
        if (strlen($credentials['password']) > 72) {
            throw ValidationException::withMessages(['email' => 'อีเมลหรือรหัสผ่านไม่ถูกต้อง']);
        }

        $pending = null;
        $authenticated = Auth::attemptWhen($credentials + ['suspended_at' => null], function (User $user) use (&$pending): bool {
            if (! $user->requiresStaffMfa()) {
                return true;
            }

            $pending = $user;

            return false;
        });

        if ($pending !== null) {
            $request->session()->forget(['staff_mfa_setup', StaffSession::VERSION]);
            $request->session()->regenerate(true);
            $request->session()->put(StaffMfaController::PENDING, [
                'id' => $pending->id,
                'version' => $pending->staff_session_version,
                'expires' => now()->addMinutes(15)->timestamp,
            ]);

            return redirect()->route('staff-mfa.show');
        }

        if (! $authenticated) {
            throw ValidationException::withMessages(['email' => 'อีเมลหรือรหัสผ่านไม่ถูกต้อง']);
        }

        $request->session()->forget([StaffMfaController::PENDING, 'staff_mfa_setup']);
        StaffSession::establish($request, $request->user());

        return redirect()->intended(route('dashboard'));
    }

    public function revokeOthers(Request $request): RedirectResponse
    {
        $user = DB::transaction(function () use ($request): User {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_if($user->suspended_at !== null, 403);
            StaffSession::revoke($user);

            return $user;
        }, 3);
        StaffSession::establish($request, $user);

        return back()->with('status', 'ยุติเซสชันอื่นของบัญชีนี้แล้ว');
    }

    public function destroy(Request $request): RedirectResponse
    {
        StaffSession::logout($request);

        return redirect()->route('login');
    }
}
