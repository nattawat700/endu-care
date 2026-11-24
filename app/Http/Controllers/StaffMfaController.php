<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\StaffSession;
use App\Support\StaffTotp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class StaffMfaController extends Controller
{
    public const PENDING = 'staff_mfa_pending';

    private const SETUP = 'staff_mfa_setup';

    public function show(Request $request): Response
    {
        $user = $this->pendingUser($request);
        $setup = $request->session()->get(self::SETUP);

        return response()->view('auth.staff-mfa', [
            'needsSetup' => $user->mfa_secret === null,
            'secret' => $user->mfa_secret === null && $setup ? Crypt::decryptString($setup['secret']) : null,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function setup(Request $request): RedirectResponse
    {
        $fields = $request->validate(['setup_code' => ['required', 'string', 'size:24']]);
        $user = $this->pendingUser($request);
        $hash = hash('sha256', strtolower($fields['setup_code']));

        if ($user->mfa_secret !== null || ! $this->validSetup($user, $hash)) {
            throw ValidationException::withMessages(['setup_code' => 'รหัสตั้งค่าไม่ถูกต้องหรือหมดอายุ กรุณาติดต่อผู้ดูแล']);
        }

        $request->session()->put(self::SETUP, [
            'hash' => $hash,
            'secret' => Crypt::encryptString(StaffTotp::createSecret()),
        ]);

        return redirect()->route('staff-mfa.show');
    }

    public function verify(Request $request): Response
    {
        $fields = $request->validate(['mfa_code' => ['required', 'string', 'max:40']]);
        $pending = $this->pendingUser($request);
        $setup = $request->session()->get(self::SETUP);

        [$user, $recoveryCodes] = DB::transaction(function () use ($pending, $setup, $fields): array {
            $user = User::whereKey($pending->id)->whereNull('suspended_at')->lockForUpdate()->first();
            abort_unless($user !== null && $user->staff_session_version === $pending->staff_session_version, 403);
            $secret = $user->mfa_secret;
            $enrolling = $secret === null;

            if ($enrolling) {
                abort_unless($setup !== null && $this->validSetup($user, $setup['hash']), 403);
                $secret = Crypt::decryptString($setup['secret']);
            }

            $step = StaffTotp::matchingStep($secret, $fields['mfa_code'], now()->timestamp, $user->mfa_last_step);
            $codes = [];
            if ($step !== null) {
                $user->mfa_last_step = $step;
            } elseif ($enrolling || ! $this->consumeRecoveryCode($user, $fields['mfa_code'])) {
                throw ValidationException::withMessages(['mfa_code' => 'รหัสยืนยันไม่ถูกต้อง หมดอายุ หรือถูกใช้แล้ว']);
            }

            if ($enrolling) {
                $user->mfa_secret = $secret;
                for ($index = 0; $index < 8; $index++) {
                    $codes[] = strtoupper(implode('-', str_split(bin2hex(random_bytes(12)), 6)));
                }
                $user->mfa_recovery_hashes = array_map($this->hashRecoveryCode(...), $codes);
                $user->mfa_setup_code_hash = null;
                $user->mfa_setup_expires_at = null;
                StaffSession::revoke($user);
            } else {
                $user->save();
            }

            return [$user, $codes];
        }, 3);

        $request->session()->forget([self::PENDING, self::SETUP]);
        Auth::login($user);
        StaffSession::establish($request, $user);

        if ($recoveryCodes !== []) {
            $continueUrl = redirect()->intended(route('dashboard'))->getTargetUrl();

            return response()->view('auth.staff-recovery-codes', compact('recoveryCodes', 'continueUrl'))->header('Cache-Control', 'private, no-store');
        }

        return redirect()->intended(route('dashboard'));
    }

    private function pendingUser(Request $request): User
    {
        $pending = $request->session()->get(self::PENDING);
        abort_unless($pending !== null && $pending['expires'] >= now()->timestamp, 403);
        $user = User::whereKey($pending['id'])->whereNull('suspended_at')->first();
        abort_unless($user !== null && $user->staff_session_version === $pending['version'], 403);

        return $user;
    }

    private function validSetup(User $user, string $hash): bool
    {
        return $user->mfa_setup_code_hash !== null
            && $user->mfa_setup_expires_at?->isFuture()
            && hash_equals($user->mfa_setup_code_hash, $hash);
    }

    private function consumeRecoveryCode(User $user, string $code): bool
    {
        $hash = $this->hashRecoveryCode($code);
        $hashes = $user->mfa_recovery_hashes ?? [];
        $index = array_search($hash, $hashes, true);
        if ($index === false) {
            return false;
        }

        unset($hashes[$index]);
        $user->mfa_recovery_hashes = array_values($hashes);

        return true;
    }

    private function hashRecoveryCode(string $code): string
    {
        return hash('sha256', strtoupper(str_replace('-', '', $code)));
    }
}
