<?php

use App\Enums\BranchPermission;
use App\Enums\BranchRole;
use App\Enums\RepairStatus;
use App\Models\Organization;
use App\Models\StaffAccessEvent;
use App\Models\StaffInvitation;
use App\Models\User;
use App\Notifications\StaffInvitationNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $organization = Organization::create(['code' => 'endu', 'name' => 'ENDU']);
    $this->branch = $organization->branches()->create(['code' => 'fix', 'name' => 'ENDU FIX']);
    $this->secondBranch = $organization->branches()->create(['code' => 'bkp', 'name' => 'บางกะปิ']);
    $other = Organization::create(['code' => 'other', 'name' => 'กิจการอื่น']);
    $this->foreignBranch = $other->branches()->create(['code' => 'fix', 'name' => 'สาขาอีกกิจการ']);
    $this->manager = User::factory()->create(['name' => 'ผู้จัดการทดสอบ']);
    foreach ([$this->branch, $this->secondBranch] as $branch) {
        $branch->memberships()->create(['user_id' => $this->manager->id, 'role' => BranchRole::Manager]);
    }
});

test('an invitation grants a new account one branch and an existing login a different role in another branch', function () {
    Notification::fake();
    config(['app.url' => 'https://endu-care.example.test']);
    $email = 'newstaff@example.test';
    $invite = ['name' => 'พนักงานใหม่', 'email' => $email, 'role' => 'reception'];
    $password = ['password' => 'test-only-password', 'password_confirmation' => 'test-only-password'];

    $this->actingAs($this->manager)->post(route('staff.invite', $this->branch), $invite)->assertSessionHasNoErrors();
    $notice = Notification::sent(new AnonymousNotifiable, StaffInvitationNotice::class)->sole();
    expect($notice->link)->toStartWith('https://endu-care.example.test/staff-invitations/');
    $firstToken = basename($notice->link);
    expect(StaffInvitation::sole()->token_hash)->toBe(hash('sha256', $firstToken));
    expect(User::where('email', $email)->exists())->toBeFalse();

    Auth::forgetGuards();
    $firstUrl = route('staff-invitations.show', $firstToken);
    $this->get($firstUrl)->assertOk()->assertSee('ENDU FIX')->assertHeader('Referrer-Policy', 'no-referrer');
    $this->post($firstUrl, $password)->assertRedirect(route('login'));
    $account = User::where('email', $email)->sole();
    expect($this->branch->roleFor($account))->toBe(BranchRole::Reception);
    $this->post($firstUrl, $password)->assertNotFound();

    $this->actingAs($this->manager)->post(route('staff.invite', $this->secondBranch), ['role' => 'technician'] + $invite)
        ->assertSessionHasNoErrors();
    $secondToken = basename(Notification::sent(new AnonymousNotifiable, StaffInvitationNotice::class)->last()->link);
    $secondUrl = route('staff-invitations.show', $secondToken);
    $this->post($secondUrl, $password)->assertForbidden();

    $this->actingAs($account)->get($secondUrl)->assertOk();
    $this->post($secondUrl)->assertRedirect(route('login'));
    expect($this->secondBranch->roleFor($account))->toBe(BranchRole::Technician)
        ->and($account->fresh()->staff_session_version)->toBe(2)
        ->and(User::where('email', $email)->count())->toBe(1);
    $this->assertDatabaseCount('staff_access_events', 2);
});

test('staff managers cannot grant elevated roles or edit an assignment from a different branch', function () {
    Notification::fake();
    $target = User::factory()->create();
    $foreign = $this->foreignBranch->memberships()->create(['user_id' => $target->id, 'role' => BranchRole::Technician]);
    $local = $this->branch->memberships()->create(['user_id' => $target->id, 'role' => BranchRole::Pc]);
    $invite = ['name' => 'ผู้สมัคร', 'email' => 'person@example.test', 'role' => 'hq'];

    $this->actingAs($this->manager)->get(route('staff.index', $this->branch))->assertOk()->assertSee('จัดการสิทธิ์');
    $this->post(route('staff.invite', $this->branch), $invite)->assertForbidden();
    $this->post(route('staff.invite', $this->foreignBranch), ['role' => 'reception'] + $invite)->assertNotFound();
    $this->patch(route('staff.change-role', [$this->branch, $foreign]), ['role' => 'cashier'])->assertNotFound();
    $this->patch(route('staff.change-role', [$this->branch, $local]), ['role' => 'manager'])->assertForbidden();
    $this->patch(route('staff.account', [$this->branch, $local]), ['action' => 'suspend'])->assertForbidden();
    $this->patch(route('staff.change-role', [$this->branch, $local]), ['role' => 'cashier'])->assertSessionHasNoErrors();
    expect($local->fresh()->role)->toBe(BranchRole::Cashier);
    $this->delete(route('staff.remove', [$this->branch, $local]))->assertSessionHasNoErrors();
    expect($this->branch->roleFor($target))->toBeNull()
        ->and($this->foreignBranch->roleFor($target))->toBe(BranchRole::Technician);
    Notification::assertNothingSent();
});

test('a suspended account loses sessions and retains its name on prior records', function () {
    config(['session.driver' => 'database', 'session.connection' => null]);
    $staff = User::factory()->create(['name' => 'ช่างที่พ้นงาน']);
    $member = $this->branch->memberships()->create(['user_id' => $staff->id, 'role' => BranchRole::Technician]);
    $job = $this->branch->repairs()->create([
        'created_by' => $staff->id, 'customer_name' => 'ลูกค้า', 'customer_phone' => '0811111111',
        'device' => 'iPhone', 'issue' => 'จอแตก', 'submission_id' => (string) Str::uuid(),
    ]);
    DB::table('sessions')->insert(['id' => 'previous-staff-session', 'user_id' => $staff->id, 'payload' => '', 'last_activity' => now()->timestamp]);
    $this->actingAs($this->manager)->patch(route('staff.account', [$this->branch, $member]), ['action' => 'suspend'])
        ->assertSessionHasNoErrors();
    $this->assertDatabaseMissing('sessions', ['id' => 'previous-staff-session']);
    $this->get(route('repairs.show', [$this->branch, $job]))->assertOk()->assertSee('ช่างที่พ้นงาน');
    $this->get(route('staff.index', $this->branch))->assertOk()->assertSee('ระงับบัญชี');
    expect($staff->fresh()->suspended_at)->not->toBeNull()
        ->and($staff->fresh()->staff_session_version)->toBe(2)
        ->and(StaffAccessEvent::sole()->action)->toBe('suspend');

    $this->actingAs($staff->fresh())->get(route('dashboard'))->assertRedirect(route('login'));
    $this->post(route('login.store'), ['email' => $staff->email, 'password' => 'password'])->assertSessionHasErrors('email');
    $this->actingAs($this->manager)->patch(route('staff.account', [$this->branch, $member]), ['action' => 'restore'])
        ->assertSessionHasNoErrors();
    expect($staff->fresh()->suspended_at)->toBeNull();
});

test('repair read, edit, approval, cancellation, print and export permissions are distinct by branch role', function () {
    $staff = User::factory()->create();
    $this->branch->memberships()->create(['user_id' => $staff->id, 'role' => BranchRole::Pc]);
    $this->secondBranch->memberships()->create(['user_id' => $staff->id, 'role' => BranchRole::Cashier]);
    $record = [
        'created_by' => $this->manager->id, 'customer_name' => 'ชื่อส่วนบุคคลทดสอบ', 'customer_phone' => '0811111111',
        'device' => '=1+1', 'issue' => 'จอแตก', 'submission_id' => (string) Str::uuid(),
    ];
    $job = $this->branch->repairs()->create($record);
    $secondJob = $this->secondBranch->repairs()->create(['device' => 'ข้อมูลสาขาที่สอง', 'submission_id' => (string) Str::uuid()] + $record);
    $job->status = RepairStatus::AwaitingApproval;
    $job->save();

    $this->actingAs($staff)->get(route('repairs.show', [$this->branch, $job]))->assertOk()
        ->assertDontSee($record['customer_name'])->assertDontSee($record['customer_phone']);
    $this->get(route('dashboard', ['branch' => $this->branch->id]))->assertOk()->assertDontSee($record['customer_name']);
    $this->get(route('repairs.create', $this->branch))->assertOk();
    $this->get(route('repairs.assessment.create', [$this->branch, $job]))->assertForbidden();
    $this->get(route('repairs.print', [$this->branch, $job]))->assertForbidden();
    $this->get(route('repairs.quotations.review-queue', $this->branch))->assertForbidden();
    $this->get(route('repairs.export', $this->branch))->assertForbidden();
    $this->get(route('repairs.show', [$this->branch, $secondJob]))->assertNotFound();
    $this->get(route('repairs.export', $this->foreignBranch))->assertNotFound();
    $this->patch(route('repairs.change-status', [$this->branch, $job]), [
        'next_status' => 'returned_unrepaired', 'workflow_version' => 0, 'assignment_version' => 0, 'workflow_note' => 'ลูกค้าไม่ซ่อม',
    ])->assertForbidden();

    $this->get(route('repairs.show', [$this->secondBranch, $secondJob]))->assertOk()->assertSee($record['customer_name']);
    $this->get(route('repairs.create', $this->secondBranch))->assertForbidden();
    $this->get(route('repairs.quotations.index', [$this->secondBranch, $secondJob]))->assertOk()->assertDontSee('ออกใบเสนอราคา');

    $export = $this->actingAs($this->manager)->get(route('repairs.export', $this->branch))->assertDownload('repairs-fix.csv');
    expect($export->streamedContent())->toContain("'=1+1")->not->toContain($record['customer_name'], $secondJob->device);
});

test('only the relevant presets have access to costs, profit, compensation and commission', function () {
    expect(BranchRole::Owner->allows(BranchPermission::Compensation))->toBeTrue()
        ->and(BranchRole::Hq->allows(BranchPermission::Profit))->toBeTrue()
        ->and(BranchRole::Hr->allows(BranchPermission::Compensation))->toBeTrue()
        ->and(BranchRole::Hr->allows(BranchPermission::CustomerData))->toBeFalse()
        ->and(BranchRole::Accounting->allows(BranchPermission::Commissions))->toBeTrue()
        ->and(BranchRole::Stock->allows(BranchPermission::Costs))->toBeTrue()
        ->and(BranchRole::Stock->allows(BranchPermission::Profit))->toBeFalse()
        ->and(BranchRole::Manager->allows(BranchPermission::Compensation))->toBeFalse()
        ->and(BranchRole::Reception->allows(BranchPermission::Costs))->toBeFalse()
        ->and(BranchRole::Technician->allows(BranchPermission::CustomerData))->toBeFalse();
});
