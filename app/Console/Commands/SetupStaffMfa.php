<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\StaffSession;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SetupStaffMfa extends Command
{
    protected $signature = 'endu:setup-staff-mfa {email : Verified staff email} {--reset : Replace lost MFA after verifying the staff identity}';

    protected $description = 'Issue a one-time staff MFA setup code from the server';

    public function handle(): int
    {
        $email = Str::lower(trim($this->argument('email')));
        $code = bin2hex(random_bytes(12));

        $issued = DB::transaction(function () use ($email, $code): bool {
            $user = User::where('email', $email)->whereNull('suspended_at')->lockForUpdate()->first();
            if ($user === null || ! $user->requiresStaffMfa() || ($user->mfa_secret !== null && ! $this->option('reset'))) {
                return false;
            }

            $user->mfa_secret = null;
            $user->mfa_recovery_hashes = null;
            $user->mfa_last_step = null;
            $user->mfa_setup_code_hash = hash('sha256', $code);
            $user->mfa_setup_expires_at = now()->addMinutes(15);
            StaffSession::revoke($user);

            return true;
        }, 3);

        if (! $issued) {
            $this->error('บัญชีนี้ไม่พร้อมตั้งค่า หากต้องกู้ MFA ที่ตั้งไว้แล้ว ให้ยืนยันตัวตนพนักงานก่อนใช้ --reset');

            return self::FAILURE;
        }

        $this->info('รหัสตั้งค่าใช้ครั้งเดียว อายุ 15 นาที ให้ส่งถึงเจ้าของบัญชีโดยตรง');
        $this->line($code);

        return self::SUCCESS;
    }
}
