<?php

use App\Enums\ApprovalKind;
use App\Enums\BranchRole;
use App\Enums\RepairStatus;
use App\Models\Organization;
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
    $this->organization = Organization::create(['code' => 'endu', 'name' => 'ENDU']);
    $this->branch = $this->organization->branches()->create(['code' => 'shop', 'name' => 'ENDU SHOP']);
    $this->secondBranch = $this->organization->branches()->create(['code' => 'care', 'name' => 'ENDU CARE']);
    $this->otherOrganization = Organization::create(['code' => 'other', 'name' => 'กิจการนอกขอบเขต']);
    $this->foreignBranch = $this->otherOrganization->branches()->create(['code' => 'shop', 'name' => 'สาขานอกขอบเขต']);
    $this->hq = User::factory()->create(['name' => 'ผู้ดูแล HQ']);
    $this->owner = User::factory()->create(['name' => 'เจ้าของกิจการ']);
    $this->reception = User::factory()->create(['name' => 'หน้าร้านทดสอบ']);
    $this->branch->memberships()->create(['user_id' => $this->hq->id, 'role' => BranchRole::Hq]);
    $this->branch->memberships()->create(['user_id' => $this->owner->id, 'role' => BranchRole::Owner]);
    $this->branch->memberships()->create(['user_id' => $this->reception->id, 'role' => BranchRole::Reception]);
    $this->intake = [
        'customer_name' => 'ลูกค้าทดสอบ', 'customer_phone' => '0811111111', 'device' => 'iPhone 13',
        'issue' => 'จอแตก', 'submission_id' => (string) Str::uuid(),
    ];
});

test('HQ creates an organization with its existing owner and can suspend and restore access with audit history', function () {
    $fields = [
        'code' => 'NEW-CO', 'name' => 'กิจการใหม่', 'operator_type' => 'individual', 'owner_email' => $this->owner->email,
        'branch_code' => 'main', 'branch_name' => 'สาขาแรก',
    ];
    $this->actingAs($this->reception)->post(route('organizations.store'), $fields)->assertForbidden();
    $this->actingAs($this->owner)->post(route('organizations.store'), $fields)->assertForbidden();
    $this->actingAs($this->hq)->get(route('organizations.show', $this->otherOrganization))->assertNotFound();
    $this->post(route('organizations.store'), ['owner_email' => 'missing@example.test'] + $fields)->assertSessionHasErrors('owner_email');
    $this->post(route('organizations.store'), $fields)->assertSessionHasNoErrors();
    $organization = Organization::where('code', 'new-co')->sole();
    $branch = $organization->branches()->sole();
    expect($organization->owner->is($this->owner))->toBeTrue()
        ->and($branch->roleFor($this->owner))->toBe(BranchRole::Owner)
        ->and($branch->roleFor($this->hq))->toBe(BranchRole::Hq);
    $this->post(route('organizations.store'), $fields)->assertSessionHasErrors('code');

    $ticket = $branch->repairs()->create($this->intake + ['created_by' => $this->hq->id]);
    $status = route('organizations.status', $organization);
    $this->patch($status, ['suspended' => '1', 'reason' => 'ปิดปรับปรุง'])->assertSessionHasNoErrors();
    $this->patch($status, ['suspended' => '1', 'reason' => 'ส่งซ้ำ'])->assertSessionHasNoErrors();
    $this->get(route('repairs.show', [$branch, $ticket]))->assertNotFound();
    $this->post(route('workspace.branch', $branch))->assertNotFound();
    $this->get(route('phase-one', ['branch' => $branch->id]))->assertNotFound();
    $this->get(route('organizations.show', $organization))->assertOk()->assertSee('ปิดปรับปรุง')->assertSee('ผู้ดูแล HQ');
    $this->patch($status, ['suspended' => '0', 'reason' => 'เปิดบริการต่อ'])->assertSessionHasNoErrors();
    $this->get(route('repairs.show', [$branch, $ticket]))->assertOk()->assertSee($ticket->reference());
    expect($organization->statusEvents()->count())->toBe(2);
});

test('branch details are editable with unique codes within their organization and the work branch is scoped and remembered', function () {
    $fields = [
        'code' => 'SHOP', 'name' => 'สาขาใหม่', 'phone' => '0812223333',
        'address' => '55/2 กรุงเทพมหานคร', 'opening_hours' => 'ทุกวัน 09:00–18:00',
    ];
    $newBranch = route('organizations.branches.store', $this->organization);
    $this->actingAs($this->hq)->post($newBranch, $fields)->assertSessionHasErrors('code');
    $this->post($newBranch, ['code' => 'unique'] + $fields)->assertSessionHasNoErrors();
    $branch = $this->organization->branches()->where('code', 'unique')->sole();
    $this->patch(route('branch-settings.update', $branch), ['code' => 'care'] + $fields)->assertSessionHasErrors('code');
    $this->patch(route('branch-settings.update', $branch), ['code' => 'renamed', 'opening_hours' => '09:30–17:30'] + $fields)
        ->assertSessionHasNoErrors();
    $branch->refresh();
    expect($branch->code)->toBe('renamed')->and($branch->opening_hours)->toBe('09:30–17:30')
        ->and($branch->address)->toBe($fields['address'])->and($branch->phone)->toBe($fields['phone']);
    $this->get(route('branch-settings.show', $this->secondBranch))->assertNotFound();

    $this->post(route('workspace.branch', $branch))->assertRedirect(route('dashboard'))->assertSessionHas('active_branch_id', $branch->id);
    $this->get(route('dashboard'))->assertOk()->assertViewHas('activeBranch', fn ($active) => $active->is($branch));
    $this->post(route('workspace.branch', $this->foreignBranch))->assertNotFound();
    $this->actingAs($this->reception)->get(route('repairs.create', $this->branch))->assertOk()->assertSee($this->branch->name);
});

test('documents use the issuing branch profile and retain the original snapshot after later profile edits', function () {
    Storage::fake('local');
    $logo = UploadedFile::fake()->createWithContent('logo.png', file_get_contents(public_path('images/endu-fix-receipt-logo.png')));
    $this->actingAs($this->hq)->patch(route('organizations.document', $this->organization), [
        'name' => 'ผู้ขายเริ่มต้น', 'tax_id' => '0123456789012', 'address' => 'ที่อยู่สำนักงานใหญ่', 'logo' => $logo,
    ])->assertSessionHasNoErrors();
    $this->branch->update(['address' => 'ที่อยู่สาขา SHOP', 'phone' => '021234567']);
    $this->patch(route('branch-settings.document', $this->branch), [
        'name' => 'ผู้ขายประจำ ENDU SHOP', 'office' => 'สาขา 00001',
        'logo' => UploadedFile::fake()->image('branch.png', 3000, 1800),
    ])->assertSessionHasNoErrors();
    $profile = $this->branch->fresh()->documentSeller();
    Storage::disk('local')->assertExists('org-documents/'.$this->organization->id.'/'.$profile['logo']);
    $logoUrl = route('branch-settings.document.logo', [$this->branch, $profile['logo']]);
    $this->get($logoUrl)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->get(route('branch-settings.document.preview', $this->branch))->assertOk()
        ->assertSee('ผู้ขายประจำ ENDU SHOP')->assertSee('ที่อยู่สาขา SHOP')->assertSee('021234567')->assertSee($logoUrl, false)
        ->assertDontSee('ผู้ขายเริ่มต้น')->assertDontSee('0123456789012')->assertDontSee('ที่อยู่สำนักงานใหญ่');
    $this->patch(route('branch-settings.document', $this->branch), [
        'logo' => UploadedFile::fake()->image('too-wide.png', 6001, 100),
    ])->assertSessionHasErrors(['logo' => 'โลโก้ต้องกว้างและสูงไม่เกิน 6,000 พิกเซลต่อด้าน']);
    $this->patch(route('branch-settings.document', $this->branch), [
        'logo' => UploadedFile::fake()->image('too-large.png')->size(2049),
    ])->assertSessionHasErrors(['logo' => 'ไฟล์โลโก้ต้องมีขนาดไม่เกิน 2 MB']);
    expect($this->branch->fresh()->document_profile['logo'])->toBe($profile['logo']);
    $this->get(route('branch-settings.document.logo', [$this->foreignBranch, $profile['logo']]))->assertNotFound();

    $ticket = $this->branch->repairs()->create($this->intake + ['created_by' => $this->reception->id]);
    $ticket->status = RepairStatus::Diagnosing;
    $ticket->save();
    $this->actingAs($this->reception)->post(route('repairs.quotations.store', [$this->branch, $ticket]), [
        'submission_id' => (string) Str::uuid(), 'base_revision' => 0, 'terms' => 'รับประกันงานซ่อม',
        'items' => [['type' => 'labor', 'description' => 'ค่าแรง', 'quantity' => 1, 'unit_price' => '500.00']],
    ])->assertSessionHasNoErrors();
    $quote = RepairQuotation::sole();

    $this->actingAs($this->hq)->patch(route('branch-settings.document', $this->branch), ['name' => 'ชื่อผู้ขายใหม่'])->assertSessionHasNoErrors();
    $this->actingAs($this->reception)->get(route('repairs.print', [$this->branch, $ticket]))->assertOk()
        ->assertSee('ผู้ขายประจำ ENDU SHOP')->assertDontSee('ชื่อผู้ขายใหม่');
    $this->get(route('repairs.quotations.show', [$this->branch, $ticket, $quote]))->assertOk()
        ->assertSee('ผู้ขายประจำ ENDU SHOP')->assertSee('สาขา 00001')->assertDontSee('ชื่อผู้ขายใหม่');
});

test('repair intake records the Bangkok business date on both sides of the cutoff and the dashboard groups by that date', function () {
    $this->actingAs($this->hq)->patch(route('branch-settings.schedule', $this->branch), [
        'working_days' => [1, 2, 3, 4, 5, 6], 'shift_name' => 'กะดึก', 'shift_start' => '22:00',
        'shift_end' => '04:00', 'day_cutoff' => '02:00',
    ])->assertSessionHasNoErrors();
    expect($this->branch->fresh()->shift_end)->toBe('04:00');
    $this->actingAs($this->reception);
    $this->travelTo(Carbon::parse('2026-11-24 01:59:59', 'Asia/Bangkok'));
    $this->post(route('repairs.store', $this->branch), $this->intake)->assertSessionHasNoErrors();
    $this->travelTo(Carbon::parse('2026-11-24 02:00:00', 'Asia/Bangkok'));
    $this->post(route('repairs.store', $this->branch), ['submission_id' => (string) Str::uuid()] + $this->intake)->assertSessionHasNoErrors();

    $dates = $this->branch->repairs()->orderBy('id')->get()->map(fn ($repair) => $repair->business_date->toDateString())->all();
    expect($dates)->toBe(['2026-11-23', '2026-11-24']);
    $this->get(route('dashboard', ['branch' => $this->branch->id]))->assertOk()->assertViewHas('series',
        fn ($series) => $series->take(-2)->pluck('total')->all() === [1, 1],
    );
});

test('counters can only connect a payment point and printer profile from their own branch', function () {
    $this->actingAs($this->hq)->post(route('branch-settings.payment-points.store', $this->branch), [
        'code' => 'cash01', 'name' => 'จุดรับเงิน 1',
    ])->assertSessionHasNoErrors();
    $this->post(route('branch-settings.printer-profiles.store', $this->branch), [
        'name' => 'เครื่องพิมพ์หน้าเคาน์เตอร์', 'device_name' => 'Receipt Printer', 'paper' => 'thermal_80',
    ])->assertSessionHasNoErrors();
    $point = $this->branch->paymentPoints()->sole();
    $printer = $this->branch->printerProfiles()->sole();
    $otherPoint = $this->foreignBranch->paymentPoints()->create(['code' => 'foreign', 'name' => 'อีกสาขา']);
    $fields = ['code' => 'counter01', 'name' => 'เคาน์เตอร์ 1', 'payment_point_id' => $point->id, 'printer_profile_id' => $printer->id];
    $this->post(route('branch-settings.counters.store', $this->branch), ['payment_point_id' => $otherPoint->id] + $fields)
        ->assertSessionHasErrors('payment_point_id');
    $this->post(route('branch-settings.counters.store', $this->branch), $fields)->assertSessionHasNoErrors();
    $counter = $this->branch->counters()->sole();
    expect($counter->paymentPoint->is($point))->toBeTrue()->and($counter->printerProfile->is($printer))->toBeTrue();
    $this->patch(route('branch-settings.payment-points.update', [$this->branch, $otherPoint]), ['code' => 'cash', 'name' => 'แก้ไขข้ามสาขา'])
        ->assertNotFound();
    $this->get(route('branch-settings.show', $this->branch))->assertOk()->assertSee('เคาน์เตอร์ 1')->assertSee('80 มม.');
});

test('amounts above the business thresholds reach only their designated approval queue', function () {
    $manager = User::factory()->create(['name' => 'ผู้จัดการทดสอบ']);
    $stock = User::factory()->create();
    $this->branch->memberships()->create(['user_id' => $manager->id, 'role' => BranchRole::Manager]);
    $this->branch->memberships()->create(['user_id' => $stock->id, 'role' => BranchRole::Stock]);
    $rules = [
        'discount' => ['limit' => '200.00', 'review_role' => 'manager'],
        'refund' => ['limit' => '500.50', 'review_role' => 'owner'],
        'stock_adjustment' => ['limit' => '3', 'review_role' => 'hq'],
    ];
    $this->actingAs($this->hq)->patch(route('organizations.rules', $this->organization), ['rules' => $rules])->assertSessionHasNoErrors();
    $store = route('organization-approvals.store', $this->branch);
    foreach ([
        [ApprovalKind::Discount, '200.00', 'within_limit', $this->reception],
        [ApprovalKind::Discount, '200.01', 'pending', $this->reception],
        [ApprovalKind::Refund, '500.51', 'pending', $this->reception],
        [ApprovalKind::StockAdjustment, '4', 'pending', $stock],
    ] as [$kind, $amount, $status, $actor]) {
        $submissionId = (string) Str::uuid();
        $fields = [
            'kind' => $kind->value, 'amount' => $amount, 'submission_id' => $submissionId,
            'reference' => $kind->label().' '.$amount, 'reason' => 'บันทึกการปรับยอดทดสอบ',
        ];
        $this->actingAs($actor)->post($store, $fields)->assertSessionHasNoErrors();
        $this->post($store, $fields)->assertSessionHasNoErrors();
        expect($this->branch->approvalRequests()->where('submission_id', $submissionId)->sole()->status)->toBe($status);
    }
    $this->assertDatabaseCount('organization_approval_requests', 4);
    $discount = $this->branch->approvalRequests()->where('kind', 'discount')->where('status', 'pending')->sole();
    $queue = route('organization-approvals.index', $this->branch);
    $decision = route('organization-approvals.decide', [$this->branch, $discount]);
    $fields = ['status' => 'approved', 'decision_reason' => 'ตรวจสอบแล้ว'];
    $this->actingAs($this->owner)->get($queue)->assertOk()->assertSee('คืนเงิน 500.51')->assertDontSee('ส่วนลด 200.01');
    $this->patch($decision, $fields)->assertForbidden();
    $this->actingAs($manager)->get($queue)->assertOk()->assertSee('ส่วนลด 200.01')->assertDontSee('คืนเงิน 500.51');
    $this->patch($decision, $fields)->assertSessionHasNoErrors();
    $this->patch($decision, $fields)->assertConflict();
    $discount->refresh();
    expect($discount->amount)->toBe(20001)->and($discount->limit)->toBe(20000)->and($discount->reviewer->is($manager))->toBeTrue();
    $this->actingAs($this->hq)->get($queue)->assertOk()->assertSee('ปรับสต๊อก 4');
    $this->get(route('organization-approvals.index', $this->foreignBranch))->assertNotFound();
});
