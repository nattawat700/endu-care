<?php

use App\Enums\BranchRole;
use App\Enums\RepairStatus;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\RepairJob;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();

    $endu = Organization::create(['code' => 'endu', 'name' => 'ENDU']);
    $this->fix = $endu->branches()->create(['code' => 'fix', 'name' => 'ENDU FIX']);
    $this->care = $endu->branches()->create(['code' => 'bkp', 'name' => 'ENDU CARE บางกะปิ']);
    $another = Organization::create(['code' => 'another', 'name' => 'Another business']);
    $this->otherBranch = $another->branches()->create(['code' => 'fix', 'name' => 'Another repair shop']);

    $this->reception = User::factory()->create();
    $this->manager = User::factory()->create();
    $this->technician = User::factory()->create();
    $this->otherTechnician = User::factory()->create();
    $this->fix->memberships()->create(['user_id' => $this->reception->id, 'role' => BranchRole::Reception]);
    $this->fix->memberships()->create(['user_id' => $this->manager->id, 'role' => BranchRole::Manager]);
    $this->fix->memberships()->create(['user_id' => $this->technician->id, 'role' => BranchRole::Technician]);
    $this->care->memberships()->create(['user_id' => $this->otherTechnician->id, 'role' => BranchRole::Technician]);
    $this->care->memberships()->create(['user_id' => $this->manager->id, 'role' => BranchRole::Technician]);

    $this->intake = [
        'submission_id' => (string) Str::uuid(),
        'customer_name' => 'ลูกค้าทดสอบ',
        'customer_phone' => '081-123-4567',
        'product' => 'โทรศัพท์มือถือ',
        'device' => 'iPhone 13',
        'device_condition' => 'ขอบเครื่องมีรอย',
        'issue' => 'ชาร์จไม่เข้า',
        'warranty_status' => 'covered',
    ];
});

test('staff can sign in and sign out and only see their branches', function () {
    $this->get(route('login'))->assertOk();
    $this->post(route('login.store'), [
        'email' => $this->reception->email,
        'password' => 'incorrect-password',
    ])->assertSessionHasErrors('email');
    $this->assertGuest();

    $this->post(route('login.store'), [
        'email' => strtoupper($this->reception->email),
        'password' => 'password',
    ])->assertRedirectToRoute('dashboard');
    $this->assertAuthenticatedAs($this->reception);

    $this->get(route('dashboard'))->assertOk()
        ->assertSee('ENDU FIX')
        ->assertDontSee($this->care->name)
        ->assertDontSee($this->otherBranch->name);

    $this->post(route('logout'))->assertRedirectToRoute('login');
    $this->assertGuest();
});

test('repeated login attempts are throttled', function () {
    for ($attempt = 0; $attempt < 8; $attempt++) {
        $this->post(route('login.store'), [
            'email' => $this->reception->email,
            'password' => 'incorrect-password',
        ])->assertSessionHasErrors('email');
    }

    $this->post(route('login.store'), [
        'email' => $this->reception->email,
        'password' => 'password',
    ])->assertStatus(429);

    $this->assertGuest();
});

test('reception can open and print a repair exactly once per intake submission', function () {
    $this->actingAs($this->reception)->get(route('repairs.create', $this->fix))->assertOk();
    $data = $this->intake + ['branch_id' => $this->care->id, 'created_by' => $this->manager->id];

    $response = $this->post(route('repairs.store', $this->fix), $data);
    $repair = RepairJob::sole();

    $response->assertRedirectToRoute('repairs.show', [$this->fix, $repair]);
    $this->post(route('repairs.store', $this->fix), $data)
        ->assertRedirectToRoute('repairs.show', [$this->fix, $repair]);

    $this->assertDatabaseCount('repair_jobs', 1);
    $this->assertDatabaseHas('repair_jobs', [
        'id' => $repair->id,
        'branch_id' => $this->fix->id,
        'created_by' => $this->reception->id,
        'customer_phone' => $data['customer_phone'],
        'technician_id' => null,
    ]);

    $this->get(route('repairs.print', [$this->fix, $repair]))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertSee('ใบส่งซ่อม ENDU FIX')
        ->assertSee('images/endu-fix-receipt-logo.png')
        ->assertSee($repair->reference())
        ->assertSee($repair->created_at->timezone('Asia/Bangkok')->format('d/m/Y'))
        ->assertSee($data['customer_name'])
        ->assertSee($data['customer_phone'])
        ->assertSee($data['device'])
        ->assertSee($data['issue'])
        ->assertSee($this->reception->name);
});

test('another organization with the same branch code keeps its existing receipt', function () {
    $this->otherBranch->memberships()->create(['user_id' => $this->reception->id, 'role' => BranchRole::Reception]);
    $job = $this->otherBranch->repairs()->create($this->intake + ['created_by' => $this->reception->id]);

    $this->actingAs($this->reception)
        ->get(route('repairs.print', [$this->otherBranch, $job]))
        ->assertOk()
        ->assertSee('ใบรับเครื่องเข้าซ่อม')
        ->assertDontSee('images/endu-fix-receipt-logo.png');
});

test('the ENDU FIX form saves and prints the complete repair intake', function () {
    $fields = $this->intake + [
        'customer_address' => '10 ซอยทดสอบ กรุงเทพฯ', 'color' => 'สีดำ',
        'imei' => '012345678901234', 'serial_number' => 'TEST-SN-01',
        'intake_terms' => 'แจ้งราคาก่อนซ่อม', 'policy_number' => 'DEMO-POLICY-01',
        'accessories' => 'สายชาร์จ', 'device_passcode' => ' Demo-pass-7462 ',
        'device_unlock_pattern' => '1258',
    ];

    $this->actingAs($this->reception);
    $response = $this->get(route('repairs.create', $this->fix))->assertOk();
    foreach (array_keys($fields) as $name) {
        $response->assertSee('name="'.$name.'"', false);
    }

    $created = $this->post(route('repairs.store', $this->fix), $fields);
    $job = RepairJob::sole();
    $created->assertRedirectToRoute('repairs.show', [$this->fix, $job]);

    expect($job->device_passcode)->toBe($fields['device_passcode'])
        ->and($job->device_unlock_pattern)->toBe(['1', '2', '5', '8'])
        ->and($job->getRawOriginal('device_passcode'))->not->toBe($fields['device_passcode'])
        ->and($job->getRawOriginal('device_unlock_pattern'))->not->toContain('["1","2","5","8"]')
        ->and($job->toArray())->not->toHaveKeys(['device_passcode', 'device_unlock_pattern']);

    $receipt = $this->get(route('repairs.print', [$this->fix, $job]))
        ->assertOk()
        ->assertDontSee(trim($fields['device_passcode']))
        ->assertDontSee('<polyline', false);
    foreach (['customer_address', 'product', 'color', 'imei', 'serial_number', 'intake_terms', 'policy_number', 'device_condition', 'accessories'] as $key) {
        $receipt->assertSee($fields[$key]);
    }
    $receipt->assertSee('data-warranty-check', false);

    $unlockReceipt = route('repairs.print', [$this->fix, $job, 'show_unlock' => 1]);
    $this->get($unlockReceipt)
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertSee(trim($fields['device_passcode']))
        ->assertSee('<polyline', false);
    $this->actingAs($this->technician)->get($unlockReceipt)->assertForbidden();
});

test('invalid intake data does not retain device credentials as old form input', function () {
    $fields = array_replace($this->intake, [
        'imei' => 'abc', 'warranty_status' => 'unknown',
        'device_passcode' => 'test-no-flash', 'device_unlock_pattern' => '1215',
    ]);

    $this->actingAs($this->reception)->from(route('repairs.create', $this->fix))
        ->post(route('repairs.store', $this->fix), $fields)
        ->assertSessionHasErrors(['imei', 'device_unlock_pattern', 'warranty_status'])
        ->assertSessionMissing('_old_input.device_passcode')
        ->assertSessionMissing('_old_input.device_unlock_pattern');
    $this->assertDatabaseCount('repair_jobs', 0);
});

test('intake rejects missing required data', function () {
    $this->actingAs($this->reception)->from(route('repairs.create', $this->fix))
        ->post(route('repairs.store', $this->fix), ['submission_id' => $this->intake['submission_id']])
        ->assertRedirectToRoute('repairs.create', $this->fix)
        ->assertSessionHasErrors(['customer_name', 'customer_phone', 'device', 'issue']);

    $this->assertDatabaseCount('repair_jobs', 0);
});

test('technicians only see their assigned jobs and customer details stay at reception', function () {
    $job = $this->fix->repairs()->create($this->intake + ['created_by' => $this->reception->id]);
    $unassigned = $this->fix->repairs()->create(array_replace($this->intake, [
        'submission_id' => (string) Str::uuid(),
        'created_by' => $this->reception->id,
    ]));

    $this->actingAs($this->manager)
        ->patch(route('repairs.assign', [$this->fix, $job]), [
            'technician_id' => $this->technician->id,
            'assignment_version' => 0,
        ])->assertRedirectToRoute('repairs.show', [$this->fix, $job]);

    $job->refresh();
    expect($job->assigned_by)->toBe($this->manager->id)
        ->and($job->assigned_at)->not->toBeNull()
        ->and($job->assignment_version)->toBe(1);

    $this->actingAs($this->technician)
        ->get(route('repairs.index', $this->fix))
        ->assertOk()
        ->assertSee($job->reference())
        ->assertDontSee($unassigned->reference())
        ->assertDontSee('เปิดใบรับซ่อม');

    $this->get(route('repairs.show', [$this->fix, $job]))
        ->assertOk()->assertSee($job->issue)->assertDontSee($job->customer_phone)->assertDontSee($job->customer_name);
    $this->get(route('repairs.show', [$this->fix, $unassigned]))->assertNotFound();
    $this->get(route('repairs.print', [$this->fix, $job]))->assertForbidden();
    $this->get(route('repairs.create', $this->fix))->assertForbidden();
    $this->post(route('repairs.store', $this->fix), $this->intake)->assertForbidden();
    $this->patch(route('repairs.assign', [$this->fix, $job]), [
        'technician_id' => $this->technician->id,
        'assignment_version' => 1,
    ])->assertForbidden();
});

test('another branch or business cannot be accessed even with a repair id', function () {
    $job = $this->fix->repairs()->create($this->intake + ['created_by' => $this->reception->id]);

    $this->actingAs($this->reception)->get(route('repairs.index', $this->care))->assertNotFound();
    $this->get(route('repairs.create', $this->otherBranch))->assertNotFound();
    $this->post(route('repairs.store', $this->care), $this->intake)->assertNotFound();

    $this->actingAs($this->manager)->get(route('repairs.create', $this->care))->assertForbidden();
    $this->get(route('repairs.show', [$this->care, $job]))->assertNotFound();
    $this->get(route('repairs.print', [$this->care, $job]))->assertNotFound();
    $this->patch(route('repairs.assign', [$this->care, $job]), [
        'technician_id' => $this->otherTechnician->id,
        'assignment_version' => 0,
    ])->assertNotFound();

    $this->actingAs($this->otherTechnician)->get(route('repairs.show', [$this->fix, $job]))->assertNotFound();
});

test('staff cannot assign an out of branch technician or overwrite a newer assignment', function () {
    $job = $this->fix->repairs()->create($this->intake + ['created_by' => $this->reception->id]);
    $alternate = User::factory()->create();
    $this->fix->memberships()->create(['user_id' => $alternate->id, 'role' => BranchRole::Technician]);
    $url = route('repairs.assign', [$this->fix, $job]);

    $this->actingAs($this->reception)->patch($url, [
        'technician_id' => $this->otherTechnician->id,
        'assignment_version' => 0,
    ])->assertSessionHasErrors('technician_id');

    $this->patch($url, [
        'technician_id' => $this->technician->id,
        'assignment_version' => 0,
    ])->assertRedirectToRoute('repairs.show', [$this->fix, $job]);

    $this->patch($url, [
        'technician_id' => $this->technician->id,
        'assignment_version' => 0,
    ])->assertRedirectToRoute('repairs.show', [$this->fix, $job]);

    $this->patch($url, [
        'technician_id' => $alternate->id,
        'assignment_version' => 0,
    ])->assertSessionHasErrors('technician_id');

    expect($job->fresh()->technician_id)->toBe($this->technician->id)
        ->and($job->fresh()->assignment_version)->toBe(1);
});

test('branch setup and staff provisioning are scoped to organization and branch', function () {
    $arguments = [
        'organization' => 'endu', 'branch' => 'TEST',
        '--organization-name' => 'ENDU', '--branch-name' => 'Test branch',
    ];

    $this->artisan('endu:setup-branch', $arguments)->assertSuccessful();
    $this->artisan('endu:setup-branch', $arguments)->assertSuccessful();
    $branch = Branch::where('code', 'test')->sole();

    $this->artisan('endu:add-staff', [
        'organization' => 'endu', 'branch' => 'test',
        'email' => 'repair.demo@example.com', 'role' => 'technician', '--name' => 'Demo staff',
    ])->expectsQuestion('ตั้งรหัสผ่าน (อย่างน้อย 12 ตัวอักษร)', 'test-only-password')
        ->expectsQuestion('ยืนยันรหัสผ่าน', 'test-only-password')
        ->assertSuccessful();

    $user = User::where('email', 'repair.demo@example.com')->sole();
    expect($branch->roleFor($user))->toBe(BranchRole::Technician)
        ->and($this->fix->roleFor($user))->toBeNull();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'test-only-password',
    ])->assertRedirectToRoute('dashboard');
    $this->assertAuthenticatedAs($user);
});

test('staff provisioning explains password errors and leaves no partial account', function (string $password, string $confirmation, string $message) {
    $this->artisan('endu:add-staff', [
        'organization' => 'endu', 'branch' => 'fix',
        'email' => 'failed.demo@example.com', 'role' => 'reception', '--name' => 'Demo reception',
    ])->expectsQuestion('ตั้งรหัสผ่าน (อย่างน้อย 12 ตัวอักษร)', $password)
        ->expectsQuestion('ยืนยันรหัสผ่าน', $confirmation)
        ->expectsOutput($message)
        ->assertFailed();

    $this->assertDatabaseMissing('users', ['email' => 'failed.demo@example.com']);
})->with([
    'ASCII above the byte limit' => [str_repeat('a', 73), str_repeat('a', 73), 'รหัสผ่านยาวเกิน 72 ไบต์ กรุณาใช้รหัสผ่านที่สั้นลง'],
    'multibyte password above the byte limit' => [str_repeat('ก', 25), str_repeat('ก', 25), 'รหัสผ่านยาวเกิน 72 ไบต์ กรุณาใช้รหัสผ่านที่สั้นลง'],
    'confirmation mismatch' => ['demo-password-one', 'demo-password-two', 'รหัสผ่านกับคำยืนยันไม่ตรงกัน กรุณาลองใหม่'],
]);

test('staff provisioning accepts piped UTF-8 passwords with Windows line endings', function () {
    $password = ' รหัสทดสอบ-once ';
    $stream = fopen('php://temp', 'w+');
    fwrite($stream, "{$password}\r\n{$password}\r\n");
    rewind($stream);
    $input = new ArrayInput([
        'command' => 'endu:add-staff', 'organization' => 'endu', 'branch' => 'fix',
        'email' => 'piped.demo@example.com', 'role' => 'reception', '--name' => 'Demo reception',
        '--password-stdin' => true, '--no-interaction' => true,
    ]);
    $input->setStream($stream);
    $output = new BufferedOutput;

    try {
        expect(app(Kernel::class)->handle($input, $output))->toBe(0);
    } finally {
        fclose($stream);
    }

    $user = User::where('email', 'piped.demo@example.com')->sole();
    expect($this->fix->roleFor($user))->toBe(BranchRole::Reception)
        ->and(Hash::check($password, $user->password))->toBeTrue()
        ->and($output->fetch())->not->toContain($password);
});

test('technician and reception can complete a repair with a history of results and approvals', function () {
    $job = $this->fix->repairs()->create($this->intake + ['created_by' => $this->reception->id]);
    $show = route('repairs.show', [$this->fix, $job]);
    $change = route('repairs.change-status', [$this->fix, $job]);

    $this->actingAs($this->reception)
        ->get($show)->assertOk()->assertSee('รับเครื่องแล้ว')->assertDontSee('เริ่มตรวจเครื่อง');
    $this->patch(route('repairs.assign', [$this->fix, $job]), [
        'technician_id' => $this->technician->id,
        'assignment_version' => 0,
    ])->assertRedirect($show);

    $this->actingAs($this->technician)->get($show)->assertOk()->assertSee('เริ่มตรวจเครื่อง');
    foreach ([
        [$this->technician, RepairStatus::Diagnosing, null],
        [$this->technician, RepairStatus::AwaitingApproval, 'พบขั้วชาร์จเสีย'],
        [$this->reception, RepairStatus::Repairing, 'ลูกค้ายืนยันให้เปลี่ยนขั้วชาร์จ'],
        [$this->technician, RepairStatus::Ready, 'ชาร์จและทดสอบการเชื่อมต่อผ่าน'],
        [$this->manager, RepairStatus::Delivered, null],
    ] as $version => [$actor, $status, $note]) {
        $this->actingAs($actor)->patch($change, [
            'next_status' => $status->value,
            'workflow_version' => $version,
            'assignment_version' => 1,
            'workflow_note' => $note,
        ])->assertRedirect($show)->assertSessionHasNoErrors();
        expect($job->fresh()->status)->toBe($status);
        $this->assertDatabaseHas('repair_status_changes', [
            'repair_job_id' => $job->id, 'version' => $version + 1,
            'to_status' => $status->value, 'changed_by' => $actor->id, 'note' => $note,
        ]);
    }

    $this->get($show)->assertOk()->assertSee('ส่งมอบแล้ว')->assertSee('พบขั้วชาร์จเสีย')
        ->assertSee('ชาร์จและทดสอบการเชื่อมต่อผ่าน')->assertDontSee('บันทึกการมอบหมาย');
    $this->assertDatabaseCount('repair_status_changes', 5);
    $otherJob = $this->fix->repairs()->create(array_replace($this->intake, [
        'submission_id' => Str::uuid()->toString(), 'created_by' => $this->reception->id,
    ]));
    $this->get(route('repairs.index', [$this->fix, 'status' => 'delivered']))
        ->assertOk()->assertSee($job->reference())->assertDontSee($otherJob->reference());
});

test('workflow rejects skipped or stale updates and requires repair notes', function () {
    $job = $this->fix->repairs()->create($this->intake + ['created_by' => $this->reception->id]);
    $job->technician()->associate($this->technician);
    $job->save();
    $url = route('repairs.change-status', [$this->fix, $job]);
    $from = route('repairs.show', [$this->fix, $job]);
    $form = ['next_status' => 'diagnosing', 'workflow_version' => 0, 'assignment_version' => 0];

    $this->actingAs($this->reception)->patch($url, $form)->assertForbidden();
    $this->actingAs($this->otherTechnician)->patch($url, $form)->assertNotFound();
    $this->actingAs($this->technician)
        ->patch(route('repairs.change-status', [$this->care, $job]), $form)->assertNotFound();
    $this->patch($url, ['next_status' => 'ready'] + $form)->assertForbidden();
    $this->patch($url, $form)->assertRedirect($from)->assertSessionHasNoErrors();
    $this->from($from)->patch($url, $form)->assertSessionHasErrors('next_status');
    $this->from($from)->patch($url, ['next_status' => 'awaiting_approval', 'workflow_version' => 1] + $form)
        ->assertSessionHasErrors('workflow_note');
    $this->assertDatabaseCount('repair_status_changes', 1);
    expect($job->fresh()->status)->toBe(RepairStatus::Diagnosing);
});

test('reception can record return without repair and closed jobs cannot be reassigned or reopened', function () {
    $job = $this->fix->repairs()->create($this->intake + ['created_by' => $this->reception->id]);
    $job->technician()->associate($this->technician);
    $job->status = RepairStatus::AwaitingApproval;
    $job->save();
    $url = route('repairs.change-status', [$this->fix, $job]);
    $show = route('repairs.show', [$this->fix, $job]);
    $alternate = User::factory()->create();
    $this->fix->memberships()->create(['user_id' => $alternate->id, 'role' => BranchRole::Technician]);

    $this->actingAs($this->reception)->patch($url, [
        'next_status' => 'returned_unrepaired', 'workflow_version' => 0, 'assignment_version' => 0,
        'workflow_note' => 'ลูกค้าไม่อนุมัติและรับเครื่องกลับแล้ว',
    ])->assertRedirect($show);
    expect($job->fresh()->status)->toBe(RepairStatus::ReturnedUnrepaired);
    $this->from($show)->patch(route('repairs.assign', [$this->fix, $job]), [
        'technician_id' => $alternate->id, 'assignment_version' => 0,
    ])->assertSessionHasErrors('technician_id');
    $this->patch($url, [
        'next_status' => 'repairing', 'workflow_version' => 1, 'assignment_version' => 0,
        'workflow_note' => 'ลองเปิดงานใหม่',
    ])->assertForbidden();
    $this->assertDatabaseCount('repair_status_changes', 1);
});

test('the themed dashboard totals only repairs the current branch member is allowed to see', function () {
    $assigned = $this->fix->repairs()->create($this->intake + ['created_by' => $this->reception->id]);
    $assigned->technician()->associate($this->technician);
    $assigned->save();
    $this->fix->repairs()->create(array_replace($this->intake, [
        'created_by' => $this->reception->id,
        'submission_id' => Str::uuid()->toString(),
    ]));
    $closed = $this->fix->repairs()->create(array_replace($this->intake, [
        'created_by' => $this->reception->id,
        'submission_id' => Str::uuid()->toString(),
    ]));
    $closed->status = RepairStatus::Delivered;
    $closed->save();

    $this->actingAs($this->reception)->get(route('dashboard'))
        ->assertOk()->assertSee('data-open-repair-count>2', false);
    $this->get(route('dashboard', ['branch' => $this->care->id]))->assertNotFound();

    $this->actingAs($this->technician)->get(route('dashboard'))
        ->assertOk()->assertSee('data-open-repair-count>1', false)
        ->assertDontSee('เปิดใบรับซ่อม');

    $this->actingAs(User::factory()->create())->get(route('dashboard'))
        ->assertOk()->assertSee('ยังไม่มีสาขาที่คุณเข้าถึงได้');
});

test('repair reference search keeps branch and technician restrictions', function () {
    $job = $this->fix->repairs()->create($this->intake + ['created_by' => $this->reception->id]);
    $queue = route('repairs.index', [$this->fix, 'q' => strtolower($job->reference())]);

    $this->actingAs($this->reception)->get($queue)
        ->assertOk()->assertSee($job->reference());
    $this->actingAs($this->technician)->get($queue)
        ->assertOk()->assertSee('ไม่พบใบงานที่ตรงกับการค้นหา')->assertDontSee($job->reference());
    $this->actingAs($this->otherTechnician)->get($queue)->assertNotFound();
});

test('the phase one scope contains the exact full list and supports module filters and search', function () {
    $this->get(route('phase-one'))->assertRedirectToRoute('login');
    $this->actingAs($this->reception)->get(route('phase-one'))
        ->assertOk()->assertSee('148 ฟังก์ชันใน 18 หมวด');

    $repairs = $this->get(route('phase-one', ['module' => 'REP']))->assertOk();
    expect(substr_count($repairs->getContent(), 'class="endu-feature-id"'))->toBe(15);
    $repairs->assertSee('REP-01')->assertDontSee('POS-01');

    $this->get(route('phase-one', ['q' => 'doc-01']))->assertOk()
        ->assertSee('พิมพ์ใบรับงานซ่อม')->assertDontSee('DOC-02');
});
