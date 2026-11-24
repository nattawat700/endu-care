<?php

use App\Enums\BranchRole;
use App\Enums\RepairStatus;
use App\Models\Organization;
use App\Models\RepairAssessment;
use App\Models\RepairQuotation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $organization = Organization::create(['code' => 'endu', 'name' => 'ENDU']);
    $this->branch = $organization->branches()->create(['code' => 'fix', 'name' => 'ENDU FIX']);
    $otherBranch = $organization->branches()->create(['code' => 'bkp', 'name' => 'ENDU CARE บางกะปิ']);
    $this->reception = User::factory()->create();
    $this->technician = User::factory()->create();
    $this->otherTechnician = User::factory()->create();
    $this->otherReception = User::factory()->create();

    $this->branch->memberships()->create(['user_id' => $this->reception->id, 'role' => BranchRole::Reception]);
    $this->branch->memberships()->create(['user_id' => $this->technician->id, 'role' => BranchRole::Technician]);
    $this->branch->memberships()->create(['user_id' => $this->otherTechnician->id, 'role' => BranchRole::Technician]);
    $otherBranch->memberships()->create(['user_id' => $this->otherReception->id, 'role' => BranchRole::Reception]);

    $this->job = $this->branch->repairs()->create([
        'created_by' => $this->reception->id,
        'submission_id' => (string) Str::uuid(),
        'customer_name' => 'ลูกค้าทดสอบ',
        'customer_phone' => '0800000000',
        'device' => 'iPhone 13',
        'issue' => 'ชาร์จไม่เข้า',
    ]);
    $this->job->status = RepairStatus::Diagnosing;
    $this->job->technician()->associate($this->technician);
    $this->job->save();

    $this->routes = [$this->branch, $this->job];
    $this->createAssessment = route('repairs.assessment.create', $this->routes);
    $this->storeAssessment = route('repairs.assessment.store', $this->routes);
    $this->showRepair = route('repairs.show', $this->routes);
    $this->fields = [
        'assessment_revision' => 0,
        'assignment_version' => 0,
        'workflow_version' => 0,
        'submission_id' => (string) Str::uuid(),
        'cause' => 'พอร์ตชาร์จมีคราบและขั้วหลวม',
        'repair_plan' => 'เปลี่ยนพอร์ตชาร์จและทดสอบการชาร์จ',
        'expected_completion_at' => '2026-11-25T13:15',
        'items' => [
            ['type' => 'labor', 'description' => 'ค่าแรงเปลี่ยนพอร์ตชาร์จ', 'quantity' => 1, 'unit_price' => '350.50'],
            ['type' => 'part', 'description' => 'อะไหล่พอร์ตชาร์จ', 'quantity' => 2, 'unit_price' => '245.25'],
        ],
    ];
});

test('the assigned technician can record a priced assessment and send it to reception once', function () {
    $this->actingAs($this->technician)->get($this->createAssessment)->assertOk()
        ->assertSee('บันทึกและส่งให้หน้าร้าน');
    $payload = $this->fields + ['send_to_reception' => '1', 'total_satang' => 1];
    $this->post($this->storeAssessment, $payload)->assertSessionHasNoErrors()->assertRedirect($this->showRepair);

    $assessment = RepairAssessment::sole();
    expect($assessment->revision)->toBe(1)
        ->and($assessment->recorded_by)->toBe($this->technician->id)
        ->and($assessment->total_satang)->toBe(84100)
        ->and($assessment->items[1]['total_satang'])->toBe(49050)
        ->and($assessment->expected_completion_at->format('Y-m-d H:i'))->toBe('2026-11-25 06:15')
        ->and($this->job->fresh()->status)->toBe(RepairStatus::AwaitingApproval);

    $this->post($this->storeAssessment, $payload)->assertRedirect($this->showRepair);
    $this->assertDatabaseCount('repair_assessments', 1);
    $this->assertDatabaseCount('repair_status_changes', 1);
    $this->actingAs($this->reception)->get($this->showRepair)->assertOk()
        ->assertSee($this->fields['cause'])->assertSee($this->fields['repair_plan'])
        ->assertSee('25/11/2026 13:15')->assertSee('841.00 บาท')
        ->assertSee('ใช้ผลประเมินจัดทำใบเสนอราคา');
});

test('reception can use assessment prices in a draft while old quotes retain their assessment', function () {
    $this->actingAs($this->technician)->post($this->storeAssessment, $this->fields)->assertSessionHasNoErrors();
    expect($this->job->fresh()->status)->toBe(RepairStatus::Diagnosing);
    $storeQuote = route('repairs.quotations.store', $this->routes);
    $createQuote = route('repairs.quotations.create', $this->routes);

    $this->actingAs($this->reception)->get($createQuote)->assertOk()
        ->assertSee('name="assessment_revision" value="1"', false)
        ->assertSee('อะไหล่พอร์ตชาร์จ')->assertSee('245.25');
    $quoteFields = [
        'base_revision' => 0,
        'assessment_revision' => 1,
        'submission_id' => (string) Str::uuid(),
        'terms' => 'รับประกันงานซ่อม 30 วัน',
        'items' => $this->fields['items'],
    ];
    $this->post($storeQuote, $quoteFields)->assertSessionHasNoErrors();
    $quote = RepairQuotation::sole();
    expect($quote->assessment->revision)->toBe(1)
        ->and($quote->total_satang)->toBe(84100)
        ->and($quote->requires_internal_approval)->toBeTrue();

    $updated = [
        'assessment_revision' => 1,
        'submission_id' => (string) Str::uuid(),
        'expected_completion_at' => '2026-11-26T14:30',
    ] + $this->fields;
    $updated['items'][0]['unit_price'] = '360.00';
    $this->actingAs($this->technician)->post($this->storeAssessment, $updated)->assertSessionHasNoErrors();
    $this->actingAs($this->reception)->post($storeQuote, [
        'base_revision' => 1, 'submission_id' => (string) Str::uuid(),
    ] + $quoteFields)->assertSessionHasErrors('assessment_revision');

    $this->flushSession()->get(route('repairs.quotations.create', [...$this->routes, 'from_assessment' => 1]))->assertOk()
        ->assertSee('name="assessment_revision" value="2"', false)->assertSee('360.00');
    $this->get(route('repairs.quotations.show', [...$this->routes, $quote]))->assertOk()
        ->assertSee('25/11/2026 13:15')->assertSee('841.00 บาท')->assertDontSee('26/11/2026 14:30');
    $this->assertDatabaseCount('repair_quotations', 1);

    $this->get($createQuote)->assertOk()->assertSee('25/11/2026 13:15')->assertSee('350.50');
    $this->post($storeQuote, [
        'base_revision' => 1,
        'submission_id' => (string) Str::uuid(),
        'terms' => 'แก้ไขเงื่อนไขรับประกัน',
        'items' => $quoteFields['items'],
    ])->assertSessionHasNoErrors();
    $revisedQuote = $this->job->quotations()->where('revision', 2)->sole();
    expect($revisedQuote->repair_assessment_id)->toBe($quote->repair_assessment_id);
});

test('assessment mutations respect the assigned technician, branch, and current form version', function () {
    foreach ([$this->otherTechnician, $this->otherReception] as $staff) {
        $this->actingAs($staff)->get($this->createAssessment)->assertNotFound();
        $this->post($this->storeAssessment, $this->fields)->assertNotFound();
    }

    $this->actingAs($this->reception)
        ->post($this->storeAssessment, $this->fields + ['send_to_reception' => '1'])->assertForbidden();
    $this->actingAs($this->technician);
    $invalid = ['expected_completion_at' => '2026-11-31T13:15'] + $this->fields;
    $invalid['items'][0]['unit_price'] = '0.001';
    $this->post($this->storeAssessment, $invalid)
        ->assertSessionHasErrors(['expected_completion_at', 'items.0.unit_price']);
    $this->assertDatabaseCount('repair_assessments', 0);

    $this->post($this->storeAssessment, $this->fields)->assertSessionHasNoErrors();
    $this->from($this->createAssessment)->post($this->storeAssessment, [
        'submission_id' => (string) Str::uuid(),
    ] + $this->fields)->assertSessionHasErrors('assessment_revision');
    $this->flushSession()->get($this->createAssessment)->assertOk()
        ->assertSee('name="assessment_revision" value="1"', false);
    $this->assertDatabaseCount('repair_assessments', 1);
});
