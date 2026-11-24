<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\StaffPassword;
use App\Support\StaffSession;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class StaffPasswordController extends Controller
{
    public function requestForm(): View
    {
        return view('auth.forgot-password');
    }

    public function send(Request $request): RedirectResponse
    {
        $fields = $request->validate(['email' => ['required', 'email', 'max:254']]);
        try {
            Password::sendResetLink(['email' => Str::lower($fields['email']), 'suspended_at' => null]);
        } catch (TransportExceptionInterface) {
            Log::warning('Staff password reset mail could not be delivered.');
        }

        return back()->with('status', 'หากอีเมลนี้มีบัญชีที่ใช้งานได้ ระบบจะส่งลิงก์ตั้งรหัสผ่านใหม่ให้');
    }

    public function resetForm(Request $request, string $token): Response
    {
        return response()->view('auth.reset-password', ['token' => $token, 'email' => $request->string('email')->toString()])
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function reset(Request $request): RedirectResponse
    {
        $fields = $request->validate([
            'email' => ['required', 'email', 'max:254'],
            'token' => ['required', 'string', 'max:128'],
            'password' => StaffPassword::rules(),
        ]);
        $fields['email'] = Str::lower($fields['email']);

        $status = DB::transaction(function () use ($fields): string {
            $account = User::where('email', $fields['email'])->whereNull('suspended_at')->lockForUpdate()->first();
            if ($account === null) {
                return Password::INVALID_USER;
            }

            return Password::reset($fields, function (User $user, string $password) use ($account): void {
                $account->password = $password;
                StaffSession::revoke($account);
                DB::afterCommit(fn () => event(new PasswordReset($account)));
            });
        }, 3);

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => 'ลิงก์ตั้งรหัสผ่านใช้ไม่ได้หรือหมดอายุ กรุณาขอลิงก์ใหม่']);
        }

        return redirect()->route('login')->with('status', 'ตั้งรหัสผ่านใหม่แล้ว กรุณาเข้าสู่ระบบอีกครั้ง');
    }
}
