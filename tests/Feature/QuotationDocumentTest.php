<?php

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
    $this->travelTo(Carbon::parse('2026-11-24 10:00:00', 'Asia/Bangkok'));
    $this->organization = Organization::create(['code' => 'endu', 'name' => 'ENDU']);
    $this->branch = $this->organization->branches()->create(['code' => 'fix', 'name' => 'ENDU FIX']);
    $this->hq = User::factory()->create();
    $this->reception = User::factory()->create();
    $this->manager = User::factory()->create(['name' => 'ผู้อนุมัติทดสอบ']);
    $this->cashier = User::factory()->create();
    $this->technician = User::factory()->create();
    foreach ([
        [$this->hq, BranchRole::Hq], [$this->reception, BranchRole::Reception], [$this->manager, BranchRole::Manager],
        [$this->cashier, BranchRole::Cashier], [$this->technician, BranchRole::Technician],
    ] as [$user, $role]) {
        $this->branch->memberships()->create(['user_id' => $user->id, 'role' => $role]);
    }

    $this->intake = [
        'created_by' => $this->reception->id, 'customer_name' => 'ลูกค้าเดิม', 'customer_phone' => '0891119927',
        'customer_address' => 'ที่อยู่ในใบรับซ่อม', 'device' => 'iPhone 13', 'issue' => 'จอแตก', 'submission_id' => (string) Str::uuid(),
    ];
    $this->ticket = $this->branch->repairs()->create($this->intake);
    $this->ticket->status = RepairStatus::AwaitingApproval;
    $this->ticket->save();
    $this->parameters = [$this->branch, $this->ticket];
    $this->store = route('repairs.quotations.store', $this->parameters);
    $this->fields = [
        'submission_id' => (string) Str::uuid(), 'base_revision' => 0, 'valid_until' => '2026-11-30',
        'terms' => 'รับประกันงานซ่อมตามตกลง',
        'items' => [
            ['type' => 'product', 'description' => 'หน้าจอรุ่นทดสอบ', 'quantity' => 2, 'unit' => 'ชิ้น', 'unit_price' => '1000.00', 'discount' => '100.00'],
            ['type' => 'labor', 'description' => 'ค่าแรงติดตั้ง', 'quantity' => 1, 'unit' => 'งาน', 'unit_price' => '500.00'],
        ],
    ];
});

test('only the issuing branch feeds both document headers and new versions retain their snapshot', function () {
    Storage::fake('local');
    $this->actingAs($this->hq)->patch(route('organizations.document', $this->organization), [
        'name' => 'ผู้ขายระดับกิจการ', 'tax_id' => '0123456789012', 'registration_id' => '1112223334445',
        'email' => 'company@example.test', 'address' => 'ข้อมูลสำนักงานกลาง',
        'logo' => UploadedFile::fake()->createWithContent('logo.png', file_get_contents(public_path('images/endu-fix-receipt-logo.png'))),
    ])->assertSessionHasNoErrors();
    $this->patch(route('branch-settings.document', $this->branch), [
        'name' => 'ผู้ขายจากสาขา', 'office' => 'สาขา 00001', 'address' => 'ที่อยู่ผู้ขายสาขา', 'phone' => '021234567',
        'email' => 'branch@example.test', 'registration_id' => '1234567890123',
        'logo' => UploadedFile::fake()->createWithContent('branch.png', file_get_contents(public_path('images/endu-fix-receipt-logo.png'))),
    ])->assertSessionHasNoErrors();
    $record = ['submission_id' => (string) Str::uuid()] + $this->intake;
    $newTicket = $this->branch->repairs()->create($record);
    $this->actingAs($this->reception)->post($this->store, $this->fields)->assertSessionHasNoErrors();
    $quote = RepairQuotation::sole();

    $logo = route('branch-settings.document.logo', [$this->branch, $quote->seller_profile['logo']]);
    expect($quote->seller_profile['address'])->toBe('ที่อยู่ผู้ขายสาขา')
        ->and($quote->seller_profile['email'])->toBe('branch@example.test');
    $this->get(route('repairs.print', [$this->branch, $newTicket]))->assertOk()
        ->assertSee($logo, false)->assertSee('ผู้ขายจากสาขา')->assertSee('branch@example.test')
        ->assertDontSee('ผู้ขายระดับกิจการ')->assertDontSee('company@example.test');
    $quoteParameters = [...$this->parameters, $quote];
    $this->post(route('repairs.quotations.submit-review', $quoteParameters), ['request_reason' => 'ตรวจหัวเอกสาร'])->assertSessionHasNoErrors();
    $this->actingAs($this->manager)->patch(route('repairs.quotations.decide-review', $quoteParameters), [
        'decision' => 'approved', 'decision_reason' => 'ตรวจหัวเอกสารแล้ว',
    ])->assertSessionHasNoErrors();
    $this->get(route('repairs.quotations.print', $quoteParameters))->assertOk()
        ->assertSee($logo, false)->assertSee('สาขา 00001')->assertSee('1234567890123')->assertSee('branch@example.test')
        ->assertDontSee('ผู้ขายระดับกิจการ')->assertDontSee('1112223334445')->assertDontSee('company@example.test');

    $this->actingAs($this->hq)->patch(route('branch-settings.document', $this->branch), ['name' => 'ชื่อใหม่'])->assertSessionHasNoErrors();
    $this->actingAs($this->reception)->get(route('repairs.print', [$this->branch, $newTicket]))->assertOk()
        ->assertSee('ผู้ขายจากสาขา')->assertDontSee('ชื่อใหม่');
    $this->get(route('repairs.quotations.show', [...$this->parameters, $quote]))->assertOk()
        ->assertSee('ผู้ขายจากสาขา')->assertDontSee('ชื่อใหม่');

    $this->ticket->seller_profile = ['name' => 'ผู้ขายระดับกิจการ', 'branch_name' => 'ENDU FIX', 'email' => 'company@example.test'];
    $this->ticket->save();
    // Simulate a historical snapshot written by the old release.
    $this->ticket->quotations()->whereKey($quote->id)->update(['seller_profile' => $this->ticket->seller_profile]);
    $this->get(route('repairs.print', $this->parameters))->assertOk()->assertSee('ชื่อใหม่')
        ->assertDontSee('ผู้ขายระดับกิจการ')->assertDontSee('company@example.test');
    $this->get(route('repairs.quotations.print', $quoteParameters))->assertOk()->assertSee('ชื่อใหม่')
        ->assertDontSee('ผู้ขายระดับกิจการ')->assertDontSee('company@example.test');
});

test('commercial details and authoritative satang totals appear on the quote and sensitive buyer data is scoped', function () {
    $this->actingAs($this->reception)->post($this->store, $this->fields + [
        'buyer_name' => 'บริษัทผู้ซื้อทดสอบ', 'buyer_phone' => '0812349385', 'buyer_email' => 'buyer@example.test',
        'buyer_address' => 'ที่อยู่ผู้ซื้อที่มีสิทธิ์เท่านั้น', 'buyer_tax_id' => '9876543210123',
        'contact_name' => 'พนักงานประสานงาน', 'contact_phone' => '021234567', 'contact_email' => 'sales@example.test',
        'document_discount' => '100.00', 'shipping' => '50.00', 'other_charge' => '20.00', 'other_label' => 'ค่าบรรจุสินค้า',
        'vat_mode' => 'exclusive', 'vat_rate' => '7.00', 'withholding' => '50.00',
        'payment_channels' => 'โอนผ่านธนาคาร', 'payment_details' => 'แจ้งหลักฐานเมื่อโอนเสร็จ',
        'credit_days' => 30, 'delivery_terms' => 'จัดส่งภายใน 3 วัน', 'notes' => 'นัดส่งช่วงเช้า',
        'total_satang' => 1,
    ])->assertSessionHasNoErrors();
    $quote = RepairQuotation::sole();
    expect($quote->items[0]['total_satang'])->toBe(190000)
        ->and($quote->amount_breakdown['subtotal_satang'])->toBe(250000)
        ->and($quote->amount_breakdown['net_before_vat_satang'])->toBe(237000)
        ->and($quote->amount_breakdown['vat_satang'])->toBe(16590)
        ->and($quote->total_satang)->toBe(253590)
        ->and($quote->amount_breakdown['payable_satang'])->toBe(248590);
    $url = route('repairs.quotations.show', [...$this->parameters, $quote]);
    $this->get($url)->assertOk()->assertSee('/ Quotation')->assertSee($quote->reference())->assertSee('30/11/2026')
        ->assertSee('บริษัทผู้ซื้อทดสอบ')->assertSee('buyer@example.test')->assertSee('พนักงานประสานงาน')
        ->assertSee('เครดิต 30 วัน')->assertSee('จัดส่งภายใน 3 วัน')->assertSee('ค่าบรรจุสินค้า')->assertSee('นัดส่งช่วงเช้า')
        ->assertSee('2,535.90 บาท')->assertSee('2,485.90 บาท')->assertSee('ลงชื่อผู้มีอำนาจ / ผู้ซื้อ');
    $this->actingAs($this->cashier)->get($url)->assertOk()->assertSee('•••• 9385')
        ->assertDontSee('0812349385')->assertDontSee('buyer@example.test')->assertDontSee('9876543210123')
        ->assertDontSee('ที่อยู่ผู้ซื้อที่มีสิทธิ์เท่านั้น');

    $this->actingAs($this->reception)->get(route('repairs.quotations.create', $this->parameters))
        ->assertOk()->assertSee('value="buyer@example.test"', false)->assertSee('value="100.00"', false);
});

test('inclusive VAT is extracted from the total and invalid adjustments cannot be saved', function () {
    $this->actingAs($this->reception);
    $fields = ['items' => [['type' => 'labor', 'description' => 'สินค้าและติดตั้ง', 'quantity' => 1, 'unit_price' => '1070.00']]] + $this->fields;
    $this->post($this->store, ['document_discount' => '1070.01'] + $fields)->assertSessionHasErrors('document_discount');
    $this->post($this->store, ['withholding' => '1070.01'] + $fields)->assertSessionHasErrors('withholding');
    $this->post($this->store, ['vat_mode' => 'inclusive'] + $fields)->assertSessionHasErrors('vat_rate');
    $this->post($this->store, ['valid_until' => '2026-11-23'] + $fields)->assertSessionHasErrors('valid_until');
    $invalid = $fields;
    $invalid['items'][0]['discount'] = '1071.00';
    $this->post($this->store, $invalid)->assertSessionHasErrors('items.0.discount');
    $this->assertDatabaseCount('repair_quotations', 0);

    $this->post($this->store, ['vat_mode' => 'inclusive', 'vat_rate' => '7.00', 'buyer_phone' => '', 'buyer_address' => ''] + $fields)->assertSessionHasNoErrors();
    $quote = RepairQuotation::sole();
    expect($quote->total_satang)->toBe(107000)->and($quote->amount_breakdown['vat_satang'])->toBe(7000)
        ->and($quote->amount_breakdown['net_before_vat_satang'])->toBe(100000);
    $this->get(route('repairs.quotations.show', [...$this->parameters, $quote]))->assertOk()
        ->assertDontSee($this->intake['customer_phone'])->assertDontSee($this->intake['customer_address']);
});

test('only the recorded approver can attach a scoped signature and expiry applies to the actual confirmation date', function () {
    Storage::fake('local');
    $this->actingAs($this->reception)->post($this->store, ['valid_until' => '2026-11-24'] + $this->fields)->assertSessionHasNoErrors();
    $quote = RepairQuotation::sole();
    $parameters = [...$this->parameters, $quote];
    $signature = route('repairs.quotations.signature', $parameters);
    $this->get($signature)->assertNotFound();
    $this->post(route('repairs.quotations.submit-review', $parameters), ['request_reason' => 'ตรวจราคา'])->assertSessionHasNoErrors();
    $image = UploadedFile::fake()->createWithContent('signature.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aMRUAAAAASUVORK5CYII='));
    $decision = ['decision' => 'approved', 'decision_reason' => 'ตรวจแล้ว', 'signature' => $image];
    $decide = route('repairs.quotations.decide-review', $parameters);
    $this->patch($decide, $decision)->assertForbidden();
    $this->actingAs($this->manager)->patch($decide, $decision)->assertSessionHasNoErrors();
    $quote->refresh();
    Storage::disk('local')->assertExists($quote->signatureDirectory($this->branch).'/'.$quote->signature_filename);
    $this->get($signature)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $this->get(route('repairs.quotations.print', $parameters))->assertOk()->assertSee($signature, false)->assertSee('ผู้อนุมัติทดสอบ');
    $this->actingAs($this->technician)->get($signature)->assertForbidden();
    $this->travelTo(Carbon::parse('2026-11-25 10:00:00', 'Asia/Bangkok'));
    $this->actingAs($this->reception)->post(route('repairs.quotations.request-customer-approval', $parameters), [
        'request_channel' => 'phone',
    ])->assertSessionHasErrors('request_channel');
    $approve = route('repairs.quotations.approve', $parameters);
    $this->patch($approve, ['approval_channel' => 'phone', 'confirmation' => '1'])->assertSessionHasErrors('confirmation');
    $this->patch($approve, ['approval_channel' => 'phone', 'confirmation' => '1', 'approval_received_at' => '2026-11-24T17:00'])
        ->assertSessionHasNoErrors();
    expect($quote->fresh()->customer_confirmed_at->timezone('Asia/Bangkok')->format('Y-m-d H:i'))->toBe('2026-11-24 17:00');
});
