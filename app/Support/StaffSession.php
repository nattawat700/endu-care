<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StaffSession
{
    public const VERSION = 'staff_auth_version';

    public static function establish(Request $request, User $user): void
    {
        $request->session()->regenerate();
        $request->session()->put(self::VERSION, $user->staff_session_version);
    }

    public static function logout(Request $request): void
    {
        Auth::logoutCurrentDevice();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    public static function revoke(User $user): void
    {
        $user->staff_session_version++;
        $user->setRememberToken(Str::random(60));
        $user->save();

        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))->table(config('session.table'))
                ->where('user_id', $user->id)->delete();
        }
    }
}
