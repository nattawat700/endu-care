<?php

use App\Enums\BranchRole;
use App\Enums\RepairKind;
use App\Enums\RepairStatus;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\RepairJob;
use App\Models\RepairPhoto;
use App\Models\RepairQualityCheck;
use App\Models\RepairQuotation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(Carbon::parse('2026-11-25 10:00:00', 'Asia/Bangkok'));
    $organization = Organization::create(['code' => 'endu', 'name' => 'ENDU']);
    $this->branch = $organization->branches()->create(['code' => 'fix', 'name' => 'ENDU FIX']);
    $this->otherBranch = $organization->branches()->create(['code' => 'care', 'name' => 'ENDU CARE']);
    $this->reception = User::factory()->create(['name' => 'ผู้รับงานทดสอบ']);
    $this->technician = User::factory()->create(['name' => 'ช่างคนแรก']);
    $this->replacement = User::factory()->create(['name' => 'ช่างคนใหม่']);
    $this->outside = User::factory()->create();
    $this->cashier = User::factory()->create();
    foreach ([
        [$this->reception, BranchRole::Reception], [$this->technician, BranchRole::Technician],
        [$this->replacement, BranchRole::Technician], [$this->cashier, BranchRole::Cashier],
    ] as [$user, $role]) {
        $this->branch->memberships()->create(['user_id' => $user->id, 'role' => $role]);
    }
    $this->otherBranch->memberships()->create(['user_id' => $this->outside->id, 'role' => BranchRole::Technician]);
    $this->otherBranch->memberships()->create(['user_id' => $this->reception->id, 'role' => BranchRole::Reception]);
    $this->intake = [
        'submission_id' => (string) Str::uuid(), 'customer_name' => 'ลูกค้าทดสอบงานด่วน', 'customer_phone' => '0891234567',
        'device' => 'iPhone 13', 'product' => 'โทรศัพท์มือถือ', 'issue' => 'จอภาพมีเส้น', 'device_condition' => 'ฝาหลังมีรอย',
        'accessories' => 'สายชาร์จ', 'warranty_status' => 'not_covered', 'imei' => '358111111111111',
        'storage_location' => 'ตู้ A / ชั้น 2',
    ];
    $this->quick = [
        'work_kind' => 'quick', 'fixed_price' => '1490.50', 'due_at' => '2026-11-25T16:30', 'technician_id' => $this->technician->id,
    ];
});

test('known price work starts in repair with the chosen local technician and preserves the intake details', function () {
    $this->actingAs($this->reception)->get(route('repairs.create', $this->branch))->assertOk()
        ->assertSee('งานด่วนทราบราคา')->assertSee('งานตรวจประเมิน')->assertDontSee($this->outside->name);
    $url = route('repairs.store', $this->branch);
    $this->post($url, ['work_kind' => 'quick'] + $this->intake)
        ->assertSessionHasErrors(['fixed_price', 'technician_id', 'due_at']);
    $this->post($url, ['technician_id' => $this->outside->id] + $this->quick + $this->intake)->assertSessionHasErrors('technician_id');
    $this->post($url, $this->quick + $this->intake)->assertSessionHasNoErrors();
    $job = RepairJob::sole();
    expect($job->work_kind)->toBe(RepairKind::Quick)->and($job->status)->toBe(RepairStatus::Repairing)
        ->and($job->technician_id)->toBe($this->technician->id)->and($job->assignment_version)->toBe(1)
        ->and($job->fixed_price_satang)->toBe(149050)->and($job->due_at->format('H:i'))->toBe('09:30');
    $show = route('repairs.show', [$this->branch, $job]);
    $this->post($url, $this->quick + $this->intake)->assertRedirect($show);
    $this->assertDatabaseCount('repair_jobs', 1);
    $this->get($show)->assertOk()->assertSee('1,490.50 บาท')->assertSee('25/11/2026 16:30')->assertSee('ตู้ A / ชั้น 2')
        ->assertSee('358111111111111')->assertSee('ฝาหลังมีรอย')->assertSee('สายชาร์จ')->assertSee('จอภาพมีเส้น');
    $this->actingAs($this->technician)->get($show)->assertOk()->assertDontSee('358111111111111');
    $this->patch(route('repairs.change-status', [$this->branch, $job]), [
        'next_status' => 'ready', 'workflow_version' => 0, 'assignment_version' => 1, 'workflow_note' => 'ขอข้าม QC',
    ])->assertForbidden();
});

test('assessment work saves common fields on a generic branch and supports diagnosis followed by a quotation draft', function () {
    $this->actingAs($this->reception)->post(route('repairs.store', $this->branch), [
        'work_kind' => 'assessment', 'technician_id' => $this->technician->id,
    ] + $this->intake)->assertSessionHasNoErrors();
    $job = RepairJob::sole();
    expect($job->work_kind)->toBe(RepairKind::Assessment)->and($job->status)->toBe(RepairStatus::Received)->and($job->fixed_price_satang)->toBeNull();
    $this->actingAs($this->technician)->patch(route('repairs.change-status', [$this->branch, $job]), [
        'next_status' => 'diagnosing', 'workflow_version' => 0, 'assignment_version' => 1,
    ])->assertSessionHasNoErrors();
    $this->actingAs($this->reception)->post(route('repairs.quotations.store', [$this->branch, $job]), [
        'submission_id' => (string) Str::uuid(), 'base_revision' => 0, 'terms' => 'รอลูกค้ายืนยัน',
        'items' => [['type' => 'labor', 'description' => 'เปลี่ยนจอ', 'quantity' => 1, 'unit_price' => '1500']],
    ])->assertSessionHasNoErrors();
    expect(RepairQuotation::sole()->repair_job_id)->toBe($job->id);

    $this->post(route('repairs.store', $this->otherBranch), $this->intake)->assertSessionHasNoErrors();
    $other = $this->otherBranch->repairs()->sole();
    $this->get(route('repairs.show', [$this->otherBranch, $other]))->assertOk()->assertSee('ตู้ A / ชั้น 2')
        ->assertSee('358111111111111')->assertSee('ฝาหลังมีรอย')->assertSee('สายชาร์จ');
});

test('the job shows old and new assignees with the actor using the assignment history', function () {
    $this->actingAs($this->reception)->post(route('repairs.store', $this->branch), $this->quick + $this->intake)->assertSessionHasNoErrors();
    $job = RepairJob::sole();
    $params = [$this->branch, $job];
    $this->patch(route('repairs.assign', $params), ['technician_id' => $this->replacement->id, 'assignment_version' => 1])->assertSessionHasNoErrors();
    $this->get(route('repairs.show', $params))->assertOk()->assertSee('ประวัติการมอบหมายช่าง')
        ->assertSee('ช่างคนแรก')->assertSee('ช่างคนใหม่')->assertSee('ผู้รับงานทดสอบ');
    $changes = AuditEvent::where('subject_reference', $job->reference())->where('subject_type', 'repair')
        ->whereNotNull('after_values->technician_id')->orderBy('id')->get();
    expect($changes)->toHaveCount(2)
        ->and($changes[1]->before_values['technician_id'])->toBe($this->technician->id)
        ->and($changes[1]->after_values['technician_id'])->toBe($this->replacement->id);
    $this->actingAs($this->technician)->get(route('repairs.show', $params))->assertNotFound();
});

test('repair images are private and scoped to the job branch and current assignee even at a direct URL', function () {
    Storage::fake('local');
    Storage::fake('public');
    $this->actingAs($this->reception)->post(route('repairs.store', $this->branch), $this->quick + $this->intake)->assertSessionHasNoErrors();
    $job = RepairJob::sole();
    $params = [$this->branch, $job];
    $store = route('repairs.photos.store', $params);
    $this->post($store, ['phase' => 'before', 'photo' => UploadedFile::fake()->createWithContent('test.php', '<?php echo "test";')])
        ->assertSessionHasErrors('photo');
    $png = file_get_contents(public_path('images/endu-fix-receipt-logo.png'));
    foreach (['before', 'after'] as $phase) {
        $this->post($store, ['phase' => $phase, 'photo' => UploadedFile::fake()->createWithContent('sample.png', $png)])
            ->assertSessionHasNoErrors();
    }
    $photo = $job->photos()->where('phase', 'before')->sole();
    $privatePath = RepairPhoto::directory($job).'/'.$photo->filename;
    Storage::disk('local')->assertExists($privatePath);
    Storage::disk('public')->assertMissing($privatePath);

    $url = route('repairs.photos.show', [...$params, $photo]);
    $this->actingAs($this->technician)->get($url)->assertOk()->assertHeader('Content-Type', 'image/png')
        ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->get(route('repairs.show', $params))->assertOk()->assertSee('รูปก่อนซ่อม')->assertSee('รูปหลังซ่อม')->assertSee('25/11/2026 10:00');
    $this->actingAs($this->replacement)->get($url)->assertNotFound();
    $this->actingAs($this->cashier)->get($url)->assertNotFound();
    $this->post($store, ['phase' => 'before', 'photo' => UploadedFile::fake()->createWithContent('sample.png', $png)])->assertNotFound();
    $this->actingAs($this->reception)->get(route('repairs.photos.show', [$this->otherBranch, $job, $photo]))->assertNotFound();
    $otherJob = $this->branch->repairs()->create(['submission_id' => (string) Str::uuid(), 'created_by' => $this->reception->id] + $this->intake);
    $this->get(route('repairs.photos.show', [$this->branch, $otherJob, $photo]))->assertNotFound();

    $this->patch(route('repairs.assign', $params), ['technician_id' => $this->replacement->id, 'assignment_version' => 1])->assertSessionHasNoErrors();
    $this->actingAs($this->technician)->get($url)->assertNotFound();
    $this->actingAs($this->replacement)->get($url)->assertOk();
    $this->assertDatabaseCount('repair_photos', 2);
});

test('QC records each check and inspector sends failures back to repair and permits delivery only after passing', function () {
    $this->actingAs($this->reception)->post(route('repairs.store', $this->branch), $this->quick + $this->intake)->assertSessionHasNoErrors();
    $job = RepairJob::sole();
    $params = [$this->branch, $job];
    $change = route('repairs.change-status', $params);
    $check = route('repairs.quality-checks.store', $params);
    $state = ['workflow_version' => 0, 'assignment_version' => 1];
    $this->actingAs($this->technician)->patch($change, ['next_status' => 'awaiting_qc'] + $state)->assertSessionHasNoErrors();
    $this->get(route('repairs.show', $params))->assertOk()->assertSee('งานที่ทำตรงกับรายการที่ตกลงกับลูกค้า')
        ->assertDontSee('บันทึกผล QC และพร้อมส่งมอบ');
    $state['workflow_version'] = 1;
    $allPass = ['symptom' => 'pass', 'agreed_service' => 'pass', 'condition' => 'pass', 'accessories' => 'pass'];
    $this->patch($change, ['next_status' => 'ready', 'workflow_note' => 'ข้ามเช็กลิสต์'] + $state)->assertForbidden();
    $this->post($check, ['qc_results' => ['symptom' => 'pass']] + $state)->assertSessionHasErrors('qc_results.condition');
    $this->actingAs($this->reception)->post($check, ['qc_results' => $allPass] + $state)->assertForbidden();
    $this->patch($change, ['next_status' => 'delivered'] + $state)->assertForbidden();
    $this->actingAs($this->replacement)->post($check, ['qc_results' => $allPass] + $state)->assertNotFound();
    $failed = ['qc_results' => ['condition' => 'fail'] + $allPass] + $state;
    $this->actingAs($this->technician)->post($check, $failed)->assertSessionHasErrors('qc_note');
    $this->post($check, ['qc_note' => 'ประกอบขอบจอใหม่'] + $failed)->assertSessionHasNoErrors();
    $record = RepairQualityCheck::sole();
    expect($job->refresh()->status)->toBe(RepairStatus::Repairing)->and($record->passed)->toBeFalse()
        ->and($record->inspected_by)->toBe($this->technician->id)->and($record->items)->toHaveCount(4);
    $this->patch($change, ['next_status' => 'awaiting_qc', 'workflow_version' => 2] + $state)->assertSessionHasNoErrors();
    $this->post($check, ['qc_results' => $allPass] + $state)->assertSessionHasErrors('qc_results');
    $this->post($check, ['qc_results' => $allPass, 'workflow_version' => 3] + $state)->assertSessionHasNoErrors();
    expect($job->refresh()->status)->toBe(RepairStatus::Ready);
    $this->actingAs($this->reception)->patch($change, ['next_status' => 'delivered', 'workflow_version' => 4] + $state)->assertSessionHasErrors('next_status');
    $job->payments()->create([
        'branch_id' => $job->branch_id, 'received_by' => $this->reception->id, 'submission_id' => (string) Str::uuid(),
        'channel' => 'cash', 'document_type' => 'receipt', 'status' => 'confirmed',
        'job_total_satang' => $job->fixed_price_satang, 'payable_satang' => $job->fixed_price_satang,
        'paid_before_satang' => 0, 'amount_satang' => $job->fixed_price_satang, 'document' => [],
    ]);
    $this->patch($change, ['next_status' => 'delivered', 'workflow_version' => 4] + $state)->assertSessionHasNoErrors();
    $this->get(route('repairs.show', $params))->assertOk()->assertSee('ส่งมอบแล้ว')->assertSee('ประกอบขอบจอใหม่');
    $this->assertDatabaseCount('repair_quality_checks', 2);
});
