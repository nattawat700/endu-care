<?php

use App\Enums\BranchRole;
use App\Enums\RepairStatus;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\RepairQuotation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->organization = Organization::create(['code' => 'endu', 'name' => 'ENDU']);
    $this->branch = $this->organization->branches()->create(['code' => 'fix', 'name' => 'ENDU FIX']);
    $this->otherBranch = $this->organization->branches()->create(['code' => 'care', 'name' => 'ENDU CARE']);
    $this->manager = User::factory()->create(['name' => 'ผู้จัดการตรวจสอบ']);
    $this->hq = User::factory()->create(['name' => 'HQ ตรวจสอบ']);
    $this->reception = User::factory()->create(['name' => 'หน้าร้านทดสอบ']);
    $this->tech = User::factory()->create();
    $this->cashier = User::factory()->create();
    foreach ([
        [$this->manager, BranchRole::Manager], [$this->hq, BranchRole::Hq], [$this->reception, BranchRole::Reception],
        [$this->tech, BranchRole::Technician], [$this->cashier, BranchRole::Cashier],
    ] as [$user, $role]) {
        $this->branch->memberships()->create(['user_id' => $user->id, 'role' => $role]);
    }
    $this->intake = [
        'customer_name' => 'ลูกค้าส่วนตัวทดสอบ', 'customer_phone' => '0891119927', 'customer_address' => 'ที่อยู่ส่วนตัวทดสอบ',
        'product' => 'มือถือ', 'device' => 'เครื่องทดสอบ', 'issue' => 'จอแตก', 'device_condition' => 'ใช้งานได้',
        'imei' => '356789123456731', 'serial_number' => 'PRIVATE-Z4WR', 'policy_number' => 'TEST-PRIVATE-POLICY',
        'device_passcode' => 'PRIVATE-PIN-TEST', 'device_unlock_pattern' => '1259', 'warranty_status' => 'covered',
        'submission_id' => (string) Str::uuid(),
    ];
});

test('repair audit stores the actor and before and after state while protecting the customer payload', function () {
    $this->travelTo(Carbon::parse('2026-11-24 00:06:00', 'Asia/Bangkok'));
    $this->actingAs($this->reception)->post(route('repairs.store', $this->branch), $this->intake)->assertSessionHasNoErrors();
    $this->post(route('repairs.store', $this->branch), $this->intake)->assertSessionHasNoErrors();
    $ticket = $this->branch->repairs()->sole();
    $this->patch(route('repairs.assign', [$this->branch, $ticket]), [
        'technician_id' => $this->tech->id, 'assignment_version' => 0,
    ])->assertSessionHasNoErrors();
    $this->actingAs($this->tech)->patch(route('repairs.change-status', [$this->branch, $ticket]), [
        'next_status' => 'diagnosing', 'assignment_version' => 1, 'workflow_version' => 0,
    ])->assertSessionHasNoErrors();

    $events = AuditEvent::where('subject_type', 'repair')->where('subject_reference', $ticket->reference())->orderBy('id')->get();
    expect($events)->toHaveCount(3);
    expect($events[0]->actor_name)->toBe('หน้าร้านทดสอบ')->and($events[0]->after_values['status'])->toBe('received');
    expect($events[1]->before_values['technician_id'])->toBeNull()->and($events[1]->after_values['technician_id'])->toBe($this->tech->id);
    expect($events[2]->before_values['status'])->toBe('received')->and($events[2]->after_values['status'])->toBe('diagnosing');
    expect($events[2]->occurred_at->timezone('Asia/Bangkok')->format('Y-m-d H:i:s'))->toBe('2026-11-24 00:06:00');
    expect($events->toJson())->not->toContain($this->intake['customer_phone'], $this->intake['imei'], $this->intake['device_passcode'], $this->intake['customer_name']);
    expect(fn () => $events[0]->update(['actor_name' => 'เปลี่ยนย้อนหลัง']))->toThrow(LogicException::class);
    expect(fn () => $events[0]->delete())->toThrow(LogicException::class);

    $audit = route('audit.index');
    $this->get($audit)->assertForbidden();
    $this->actingAs($this->manager)->get(route('audit.index', [
        'branch' => $this->branch->id, 'reference' => strtolower($ticket->reference()), 'actor' => 'หน้าร้าน',
        'from' => '2026-11-24', 'to' => '2026-11-24',
    ]))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertSee($ticket->reference())
        ->assertSee('ดูค่าก่อนและหลัง')->assertSee('หน้าร้านทดสอบ')->assertDontSee($this->intake['customer_phone']);
    $this->get(route('audit.index', ['reference' => $ticket->reference(), 'to' => '2026-11-23']))
        ->assertOk()->assertSee('ไม่พบประวัติที่ตรงกับตัวกรอง');
});

test('audit results distinguish branch access from organization settings access', function () {
    $this->actingAs($this->hq)->patch(route('organizations.update', $this->organization), [
        'name' => 'ENDU ปรับข้อมูล', 'operator_type' => 'business',
    ])->assertSessionHasNoErrors();
    $foreign = Organization::create(['code' => 'foreign', 'name' => 'กิจการลับอีกแห่ง']);
    $foreign->branches()->create(['code' => 'desk', 'name' => 'สาขาลับอีกแห่ง']);
    $this->otherBranch->update(['name' => 'ชื่อสาขาอื่นที่ปิดบัง']);

    $this->get(route('audit.index', ['subject' => 'organization']))->assertOk()
        ->assertSee('ORG-'.$this->organization->id)->assertDontSee('กิจการลับอีกแห่ง');
    $this->get(route('audit.index', ['subject' => 'branch']))->assertOk()->assertDontSee('ชื่อสาขาอื่นที่ปิดบัง');
    $this->actingAs($this->manager)->get(route('audit.index', ['subject' => 'organization']))->assertOk()
        ->assertSee('ไม่พบประวัติที่ตรงกับตัวกรอง');
    $this->get(route('audit.index', ['branch' => $this->otherBranch->id]))->assertNotFound();
});

test('audit is transactional and a failed audit append does not leave a partially created repair', function () {
    Event::listen('eloquent.creating: '.AuditEvent::class, function (AuditEvent $event): void {
        if ($event->subject_type === 'repair') {
            throw new RuntimeException('synthetic audit persistence failure');
        }
    });

    $this->actingAs($this->reception)->post(route('repairs.store', $this->branch), $this->intake)->assertServerError();
    $this->assertDatabaseCount('repair_jobs', 0);
    $this->assertDatabaseMissing('audit_events', ['subject_type' => 'repair']);
});

test('repair screens and exports expose only the fields intended for the role', function () {
    $this->intake['customer_phone'] = '089-111-(9927)';
    $this->actingAs($this->reception)->post(route('repairs.store', $this->branch), $this->intake)->assertSessionHasNoErrors();
    $ticket = $this->branch->repairs()->sole();
    $ticket->technician_id = $this->tech->id;
    $ticket->save();
    $page = route('repairs.show', [$this->branch, $ticket]);
    $unmasked = [$this->intake['customer_phone'], $this->intake['customer_address'], $this->intake['imei'], $this->intake['serial_number'], $this->intake['policy_number']];

    $this->get($page)->assertOk()->assertSeeTextInOrder($unmasked)->assertDontSee($this->intake['device_passcode']);
    $this->actingAs($this->cashier)->get($page)->assertOk()
        ->assertSee('•••• 9927')->assertSee($this->intake['customer_name'])->assertDontSee($this->intake['device_passcode']);
    foreach ($unmasked as $privateValue) {
        $this->get($page)->assertDontSee($privateValue);
    }
    $this->get(route('repairs.print', [$this->branch, $ticket, 'show_unlock' => 1]))->assertForbidden();

    $this->actingAs($this->tech)->get($page)->assertOk()
        ->assertSee('•••• 6731')->assertSee('•••• Z4WR')
        ->assertDontSee($this->intake['imei'])->assertDontSee($this->intake['customer_phone'])->assertDontSee($this->intake['customer_name']);
    $this->get(route('repairs.export', $this->branch))->assertForbidden();

    $csv = $this->actingAs($this->manager)->get(route('repairs.export', $this->branch))->assertDownload()->streamedContent();
    expect($csv)->toContain($ticket->reference())->not->toContain(...$unmasked)
        ->not->toContain($this->intake['customer_name'], $this->intake['device_passcode']);
});

test('quotation approvals are audited and internal notes remain hidden from cashiers', function () {
    $this->actingAs($this->reception)->post(route('repairs.store', $this->branch), $this->intake)->assertSessionHasNoErrors();
    $ticket = $this->branch->repairs()->sole();
    $ticket->status = RepairStatus::AwaitingApproval;
    $ticket->save();
    $this->post(route('repairs.quotations.store', [$this->branch, $ticket]), [
        'submission_id' => (string) Str::uuid(), 'base_revision' => 0, 'terms' => 'รับประกันตามใบเสนอราคา',
        'items' => [['type' => 'labor', 'description' => 'ค่าแรง', 'quantity' => 1, 'unit_price' => '500.00']],
    ])->assertSessionHasNoErrors();
    $quote = RepairQuotation::sole();
    $parameters = [$this->branch, $ticket, $quote];
    $this->post(route('repairs.quotations.submit-review', $parameters), [
        'request_reason' => 'คำขอภายในเฉพาะผู้ดูแล',
    ])->assertSessionHasNoErrors();
    $this->actingAs($this->manager)->patch(route('repairs.quotations.decide-review', $parameters), [
        'decision' => 'approved', 'decision_reason' => 'เหตุผลเฉพาะผู้ดูแล',
    ])->assertSessionHasNoErrors();
    $show = route('repairs.quotations.show', $parameters);
    $this->get($show)->assertOk()->assertSee('คำขอภายในเฉพาะผู้ดูแล')->assertSee('เหตุผลเฉพาะผู้ดูแล');
    $this->actingAs($this->cashier)->get($show)->assertOk()->assertSee('500.00 บาท')
        ->assertDontSee('คำขอภายในเฉพาะผู้ดูแล')->assertDontSee('เหตุผลเฉพาะผู้ดูแล');

    $events = AuditEvent::where('subject_reference', $quote->reference())->orderBy('id')->get();
    expect($events)->toHaveCount(3);
    expect($events->last()->after_values['internal_decision'])->toBe('approved')
        ->and($events->last()->before_values['internal_decision'])->toBeNull()
        ->and($events->last()->actor_name)->toBe('ผู้จัดการตรวจสอบ');
    expect($events->toJson())->not->toContain('คำขอภายในเฉพาะผู้ดูแล', 'เหตุผลเฉพาะผู้ดูแล', $this->intake['customer_name']);
});

test('branch role changes and staff suspension append prior state without storing credentials', function () {
    $membership = $this->branch->memberships()->whereBelongsTo($this->reception)->sole();
    $parameters = [$this->branch, $membership];

    $this->actingAs($this->manager)->patch(route('staff.change-role', $parameters), ['role' => 'cashier'])->assertSessionHasNoErrors();
    $this->patch(route('staff.account', $parameters), ['action' => 'suspend'])->assertSessionHasNoErrors();

    $events = AuditEvent::where('subject_reference', 'STAFF-'.$this->reception->id)->orderBy('id')->get();
    expect($events)->toHaveCount(3);
    expect($events[1]->before_values)->toBe(['role' => 'reception'])->and($events[1]->after_values)->toBe(['role' => 'cashier']);
    expect($events[2]->operation)->toBe('suspend')
        ->and($events[2]->before_values['suspended_at'])->toBeNull()
        ->and($events[2]->after_values['suspended_at'])->not->toBeNull();
    expect($events->toJson())->not->toContain($this->reception->email, $this->reception->password);
});
