<?php

use App\Enums\BranchRole;
use App\Models\Organization;
use App\Models\User;
use App\Support\StaffSession;
use App\Support\StaffTotp;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $organization = Organization::create(['code' => 'endu', 'name' => 'ENDU']);
    $this->branch = $organization->branches()->create(['code' => 'fix', 'name' => 'ENDU FIX']);
});

test('a password reset link is single use and invalidates previously authenticated sessions', function () {
    Notification::fake();
    config(['session.driver' => 'database', 'session.connection' => null]);
    $staff = User::factory()->create();
    $this->branch->memberships()->create(['user_id' => $staff->id, 'role' => BranchRole::Reception]);
    $credentials = ['email' => $staff->email, 'password' => 'password'];
    $this->post(route('login.store'), $credentials)->assertRedirect(route('dashboard'))->assertSessionHas(StaffSession::VERSION, 1);
    $this->assertAuthenticatedAs($staff);
    $this->post(route('logout'))->assertRedirect(route('login'));
    DB::table('sessions')->insert(['id' => 'other-session-before-reset', 'user_id' => $staff->id, 'payload' => '', 'last_activity' => now()->timestamp]);

    $this->post(route('password.email'), ['email' => $staff->email])->assertSessionHas('status');
    $notice = Notification::sent($staff, ResetPassword::class)->sole();
    $payload = [
        'email' => $staff->email, 'token' => $notice->token,
        'password' => 'updated-test-password', 'password_confirmation' => 'updated-test-password',
    ];
    $this->post(route('password.update'), $payload)->assertRedirect(route('login'));
    $this->assertDatabaseMissing('sessions', ['id' => 'other-session-before-reset']);
    expect(Hash::check($payload['password'], $staff->fresh()->password))->toBeTrue();
    expect($staff->fresh()->staff_session_version)->toBe(2);
    $this->post(route('password.update'), $payload)->assertSessionHasErrors('email');

    $this->actingAs($staff->fresh())->withSession([Auth::guard()->getName() => $staff->id, StaffSession::VERSION => 1])
        ->get(route('dashboard'))->assertRedirect(route('login'));
    $this->post(route('login.store'), $credentials)->assertSessionHasErrors('email');
    $this->post(route('login.store'), ['password' => $payload['password']] + $credentials)
        ->assertRedirect(route('dashboard'))->assertSessionHas(StaffSession::VERSION, 2);
});

test('privileged staff enroll with a server code and can use each recovery code only once', function () {
    $this->travelTo(Carbon::parse('2026-11-24T03:02:15Z'));
    $staff = User::factory()->create();
    $this->branch->memberships()->create(['user_id' => $staff->id, 'role' => BranchRole::Manager]);
    $credentials = ['email' => $staff->email, 'password' => 'password'];
    expect(Artisan::call('endu:setup-staff-mfa', ['email' => $staff->email]))->toBe(0);
    preg_match('/^[a-f0-9]{24}$/m', Artisan::output(), $match);
    $activation = $match[0];
    $entry = route('staff-mfa.show');

    $this->post(route('login.store'), $credentials)->assertRedirect($entry);
    $this->assertGuest();
    $this->get(route('staff.index', $this->branch))->assertRedirect(route('login'));
    $this->get($entry)->assertOk()->assertViewHas('secret', null);
    $this->post(route('staff-mfa.setup'), ['setup_code' => str_repeat('a', 24)])->assertSessionHasErrors('setup_code');
    $this->post(route('staff-mfa.setup'), ['setup_code' => $activation])->assertRedirect($entry);
    $secret = $this->get($entry)->assertOk()->viewData('secret');
    expect($secret)->toMatch('/^[A-Z2-7]{32}$/');

    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($secret) as $symbol) {
        $bits .= str_pad(decbin(strpos($alphabet, $symbol)), 5, '0', STR_PAD_LEFT);
    }
    $key = implode(array_map(fn (string $byte) => chr(bindec($byte)), str_split($bits, 8)));
    $step = intdiv(now()->timestamp, 30);
    $digest = hash_hmac('sha1', hex2bin(sprintf('%016x', $step)), $key, true);
    $start = ord(substr($digest, -1)) % 16;
    $code = sprintf('%06d', (hexdec(bin2hex(substr($digest, $start, 4))) % 2147483648) % 1000000);

    $enrolled = $this->post(route('staff-mfa.verify'), ['mfa_code' => $code])->assertOk()->assertSee('เก็บรหัสกู้คืน');
    $codes = $enrolled->viewData('recoveryCodes');
    $staff->refresh();
    expect($codes)->toHaveCount(8);
    expect($staff->mfa_setup_code_hash)->toBeNull();
    expect($staff->getRawOriginal('mfa_secret'))->not->toBe($secret);
    expect($staff->mfa_recovery_hashes)->not->toContain($codes[0]);
    $this->assertAuthenticatedAs($staff);
    $this->post(route('logout'))->assertRedirect(route('login'));

    $this->post(route('login.store'), $credentials)->assertRedirect($entry);
    $this->post(route('staff-mfa.verify'), ['mfa_code' => $code])->assertSessionHasErrors('mfa_code');
    $this->post(route('staff-mfa.verify'), ['mfa_code' => $codes[0]])->assertRedirect();
    $this->assertAuthenticatedAs($staff);
    expect($staff->fresh()->mfa_recovery_hashes)->toHaveCount(7);
    $this->post(route('logout'))->assertRedirect(route('login'));
    $this->post(route('login.store'), $credentials)->assertRedirect($entry);
    $this->post(route('staff-mfa.verify'), ['mfa_code' => $codes[0]])->assertSessionHasErrors('mfa_code');

    expect(Artisan::call('endu:setup-staff-mfa', ['email' => $staff->email, '--reset' => true]))->toBe(0);
    expect($staff->fresh()->mfa_secret)->toBeNull();
    $this->get($entry)->assertForbidden();
});

test('authenticator codes match the RFC vector and cannot be reused', function () {
    $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
    expect(StaffTotp::matchingStep($secret, '287082', 59, null))->toBe(1)
        ->and(StaffTotp::matchingStep($secret, '287082', 59, 1))->toBeNull()
        ->and(StaffTotp::matchingStep($secret, '287082', 90, null))->toBeNull();
});

test('granting an administrative branch role from the console revokes sessions created before MFA was required', function () {
    $staff = User::factory()->create();
    $credentials = ['email' => $staff->email, 'password' => 'password'];
    $this->branch->memberships()->create(['user_id' => $staff->id, 'role' => BranchRole::Reception]);
    $secondBranch = $this->branch->organization->branches()->create(['code' => 'care', 'name' => 'ENDU CARE']);

    $this->post(route('login.store'), $credentials)->assertSessionHas(StaffSession::VERSION, 1);
    $this->artisan('endu:add-staff', [
        'organization' => 'endu', 'branch' => $secondBranch->code, 'email' => $staff->email, 'role' => 'manager',
    ])->assertSuccessful();
    Auth::forgetGuards();
    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->post(route('login.store'), $credentials)->assertRedirect(route('staff-mfa.show'));
    $this->assertGuest();
});
