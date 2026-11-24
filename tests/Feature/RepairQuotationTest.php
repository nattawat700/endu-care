<?php

use App\Enums\BranchRole;
use App\Enums\QuotationDecision;
use App\Enums\RepairStatus;
use App\Models\Organization;
use App\Models\RepairQuotation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $organization = Organization::create(['code' => 'endu', 'name' => 'ENDU']);
    $this->branch = $organization->branches()->create(['code' => 'fix', 'name' => 'ENDU FIX']);
    $this->otherBranch = $organization->branches()->create(['code' => 'bkp', 'name' => 'บางกะปิ']);
    $this->reception = User::factory()->create();
    $this->manager = User::factory()->create();
    $this->technician = User::factory()->create();
    $this->otherReception = User::factory()->create();

    $this->branch->memberships()->create(['user_id' => $this->reception->id, 'role' => BranchRole::Reception]);
    $this->branch->memberships()->create(['user_id' => $this->manager->id, 'role' => BranchRole::Manager]);
    $this->branch->memberships()->create(['user_id' => $this->technician->id, 'role' => BranchRole::Technician]);
    $this->otherBranch->memberships()->create(['user_id' => $this->otherReception->id, 'role' => BranchRole::Reception]);
    $this->intake = [
        'created_by' => $this->reception->id,
        'submission_id' => (string) Str::uuid(),
        'customer_name' => 'ลูกค้าทดสอบ',
        'customer_phone' => '0800000000',
        'device' => 'iPhone 13',
        'issue' => 'ชาร์จไม่เข้า',
    ];
    $this->job = $this->branch->repairs()->create($this->intake);
    $this->job->status = RepairStatus::AwaitingApproval;
    $this->job->technician()->associate($this->technician);
    $this->job->save();
    $this->fields = [
        'base_revision' => 0,
        'submission_id' => (string) Str::uuid(),
        'terms' => 'รับประกันงานซ่อม 30 วันตามเงื่อนไขที่แจ้ง',
        'items' => [
            ['type' => 'labor', 'description' => 'ค่าแรงเปลี่ยนพอร์ตชาร์จ', 'quantity' => 1, 'unit_price' => '350.50'],
            ['type' => 'part', 'description' => 'อะไหล่พอร์ตชาร์จ', 'quantity' => 2, 'unit_price' => '245.25'],
        ],
    ];
    $this->store = route('repairs.quotations.store', [$this->branch, $this->job]);

    $this->authorizeRelease = function (RepairQuotation $quote): void {
        $url = [$this->branch, $this->job, $quote];
        $this->actingAs($this->reception)->post(route('repairs.quotations.submit-review', $url), [
            'request_reason' => 'ขออนุมัติราคางานซ่อม',
        ])->assertRedirect(route('repairs.quotations.show', $url));
        $this->actingAs($this->manager)->patch(route('repairs.quotations.decide-review', $url), [
            'decision' => 'approved', 'decision_reason' => 'ตรวจราคาและเงื่อนไขแล้ว',
        ])->assertRedirect(route('repairs.quotations.show', $url));
    };
});

test('front staff can open a quotation from the repair and issue a printable itemized document', function () {
    $this->actingAs($this->reception)
        ->get(route('repairs.show', [$this->branch, $this->job]))
        ->assertOk()->assertSee('ใบเสนอราคา');
    $this->get(route('repairs.quotations.create', [$this->branch, $this->job]))->assertOk();
    $created = $this->post($this->store, $this->fields + ['total_satang' => 1]);
    $quote = RepairQuotation::sole();
    $show = route('repairs.quotations.show', [$this->branch, $this->job, $quote]);
    $created->assertRedirect($show);
    expect($quote->total_satang)->toBe(84100)
        ->and($quote->items[1]['total_satang'])->toBe(49050)
        ->and($quote->revision)->toBe(1);

    $this->get($show)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertSee($quote->reference())->assertSee('841.00 บาท')
        ->assertSee('ค่าแรงเปลี่ยนพอร์ตชาร์จ')->assertSee('อะไหล่พอร์ตชาร์จ')
        ->assertSee($this->fields['terms'])->assertDontSee('พิมพ์ใบเสนอราคา');
    $this->post($this->store, $this->fields)->assertRedirect($show);
    $this->assertDatabaseCount('repair_quotations', 1);
    ($this->authorizeRelease)($quote);
    $this->get($show)->assertOk()->assertSee('พิมพ์ใบเสนอราคา');
});

test('pricing validation rejects malformed amounts and stale issue forms', function () {
    $this->actingAs($this->manager);
    $bad = $this->fields;
    $bad['items'][0]['unit_price'] = '0.001';
    $this->post($this->store, $bad)->assertSessionHasErrors('items.0.unit_price');
    $this->assertDatabaseCount('repair_quotations', 0);

    $this->post($this->store, $this->fields)->assertSessionHasNoErrors();
    $this->post($this->store, ['submission_id' => (string) Str::uuid()] + $this->fields)
        ->assertSessionHasErrors('base_revision');
    $this->assertDatabaseCount('repair_quotations', 1);
});

test('approval binds to an immutable revision and a revised price requires confirmation again', function () {
    $this->actingAs($this->reception)->post($this->store, $this->fields)->assertSessionHasNoErrors();
    $first = RepairQuotation::sole();
    $approveFirst = route('repairs.quotations.approve', [$this->branch, $this->job, $first]);
    $approval = ['approval_channel' => 'phone', 'approval_note' => 'ลูกค้ายืนยันทางโทรศัพท์', 'confirmation' => '1'];

    $this->patch(route('repairs.change-status', [$this->branch, $this->job]), [
        'next_status' => 'repairing', 'workflow_version' => 0, 'assignment_version' => 0,
        'workflow_note' => 'เริ่มซ่อม',
    ])->assertSessionHasErrors('next_status');
    $this->patch($approveFirst, ['approval_channel' => 'phone'])->assertSessionHasErrors('confirmation');
    $this->patch($approveFirst, $approval)->assertSessionHasErrors('confirmation');
    ($this->authorizeRelease)($first);
    $this->actingAs($this->reception);
    $this->patch($approveFirst, $approval)->assertSessionHasNoErrors();
    $this->patch($approveFirst, $approval)->assertSessionHasNoErrors();
    expect($first->fresh()->approved_by)->toBe($this->reception->id)
        ->and($first->fresh()->approval_channel)->toBe('phone')
        ->and($this->job->fresh()->status)->toBe(RepairStatus::Repairing);
    $this->assertDatabaseCount('repair_status_changes', 1);

    $revision = [
        'base_revision' => 1, 'submission_id' => (string) Str::uuid(), 'terms' => 'ข้อตกลงฉบับแก้',
    ] + $this->fields;
    $revision['items'][1]['unit_price'] = '250.00';
    $this->actingAs($this->manager)->post($this->store, $revision)->assertSessionHasNoErrors();
    $latest = $this->job->quotations()->orderByDesc('revision')->firstOrFail();
    expect($latest->revision)->toBe(2)
        ->and($latest->approved_at)->toBeNull()
        ->and($latest->total_satang)->toBe(85050)
        ->and($first->fresh()->total_satang)->toBe(84100)
        ->and($first->fresh()->terms)->toBe($this->fields['terms'])
        ->and($this->job->fresh()->status)->toBe(RepairStatus::AwaitingApproval);

    $this->patch($approveFirst, $approval)->assertSessionHasErrors('confirmation');
    $this->get(route('repairs.quotations.show', [$this->branch, $this->job, $first]))
        ->assertOk()->assertSee('ฉบับก่อนหน้า')->assertSee('ลูกค้ายืนยันฉบับนี้แล้ว')->assertSee($this->fields['terms']);
    $this->get(route('repairs.quotations.index', [$this->branch, $this->job]))
        ->assertOk()->assertSee($first->reference())->assertSee($latest->reference());
    ($this->authorizeRelease)($latest);
    $this->patch(route('repairs.quotations.approve', [$this->branch, $this->job, $latest]), $approval)
        ->assertSessionHasNoErrors();
    expect($latest->fresh()->approved_by)->toBe($this->manager->id)
        ->and($this->job->fresh()->status)->toBe(RepairStatus::Repairing);
    $this->assertDatabaseCount('repair_status_changes', 3);
});

test('quotation routes enforce the branch role and quoted repair identity', function () {
    $this->actingAs($this->reception)->post($this->store, $this->fields)->assertSessionHasNoErrors();
    $quote = RepairQuotation::sole();
    $otherJob = $this->branch->repairs()->create(['submission_id' => (string) Str::uuid()] + $this->intake);
    $show = route('repairs.quotations.show', [$this->branch, $this->job, $quote]);
    $this->get(route('repairs.quotations.show', [$this->branch, $otherJob, $quote]))->assertNotFound();

    $this->actingAs($this->technician)->get($show)->assertForbidden();
    $this->post($this->store, $this->fields)->assertForbidden();
    $this->patch(route('repairs.quotations.approve', [$this->branch, $this->job, $quote]), [
        'confirmation' => '1', 'approval_channel' => 'counter',
    ])->assertForbidden();

    $this->actingAs($this->otherReception)->get($show)->assertNotFound();
    $this->get(route('repairs.quotations.show', [$this->otherBranch, $this->job, $quote]))->assertNotFound();

    $this->job->status = RepairStatus::Delivered;
    $this->job->save();
    $this->actingAs($this->manager)->get($show)->assertOk();
    $this->post($this->store, ['base_revision' => 1, 'submission_id' => (string) Str::uuid()] + $this->fields)->assertConflict();
});

test('internal approval records the original requester and one reasoned decision from a branch manager', function () {
    $this->actingAs($this->reception)->post($this->store, $this->fields)->assertSessionHasNoErrors();
    $quote = RepairQuotation::sole();
    $args = [$this->branch, $this->job, $quote];
    $submit = route('repairs.quotations.submit-review', $args);
    $decide = route('repairs.quotations.decide-review', $args);
    $show = route('repairs.quotations.show', $args);
    $queue = route('repairs.quotations.review-queue', $this->branch);
    $decision = ['decision' => 'approved', 'decision_reason' => 'ตรวจสอบรายการอะไหล่แล้ว'];
    $this->assertTrue($quote->requires_internal_approval);
    $this->get($queue)->assertForbidden();
    $this->patch($decide, $decision)->assertForbidden();
    $this->post($submit, ['request_reason' => '  '])->assertSessionHasErrors('request_reason');
    $this->post($submit, ['request_reason' => 'รบกวนตรวจราคาก่อนแจ้งลูกค้า'])->assertRedirect($show);
    $this->post($submit, ['request_reason' => 'ลองเปลี่ยนเหตุผล'])->assertRedirect($show);
    expect($quote->fresh()->internal_requested_by)->toBe($this->reception->id)
        ->and($quote->fresh()->internal_request_reason)->toBe('รบกวนตรวจราคาก่อนแจ้งลูกค้า')
        ->and($quote->fresh()->internal_requested_at)->not->toBeNull();
    $this->get($show)->assertOk()->assertSee('รอผู้จัดการอนุมัติ')->assertDontSee('พิมพ์ใบเสนอราคา');

    $this->actingAs($this->otherReception)->patch($decide, $decision)->assertNotFound();
    $this->actingAs($this->manager)->get($queue)->assertOk()->assertSee($quote->reference());
    $this->patch($decide, ['decision' => 'approved'])->assertSessionHasErrors('decision_reason');
    $this->patch($decide, $decision)->assertRedirect($show);
    $this->patch($decide, ['decision' => 'rejected', 'decision_reason' => 'ลองกลับผล'])->assertSessionHasErrors('review');

    $quote->refresh();
    expect($quote->internal_decision)->toBe(QuotationDecision::Approved)
        ->and($quote->internal_decided_by)->toBe($this->manager->id)
        ->and($quote->internal_decision_reason)->toBe($decision['decision_reason'])
        ->and($quote->internal_decided_at)->not->toBeNull()
        ->and($quote->approved_at)->toBeNull()
        ->and($this->job->fresh()->status)->toBe(RepairStatus::AwaitingApproval);
    $this->get($queue)->assertOk()->assertDontSee($quote->reference());

    $this->actingAs($this->reception)->get($show)->assertOk()
        ->assertSee('ผู้จัดการอนุมัติแล้ว')->assertSee($decision['decision_reason'])
        ->assertSee('พิมพ์ใบเสนอราคา')->assertSee('บันทึกการยืนยันของลูกค้า');
});

test('rejection and replacing a pending revision preserve old audit details and cannot authorize a new quote', function () {
    $this->actingAs($this->reception)->post($this->store, $this->fields)->assertSessionHasNoErrors();
    $first = RepairQuotation::sole();
    $firstArgs = [$this->branch, $this->job, $first];
    $firstDecision = route('repairs.quotations.decide-review', $firstArgs);
    $reason = ['decision' => 'rejected', 'decision_reason' => 'เปลี่ยนสเปกอะไหล่ตามที่ตกลง'];
    $this->actingAs($this->manager)->patch($firstDecision, $reason)->assertSessionHasErrors('review');
    $this->actingAs($this->reception)->post(route('repairs.quotations.submit-review', $firstArgs), [
        'request_reason' => 'ฉบับก่อนเปลี่ยนสเปก',
    ])->assertSessionHasNoErrors();
    $this->actingAs($this->manager)->patch($firstDecision, $reason)->assertSessionHasNoErrors();
    $this->actingAs($this->reception)->get(route('repairs.quotations.show', $firstArgs))
        ->assertOk()->assertSee('ผู้จัดการไม่อนุมัติ')->assertDontSee('พิมพ์ใบเสนอราคา');
    $this->patch(route('repairs.quotations.approve', $firstArgs), [
        'approval_channel' => 'counter', 'confirmation' => '1',
    ])->assertSessionHasErrors('confirmation');

    $this->post($this->store, ['base_revision' => 1, 'submission_id' => (string) Str::uuid()] + $this->fields);
    $second = $this->job->quotations()->orderByDesc('revision')->firstOrFail();
    $secondArgs = [$this->branch, $this->job, $second];
    $this->post(route('repairs.quotations.submit-review', $secondArgs), [
        'request_reason' => 'ตรวจสเปกใหม่',
    ])->assertSessionHasNoErrors();
    $this->post($this->store, ['base_revision' => 2, 'submission_id' => (string) Str::uuid()] + $this->fields)
        ->assertSessionHasNoErrors();

    $this->actingAs($this->manager)->get(route('repairs.quotations.review-queue', $this->branch))
        ->assertOk()->assertDontSee($second->reference());
    $this->patch(route('repairs.quotations.decide-review', $secondArgs), $reason)->assertSessionHasErrors('review');
    expect($first->fresh()->internal_decision_reason)->toBe($reason['decision_reason'])
        ->and($second->fresh()->internal_requested_at)->not->toBeNull()
        ->and($second->fresh()->internal_decision)->toBeNull()
        ->and($this->job->quotations()->orderByDesc('revision')->firstOrFail()->canRelease())->toBeFalse();
});

test('quotations issued before the internal approval workflow keep their previous behavior', function () {
    $this->actingAs($this->reception)->post($this->store, $this->fields)->assertSessionHasNoErrors();
    $quote = RepairQuotation::sole();
    $quote->requires_internal_approval = false;
    $quote->save();
    $args = [$this->branch, $this->job, $quote];
    $this->get(route('repairs.quotations.show', $args))->assertOk()
        ->assertSee('ออกก่อนเริ่มขั้นตอนอนุมัติ')->assertSee('พิมพ์ใบเสนอราคา');
    $this->patch(route('repairs.quotations.approve', $args), [
        'approval_channel' => 'counter', 'confirmation' => '1',
    ])->assertSessionHasNoErrors();
    expect($quote->fresh()->approved_at)->not->toBeNull();
});

test('front staff records the customer request and confirmation against the same quotation revision', function () {
    $this->travelTo(now()->parse('2026-11-24 02:00:00', 'UTC'));
    $this->actingAs($this->reception)->post($this->store, $this->fields)->assertSessionHasNoErrors();
    $quote = RepairQuotation::sole();
    $parameters = [$this->branch, $this->job, $quote];
    $show = route('repairs.quotations.show', $parameters);
    $request = route('repairs.quotations.request-customer-approval', $parameters);
    $confirm = route('repairs.quotations.approve', $parameters);

    ($this->authorizeRelease)($quote);
    $this->travel(1)->hour();
    $this->actingAs($this->reception)->get(route('repairs.show', [$this->branch, $this->job]))
        ->assertOk()->assertSee($show.'#customer-approval', false);
    $this->get($show)->assertOk()->assertSee('บันทึกการส่งขออนุมัติ');

    $this->post($request, ['request_channel' => 'line'])->assertRedirect($show);
    $this->get(route('repairs.quotations.index', [$this->branch, $this->job]))
        ->assertOk()->assertSee('ส่งแล้ว · รอลูกค้ายืนยัน');
    expect($this->job->fresh()->status)->toBe(RepairStatus::AwaitingApproval);

    $this->travel(30)->minutes();
    $this->actingAs($this->manager)->post($request, ['request_channel' => 'counter'])->assertRedirect($show);
    $this->post($request, ['request_channel' => 'phone'])->assertRedirect($show);

    $confirmation = [
        'approval_channel' => 'phone',
        'customer_approver_name' => 'คุณมะลิ & ผู้รับเครื่อง',
        'approval_received_at' => '2026-11-24T10:15',
        'confirmation' => '1',
    ];
    $this->patch($confirm, $confirmation)->assertSessionHasNoErrors()->assertRedirect($show);
    $this->actingAs($this->reception)->patch($confirm, ['approval_channel' => 'counter'] + $confirmation)->assertRedirect($show);

    $quote->refresh();
    expect($quote->customer_request_channel)->toBe('line')
        ->and($quote->customer_requested_by)->toBe($this->reception->id)
        ->and($quote->customer_requested_at->format('Y-m-d H:i:s'))->toBe('2026-11-24 03:00:00')
        ->and($quote->approval_channel)->toBe('phone')
        ->and($quote->customer_approver_name)->toBe('คุณมะลิ & ผู้รับเครื่อง')
        ->and($quote->customer_confirmed_at->format('Y-m-d H:i:s'))->toBe('2026-11-24 03:15:00')
        ->and($quote->approved_by)->toBe($this->manager->id)
        ->and($quote->approved_at->format('Y-m-d H:i:s'))->toBe('2026-11-24 03:30:00');
    expect($this->job->fresh()->status)->toBe(RepairStatus::Repairing);
    $this->assertDatabaseCount('repair_status_changes', 1);
    $this->get($show)->assertOk()->assertSee('คุณมะลิ & ผู้รับเครื่อง')
        ->assertSee('24/11/2026 10:15')->assertSee('24/11/2026 10:30')->assertSee('ลูกค้ายืนยันฉบับนี้แล้ว');

    $this->post($this->store, ['base_revision' => 1, 'submission_id' => (string) Str::uuid()] + $this->fields)
        ->assertSessionHasNoErrors();
    $revised = $this->job->quotations()->where('revision', 2)->sole();
    expect($revised->customer_requested_at)->toBeNull()
        ->and($revised->customer_confirmed_at)->toBeNull();
    $this->post($request, ['request_channel' => 'line'])->assertSessionHasErrors('request_channel');
    $this->get($show)->assertOk()->assertSee('ฉบับก่อนหน้า')->assertSee('คุณมะลิ & ผู้รับเครื่อง');
});

test('customer approval requests are limited to staff in the branch and eligible quotations', function () {
    $this->actingAs($this->reception)->post($this->store, $this->fields)->assertSessionHasNoErrors();
    $quote = RepairQuotation::sole();
    $request = route('repairs.quotations.request-customer-approval', [$this->branch, $this->job, $quote]);
    $payload = ['request_channel' => 'line'];

    $this->actingAs($this->otherReception)->post($request, $payload)->assertNotFound();
    $this->actingAs($this->technician)->post($request, $payload)->assertForbidden();
    $this->actingAs($this->reception)->post($request, $payload)->assertSessionHasErrors('request_channel');
    ($this->authorizeRelease)($quote);
    $this->actingAs($this->reception)->post($request, ['request_channel' => 'invalid'])->assertSessionHasErrors('request_channel');

    $this->job->status = RepairStatus::Diagnosing;
    $this->job->save();
    $this->post($request, $payload)->assertSessionHasErrors('request_channel');
    expect($quote->fresh()->customer_requested_at)->toBeNull();
    $this->assertDatabaseCount('repair_status_changes', 0);
});

test('customer confirmation validates when the quotation was accepted', function () {
    $this->travelTo(now()->parse('2026-11-24 02:00:00', 'UTC'));
    $this->actingAs($this->reception)->post($this->store, $this->fields)->assertSessionHasNoErrors();
    $quote = RepairQuotation::sole();
    $parameters = [$this->branch, $this->job, $quote];
    ($this->authorizeRelease)($quote);
    $confirm = route('repairs.quotations.approve', $parameters);
    $payload = ['approval_channel' => 'counter', 'customer_approver_name' => 'คุณดาว', 'confirmation' => '1'];

    $this->actingAs($this->reception);
    foreach (['2026-11-24T08:59', '2026-11-24T09:01', '2026-11-31T09:00'] as $invalidTime) {
        $this->patch($confirm, ['approval_received_at' => $invalidTime] + $payload)->assertSessionHasErrors('approval_received_at');
    }
    $this->patch($confirm, ['customer_approver_name' => ' '] + $payload)->assertSessionHasErrors('customer_approver_name');
    expect($quote->fresh()->approved_at)->toBeNull();
    $this->assertDatabaseCount('repair_status_changes', 0);

    $this->patch($confirm, $payload)->assertSessionHasNoErrors()->assertRedirect(route('repairs.quotations.show', $parameters));
    $quote->refresh();
    expect($quote->customer_confirmed_at->format('Y-m-d H:i:s'))->toBe('2026-11-24 02:00:00')
        ->and($quote->customer_requested_at)->toBeNull();
});

test('additional work pauses an approved repair until the customer confirms a quote based on the updated assessment', function () {
    $this->actingAs($this->reception)->post($this->store, $this->fields)->assertSessionHasNoErrors();
    $first = RepairQuotation::sole();
    $repairRoutes = [$this->branch, $this->job];
    $approval = ['confirmation' => '1', 'approval_channel' => 'phone'];
    $oldApproval = route('repairs.quotations.approve', [...$repairRoutes, $first]);
    ($this->authorizeRelease)($first);
    $this->actingAs($this->reception)->patch($oldApproval, $approval)->assertSessionHasNoErrors();

    $assessmentRoute = route('repairs.assessment.create', $repairRoutes);
    $assessmentStore = route('repairs.assessment.store', $repairRoutes);
    $jobPage = route('repairs.show', $repairRoutes);
    $this->actingAs($this->technician)->get($assessmentRoute)->assertOk()->assertSee('แจ้งงานเพิ่มและพักงานซ่อม');
    $items = [...$this->fields['items'], ['type' => 'part', 'description' => 'สายแพเพิ่มเติม', 'quantity' => 1, 'unit_price' => '250.00']];
    $assessmentFields = [
        'submission_id' => (string) Str::uuid(),
        'assessment_revision' => 0,
        'assignment_version' => 0,
        'workflow_version' => 1,
        'cause' => 'พบสายแพเสียขณะทดสอบ',
        'repair_plan' => 'เพิ่มรายการเปลี่ยนสายแพ',
        'expected_completion_at' => '2026-11-25T11:00',
        'request_price_change' => '1',
        'items' => $items,
    ];

    $this->actingAs($this->reception)->post($assessmentStore, $assessmentFields)->assertForbidden();
    $this->actingAs($this->technician)->post($assessmentStore, $assessmentFields)->assertSessionHasNoErrors()->assertRedirect($jobPage);
    $this->post($assessmentStore, $assessmentFields)->assertRedirect($jobPage);
    $job = $this->job->fresh();
    expect($job->status)->toBe(RepairStatus::AwaitingApproval)
        ->and($job->price_change_assessment_id)->toBe($job->assessments()->sole()->id);
    $this->assertDatabaseCount('repair_status_changes', 2);
    $this->get($jobPage)->assertOk()->assertSee('พักงานรออนุมัติราคาใหม่')->assertDontSee('จัดทำใบเสนอราคางานเพิ่ม');
    $this->patch(route('repairs.change-status', $repairRoutes), [
        'next_status' => 'ready', 'workflow_version' => 2, 'assignment_version' => 0, 'workflow_note' => 'ทดสอบแล้ว',
    ])->assertForbidden();

    $this->actingAs($this->reception)->get($jobPage)->assertOk()->assertSee('จัดทำใบเสนอราคางานเพิ่ม');
    $this->patch($oldApproval, $approval)->assertSessionHasErrors('confirmation');
    $this->post(route('repairs.quotations.request-customer-approval', [...$repairRoutes, $first]), [
        'request_channel' => 'phone',
    ])->assertSessionHasErrors('request_channel');
    $this->patch(route('repairs.change-status', $repairRoutes), [
        'next_status' => 'repairing', 'workflow_version' => 2, 'assignment_version' => 0, 'workflow_note' => 'ขอให้ทำต่อ',
    ])->assertSessionHasErrors('next_status');

    $newQuote = [
        'submission_id' => (string) Str::uuid(),
        'base_revision' => 1,
        'revision_reason' => 'พบสายแพเสียเพิ่มเติม',
        'items' => $items,
    ] + $this->fields;
    $this->post($this->store, $newQuote)->assertSessionHasErrors('assessment_revision');
    $this->assertDatabaseCount('repair_quotations', 1);
    $this->flushSession()->get(route('repairs.quotations.create', $repairRoutes))->assertOk()
        ->assertSee('สายแพเพิ่มเติม')->assertSee('ยอดที่ลูกค้าอนุมัติล่าสุด')
        ->assertSee('data-price-difference="84100"', false);

    $newQuote['assessment_revision'] = 1;
    $this->post($this->store, $newQuote)->assertSessionHasNoErrors();
    $revised = $job->quotations()->where('revision', 2)->sole();
    $revisedRoutes = [...$repairRoutes, $revised];
    $approveRevised = route('repairs.quotations.approve', $revisedRoutes);
    $this->get(route('repairs.quotations.show', $revisedRoutes))->assertOk()
        ->assertSee('+250.00 บาท')->assertSee('1,091.00 บาท')->assertSee($newQuote['revision_reason']);
    $this->patch($approveRevised, $approval)->assertSessionHasErrors('confirmation');

    ($this->authorizeRelease)($revised);
    $this->actingAs($this->reception)->patch($approveRevised, $approval)->assertSessionHasNoErrors();
    $job->refresh();
    expect($job->status)->toBe(RepairStatus::Repairing)
        ->and($job->price_change_assessment_id)->toBeNull();
    expect($first->fresh()->total_satang)->toBe(84100)
        ->and($first->fresh()->approved_at)->not->toBeNull();
    expect($revised->fresh()->repair_assessment_id)->toBe($job->assessments()->sole()->id)
        ->and($revised->fresh()->approved_at)->not->toBeNull();
    $this->assertDatabaseCount('repair_status_changes', 3);
});

test('price comparisons use the last customer-approved quote when a previous draft was never approved', function () {
    $this->actingAs($this->reception)->post($this->store, $this->fields)->assertSessionHasNoErrors();
    $first = RepairQuotation::sole();
    $repairRoutes = [$this->branch, $this->job];

    ($this->authorizeRelease)($first);
    $this->actingAs($this->reception)->patch(route('repairs.quotations.approve', [...$repairRoutes, $first]), [
        'confirmation' => '1', 'approval_channel' => 'counter',
    ])->assertSessionHasNoErrors();

    $draft = ['base_revision' => 1, 'submission_id' => (string) Str::uuid()] + $this->fields;
    $draft['items'][0]['unit_price'] = '450.50';
    $this->post($this->store, $draft)->assertSessionHasNoErrors();
    $this->post($this->store, ['base_revision' => 2, 'submission_id' => (string) Str::uuid()] + $this->fields)
        ->assertSessionHasNoErrors();
    $latest = $this->job->quotations()->where('revision', 3)->sole();

    $this->get(route('repairs.quotations.show', [...$repairRoutes, $latest]))->assertOk()
        ->assertViewHas('comparisonQuote', fn (RepairQuotation $comparisonQuote) => $comparisonQuote->is($first))
        ->assertSee('ยอดรวมเท่าเดิม');
});
