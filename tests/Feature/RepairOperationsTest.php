<?php

use App\Enums\BranchRole;
use App\Enums\RepairStatus;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\RepairJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(Carbon::parse('2026-11-25 09:00:00', 'Asia/Bangkok'));
    $organization = Organization::create(['code' => 'endu', 'name' => 'ENDU']);
    $this->branch = $organization->branches()->create(['code' => 'fix', 'name' => 'ENDU FIX']);
    $this->secondBranch = $organization->branches()->create(['code' => 'care', 'name' => 'ENDU CARE']);
    $this->reception = User::factory()->create(['name' => 'ผู้รับเครื่องตัวอย่าง']);
    $this->technician = User::factory()->create(['name' => 'ช่างที่รับงาน']);
    $this->otherTechnician = User::factory()->create();
    $this->branch->memberships()->create(['user_id' => $this->reception->id, 'role' => BranchRole::Reception]);
    $this->branch->memberships()->create(['user_id' => $this->technician->id, 'role' => BranchRole::Technician]);
    $this->branch->memberships()->create(['user_id' => $this->otherTechnician->id, 'role' => BranchRole::Technician]);
    $this->secondBranch->memberships()->create(['user_id' => $this->technician->id, 'role' => BranchRole::Technician]);
    $this->secondBranch->memberships()->create(['user_id' => $this->reception->id, 'role' => BranchRole::Reception]);
    $this->intake = [
        'customer_name' => 'ลูกค้าส่วนตัวทดสอบ', 'customer_phone' => '0891234567', 'device' => 'iPhone 13',
        'product' => 'โทรศัพท์มือถือ', 'issue' => 'จอภาพมีเส้น', 'device_condition' => 'มีรอย', 'warranty_status' => 'covered',
    ];
    $this->makeJob = function (RepairStatus $status, string $device, ?User $technician = null, ?string $dueAt = null, bool $outside = false): RepairJob {
        $branch = $outside ? $this->secondBranch : $this->branch;
        $job = $branch->repairs()->make([
            'device' => $device, 'due_at' => $dueAt, 'submission_id' => (string) Str::uuid(), 'created_by' => $this->reception->id,
        ] + $this->intake);
        $job->status = $status;
        $job->technician_id = $technician?->id;
        $job->save();

        return $job;
    };
});

test('operations queues show branch work to reception and show only assigned work to technicians', function () {
    $received = ($this->makeJob)(RepairStatus::Received, 'ทดสอบรับเครื่อง');
    $diagnosing = ($this->makeJob)(RepairStatus::Diagnosing, 'ทดสอบกำลังตรวจ', $this->technician);
    $approval = ($this->makeJob)(RepairStatus::AwaitingApproval, 'ทดสอบรออนุมัติ', $this->technician);
    $parts = ($this->makeJob)(RepairStatus::WaitingParts, 'ทดสอบรอแบตเตอรี่', $this->technician);
    $qc = ($this->makeJob)(RepairStatus::AwaitingQualityCheck, 'ทดสอบรอ QC ช่างอีกคน', $this->otherTechnician);
    $foreign = ($this->makeJob)(RepairStatus::AwaitingQualityCheck, 'ทดสอบงานต่างสาขา', $this->technician, outside: true);

    $this->actingAs($this->reception)->get(route('dashboard'))->assertOk()
        ->assertSee('รอตรวจและกำลังตรวจ')->assertSee('รออนุมัติราคา')->assertSee('รออะไหล่')->assertSee('รอตรวจ QC');
    foreach (['inspection' => [$received, $diagnosing], 'approval' => [$approval], 'parts' => [$parts], 'qc' => [$qc]] as $queue => $jobs) {
        $response = $this->get(route('repairs.index', [$this->branch, 'queue' => $queue]))->assertOk();
        expect(collect($response->viewData('repairs')->items())->pluck('id')->sort()->values()->all())
            ->toBe(collect($jobs)->pluck('id')->sort()->values()->all());
    }

    $this->actingAs($this->technician)->get(route('repairs.index', [$this->branch, 'queue' => 'inspection']))->assertOk()
        ->assertSee($diagnosing->reference())->assertDontSee($received->reference())->assertDontSee($this->intake['customer_name']);
    $this->get(route('repairs.index', [$this->branch, 'queue' => 'qc']))->assertOk()
        ->assertDontSee($qc->reference())->assertDontSee($foreign->reference());
});

test('the assigned technician can send a repair to wait for parts and quality checking', function () {
    $job = ($this->makeJob)(RepairStatus::Repairing, 'เครื่องรออะไหล่', $this->technician);
    $url = route('repairs.change-status', [$this->branch, $job]);
    $this->actingAs($this->otherTechnician)->patch($url, ['next_status' => 'waiting_parts'])->assertNotFound();
    $this->actingAs($this->technician)->get(route('repairs.show', [$this->branch, $job]))->assertOk()
        ->assertSee('ย้ายเข้าคิวรออะไหล่')->assertSee('ส่งเข้าคิวตรวจ QC');

    foreach ([
        [RepairStatus::WaitingParts, 'รอจอทดสอบ'],
        [RepairStatus::Repairing, null],
        [RepairStatus::AwaitingQualityCheck, null],
    ] as $version => [$next, $note]) {
        $this->patch($url, ['next_status' => $next->value, 'workflow_version' => $version, 'assignment_version' => 0, 'workflow_note' => $note])
            ->assertSessionHasNoErrors();
        expect($job->refresh()->status)->toBe($next);
    }
    $this->assertDatabaseCount('repair_status_changes', 3);
});

test('deadline alerts and filters use the Bangkok day and update ownership when work is reassigned', function () {
    $past = ($this->makeJob)(RepairStatus::WaitingParts, 'งานเกินนัดของช่าง', $this->technician, '2026-11-25 01:59:00');
    $now = ($this->makeJob)(RepairStatus::Diagnosing, 'งานถึงกำหนดเวลานี้', $this->technician, '2026-11-25 02:00:00');
    $later = ($this->makeJob)(RepairStatus::AwaitingQualityCheck, 'งานถึงนัดคืนนี้', $this->technician, '2026-11-25 16:59:00');
    ($this->makeJob)(RepairStatus::Diagnosing, 'งานเที่ยงคืนวันถัดไป', $this->technician, '2026-11-25 17:00:00');
    ($this->makeJob)(RepairStatus::Delivered, 'งานที่ปิดแล้ว', $this->technician, '2026-11-24 05:00:00');
    ($this->makeJob)(RepairStatus::Diagnosing, 'งานต่างสาขา', $this->technician, '2026-11-24 05:00:00', outside: true);
    $unassigned = ($this->makeJob)(RepairStatus::Received, 'งานของผู้รับเครื่อง', dueAt: '2026-11-25 08:00:00');

    $this->actingAs($this->technician)->get(route('repairs.index', [$this->branch, 'due' => 'today']))->assertOk()
        ->assertSee($now->reference())->assertSee($later->reference())->assertSee('25/11/2026 23:59')
        ->assertDontSee($past->reference())->assertDontSee('งานเที่ยงคืนวันถัดไป');
    $this->get(route('repairs.index', [$this->branch, 'due' => 'overdue']))->assertOk()
        ->assertSee($past->reference())->assertDontSee('งานที่ปิดแล้ว')->assertDontSee('งานต่างสาขา');
    $alerts = $this->get(route('dashboard'))->assertOk()->assertSee('แจ้งเตือนงานที่ฉันรับผิดชอบ')
        ->assertSee('งานเกินนัด (1)')->assertSee('งานถึงกำหนดวันนี้ (2)')->viewData('deadlineAlerts');
    expect($alerts->pluck('id')->all())->toBe([$past->id, $now->id, $later->id]);

    $this->actingAs($this->reception)->get(route('repairs.index', [$this->branch, 'due' => 'today', 'mine' => 1]))->assertOk()
        ->assertSee($unassigned->reference())->assertSee($this->reception->name)->assertDontSee($later->reference());
    $this->patch(route('repairs.assign', [$this->branch, $unassigned]), ['technician_id' => $this->technician->id, 'assignment_version' => 0])
        ->assertSessionHasNoErrors();
    $alerts = $this->get(route('dashboard'))->assertOk()->viewData('deadlineAlerts');
    expect($alerts)->toBeEmpty();
    $alerts = $this->actingAs($this->technician)->get(route('dashboard'))->assertOk()->viewData('deadlineAlerts');
    expect($alerts->pluck('id')->all())->toBe([$past->id, $now->id, $unassigned->id, $later->id]);
});

test('the latest date follows rescheduling and assessments and edits require the current version and assignment', function () {
    $this->actingAs($this->reception)->post(route('repairs.store', $this->branch), [
        'submission_id' => (string) Str::uuid(), 'due_at' => '2026-11-25T15:00',
    ] + $this->intake)->assertSessionHasNoErrors();
    $job = RepairJob::sole();
    expect($job->due_at->format('Y-m-d H:i'))->toBe('2026-11-25 08:00');
    $job->status = RepairStatus::Diagnosing;
    $job->technician_id = $this->technician->id;
    $job->save();
    $deadline = route('repairs.change-deadline', [$this->branch, $job]);
    $assessment = route('repairs.assessment.store', [$this->branch, $job]);
    $this->actingAs($this->technician)->post($assessment, [
        'submission_id' => (string) Str::uuid(), 'assessment_revision' => 0, 'assignment_version' => 0, 'workflow_version' => 0,
        'due_version' => 0, 'cause' => 'จอมีเส้น', 'repair_plan' => 'เปลี่ยนจอ', 'expected_completion_at' => '2026-11-25T16:30',
        'items' => [['type' => 'labor', 'description' => 'ค่าแรง', 'quantity' => 1, 'unit_price' => '500.00']],
    ])->assertSessionHasNoErrors();
    expect($job->refresh()->due_at->format('Y-m-d H:i'))->toBe('2026-11-25 09:30');
    $this->actingAs($this->otherTechnician)->patch($deadline, [])->assertNotFound();
    $this->actingAs($this->reception)->patch($deadline, [
        'due_at' => '2026-11-26T13:10', 'due_version' => 0, 'assignment_version' => 0,
    ])->assertSessionHasErrors('due_at');
    $this->patch($deadline, ['due_at' => '2026-11-26T13:10', 'due_version' => 1, 'assignment_version' => 0])->assertSessionHasNoErrors();
    $this->get(route('repairs.show', [$this->branch, $job]))->assertOk()->assertSee('26/11/2026 13:10');
    $this->get(route('repairs.assessment.create', [$this->branch, $job]))->assertOk()->assertSee('2026-11-26T13:10');
    $this->get(route('repairs.index', [$this->branch, 'due' => 'today']))->assertOk()->assertDontSee($job->reference());
    $this->assertDatabaseHas('audit_events', ['subject_type' => 'repair', 'subject_reference' => $job->reference(), 'operation' => 'updated']);
    $event = AuditEvent::where('subject_reference', $job->reference())->latest('id')->firstOrFail();
    expect($event->before_values['due_at'])->toBe('2026-11-25 09:30:00')
        ->and($event->after_values['due_at'])->toBe('2026-11-26 06:10:00');
    $job->status = RepairStatus::Delivered;
    $job->save();
    $this->patch($deadline, ['due_at' => '2026-11-26T13:11', 'due_version' => 2, 'assignment_version' => 0])->assertSessionHasErrors('due_at');
});
