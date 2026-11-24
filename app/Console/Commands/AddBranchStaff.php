<?php

namespace App\Console\Commands;

use App\Enums\BranchRole;
use App\Models\Branch;
use App\Models\User;
use App\Support\StaffSession;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Console\Input\StreamableInputInterface;

class AddBranchStaff extends Command
{
    protected $signature = 'endu:add-staff
        {organization : Organization code}
        {branch : Branch code}
        {email : Staff login email}
        {role : manager, reception, technician, pc, cashier, stock, accounting, hr, owner or hq}
        {--name= : Staff name for a new account}
        {--password-stdin : Read the password and confirmation as two lines from standard input}';

    protected $description = 'Give a staff account a role at one ENDU CARE branch';

    public function handle(): int
    {
        $data = Validator::make([
            'email' => Str::lower(trim($this->argument('email'))),
            'role' => $this->argument('role'),
        ], [
            'email' => ['required', 'email', 'max:254'],
            'role' => ['required', Rule::enum(BranchRole::class)],
        ])->validate();

        $branch = Branch::query()
            ->where('code', Str::lower(trim($this->argument('branch'))))
            ->whereHas('organization', fn ($query) => $query->where('code', Str::lower(trim($this->argument('organization')))))
            ->first();

        if ($branch === null) {
            $this->error('ไม่พบกิจการและสาขาที่ระบุ กรุณาตั้งค่าสาขาก่อน');

            return self::FAILURE;
        }

        $user = User::where('email', $data['email'])->first();
        $credentials = [];

        if ($user === null) {
            if ($this->option('password-stdin')) {
                $inputStream = $this->input instanceof StreamableInputInterface
                    ? $this->input->getStream() ?? STDIN
                    : STDIN;

                if (stream_isatty($inputStream)) {
                    $this->error('ตัวเลือก --password-stdin ต้องรับรหัสผ่านจากคำสั่งที่ป้อนแบบซ่อนอักขระ');

                    return self::FAILURE;
                }

                $password = rtrim((string) fgets($inputStream, 256), "\r\n");
                $confirmation = rtrim((string) fgets($inputStream, 256), "\r\n");
            } else {
                $password = $this->secret('ตั้งรหัสผ่าน (อย่างน้อย 12 ตัวอักษร)', false);
                $confirmation = $this->secret('ยืนยันรหัสผ่าน', false);
            }

            $validator = Validator::make([
                'name' => trim((string) $this->option('name')),
                'password' => $password,
                'password_confirmation' => $confirmation,
            ], [
                'name' => ['required', 'string', 'max:150'],
                'password' => ['bail', 'required', 'string', function (string $attribute, string $value, \Closure $fail): void {
                    if (! mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                        $fail('อ่านรหัสผ่านไม่ได้ กรุณาลองพิมพ์ใหม่หรือป้อนรหัสผ่านผ่าน PowerShell');
                    } elseif (strlen($value) > 72) {
                        $fail('รหัสผ่านยาวเกิน 72 ไบต์ กรุณาใช้รหัสผ่านที่สั้นลง');
                    }
                }, 'min:12', 'confirmed'],
            ], [
                'name.required' => 'กรุณาระบุชื่อพนักงานด้วย --name',
                'name.max' => 'ชื่อพนักงานต้องไม่เกิน 150 ตัวอักษร',
                'password.required' => 'กรุณาป้อนรหัสผ่าน',
                'password.min' => 'รหัสผ่านต้องมีอย่างน้อย 12 ตัวอักษร',
                'password.confirmed' => 'รหัสผ่านกับคำยืนยันไม่ตรงกัน กรุณาลองใหม่',
            ]);

            if ($validator->fails()) {
                $this->error($validator->errors()->first());

                return self::FAILURE;
            }

            $credentials = $validator->validated();
        }

        DB::transaction(function () use ($data, $branch, $user, $credentials): void {
            $account = $user === null ? User::create([
                'email' => $data['email'],
                'name' => $credentials['name'],
                'password' => $credentials['password'],
            ]) : User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            $membership = $branch->memberships()->firstOrCreate(
                ['user_id' => $account->id],
                ['role' => $data['role']],
            );

            if ($membership->role->value !== $data['role']) {
                throw ValidationException::withMessages(['role' => 'บัญชีนี้มีบทบาทอยู่แล้วในสาขานี้']);
            }
            if ($user !== null && $membership->wasRecentlyCreated) {
                StaffSession::revoke($account);
            }
        });

        $role = BranchRole::from($data['role']);
        $this->info("พร้อมใช้งาน {$data['email']} · {$branch->name} · {$role->label()}");

        return self::SUCCESS;
    }
}
