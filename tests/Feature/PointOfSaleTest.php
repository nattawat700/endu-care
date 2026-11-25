<?php

use App\Enums\BranchRole;
use App\Models\Organization;
use App\Models\User;
use App\Support\CatalogStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->org = Organization::create(['code' => 'endu', 'name' => 'ENDU', 'approval_rules' => ['discount' => ['limit' => 1000, 'review_role' => 'manager']]]);
    $this->branch = $this->org->branches()->create(['code' => 'fix', 'name' => 'ENDU FIX']);
    $this->other = $this->org->branches()->create(['code' => 'other', 'name' => 'อื่น']);
    foreach (['pc' => BranchRole::Pc, 'anotherPc' => BranchRole::Pc, 'cashier' => BranchRole::Cashier, 'manager' => BranchRole::Manager, 'tech' => BranchRole::Technician] as $key => $role) {
        $this->{$key} = User::factory()->create();
        $this->branch->memberships()->create(['user_id' => $this->{$key}->id, 'role' => $role]);
    }
    $this->other->memberships()->create(['user_id' => $this->cashier->id, 'role' => BranchRole::Cashier]);
    $point = $this->branch->paymentPoints()->create(['code' => 'cash', 'name' => 'จุดรับเงิน']);
    $printer = $this->branch->printerProfiles()->create(['name' => 'A4', 'device_name' => 'A4', 'paper' => 'a4']);
    $this->front = $this->branch->counters()->create(['name' => 'หน้าร้าน', 'code' => 'front', 'payment_point_id' => $point->id, 'printer_profile_id' => $printer->id]);
    $this->back = $this->branch->counters()->create(['name' => 'หลังร้าน', 'code' => 'back', 'payment_point_id' => $point->id, 'printer_profile_id' => $printer->id]);
    $this->warehouse = $this->branch->catalogWarehouses()->create(['name' => 'คลังขาย', 'kind' => 'sale']);
    $this->inspection = $this->branch->catalogWarehouses()->create(['name' => 'พักตรวจ', 'kind' => 'inspection']);
    $this->item = $this->branch->catalogProducts()->create([
        'sku' => 'CABLE-1', 'name' => 'สายชาร์จ', 'kind' => 'accessory', 'tracking' => 'quantity',
        'unit' => 'เส้น', 'retail_satang' => 10000, 'dealer_satang' => 8000, 'partner_satang' => 7000, 'cost_satang' => 4000,
    ]);
    $this->pack = $this->item->barcodes()->create(['branch_id' => $this->branch->id, 'code' => '88510000', 'unit' => 'แพ็ก', 'multiplier' => 2]);
    $this->item->compatibilities()->create(['device_model' => 'iPhone 16']);
    $this->service = $this->branch->catalogProducts()->create([
        'sku' => 'LABOR-1', 'name' => 'ค่าแรง', 'kind' => 'labor', 'tracking' => 'none', 'unit' => 'ครั้ง', 'retail_satang' => 5000,
    ]);
});

function posPayload(object $test, array $overrides = []): array
{
    return $overrides + [
        'submission_id' => (string) Str::uuid(), 'counter_id' => $test->front->id, 'customer_mode' => 'walkin', 'price_group' => 'retail',
        'items' => [['product_id' => $test->item->id, 'quantity' => 1]],
    ];
}

function posOpen(object $test, User $operator, $counter): void
{
    $test->actingAs($operator)->post(route('cash-shifts.open', $test->branch), [
        'submission_id' => (string) Str::uuid(), 'counter_id' => $counter->id, 'opening_cash' => '400',
    ])->assertSessionHasNoErrors();
}

function posReceive(object $test, $item, $warehouse, int $qty, array $data = [], $location = null): void
{
    CatalogStock::move($test->branch, $item, $warehouse, 'receive', $qty, $data + ['submission_id' => (string) Str::uuid()], $test->manager->id, location: $location);
}

test('looks up branch barcodes and model, holds a mixed sale, and completes cash once at the right counter', function () {
    posReceive($this, $this->item, $this->warehouse, 2);
    $this->actingAs($this->tech)->get(route('pos.index', $this->branch))->assertForbidden();
    $this->actingAs($this->pc)->get(route('pos.index', $this->branch))->assertOk()
        ->assertSee('endu-sidebar--desktop', false)->assertSee('pos-workspace', false)
        ->assertSee('เลือกสินค้า')->assertSee('ตะกร้าขาย')->assertSee('ส่งให้แคชเชียร์')->assertSee('สแกนบาร์โค้ดหรือค้นสินค้า');
    $this->get(route('pos.create', $this->branch))->assertOk()->assertSee('ตะกร้าขาย');
    $this->get(route('pos.features', $this->branch))->assertOk()->assertSee('endu-sidebar--desktop', false)->assertSee('POS-01')->assertSee('POS-11');
    posReceive($this, $this->item, $this->inspection, 4);
    $this->getJson(route('pos.products', $this->branch))
        ->assertJsonPath('items.0.available', 2)->assertJsonPath('items.1.available', null)->assertJsonMissingPath('items.0.prices.partner');
    $this->getJson(route('pos.products', [$this->branch, 'after' => $this->item->id]))
        ->assertJsonCount(1, 'items')->assertJsonPath('items.0.sku', $this->service->sku);
    $this->getJson(route('pos.products', [$this->branch, 'q' => '88510000']))
        ->assertJsonPath('0.multiplier', 2)->assertJsonPath('0.prices.retail', 20000)->assertJsonMissingPath('0.prices.partner');
    $this->getJson(route('pos.products', [$this->branch, 'q' => 'iPhone 16']))->assertJsonPath('0.product_id', $this->item->id);
    $this->getJson(route('pos.products', [$this->branch, 'q' => 'CABLE']))->assertJsonCount(1);
    $this->getJson(route('pos.products', [$this->branch, 'q' => '%']))->assertExactJson([]);
    $this->post(route('pos.store', $this->branch), posPayload($this, ['price_group' => 'partner']))->assertForbidden();
    $data = posPayload($this, ['items' => [
        ['product_id' => $this->item->id, 'barcode' => $this->pack->code, 'quantity' => 1, 'discount' => '5'],
        ['product_id' => $this->service->id, 'quantity' => 1],
    ], 'document_discount' => '5', 'customer_mode' => 'new', 'new_customer' => [
        'name' => 'ลูกค้าทดสอบ', 'phone' => '0812345678', 'billing_address' => 'ที่อยู่ออกเอกสาร',
    ]]);
    $this->post(route('pos.store', $this->branch), ['intent' => 'hold'] + $data)
        ->assertRedirectToRoute('pos.history', [$this->branch, 'status' => 'draft']);
    $sale = $this->branch->posSales()->sole();
    $args = [$this->branch, $sale];
    $this->post(route('pos.store', $this->branch), $data)->assertRedirectToRoute('pos.show', $args);
    expect($sale->total_satang)->toBe(24000)->and($sale->salesperson_id)->toBe($this->pc->id);
    expect($this->item->balances()->where('catalog_warehouse_id', $this->warehouse->id)->sole()->quantity)->toBe(2);
    $this->get(route('pos.edit', $args))->assertOk()->assertSee('แก้ไขตะกร้า');
    $this->get(route('pos.receipt', $args))->assertNotFound();
    $this->actingAs($this->anotherPc)->get(route('pos.show', $args))->assertNotFound();
    $this->actingAs($this->cashier)->get(route('pos.show', [$this->other, $sale]))->assertNotFound();

    posOpen($this, $this->cashier, $this->back);
    $this->get(route('pos.show', $args))->assertNotFound();
    $this->post(route('pos.checkout', $args), ['version' => 2, 'tendered' => '250'])->assertNotFound();
    $backShift = $this->branch->cashShifts()->sole();
    $this->patch(route('cash-shifts.close', [$this->branch, $backShift]), ['counted_cash' => '400', 'counted_transfer' => '0'])->assertSessionHasNoErrors();
    posOpen($this, $this->cashier, $this->front);
    $this->get(route('pos.history', [$this->branch, 'status' => 'draft']))->assertOk()->assertSee($sale->reference());
    $this->get(route('pos.show', $args))->assertOk()->assertSee('ยืนยันรับเงินสด');
    $this->patch(route('pos.update', $args), posPayload($this, [
        'intent' => 'checkout', 'version' => 1, 'items' => $data['items'], 'document_discount' => '5',
        'customer_mode' => 'existing', 'customer_id' => $sale->customer_id,
    ]))->assertRedirectToRoute('pos.show', [...$args, 'focus' => 'payment']);
    $this->get(route('pos.show', [...$args, 'focus' => 'payment']))->assertOk()->assertSee('data-pos-focus-payment', false);
    $this->post(route('pos.checkout', $args), ['version' => 2, 'tendered' => '200'])->assertSessionHasErrors('tendered');
    $this->post(route('pos.checkout', $args), ['version' => 2, 'tendered' => '250'])->assertRedirectToRoute('pos.show', $args);
    $this->post(route('pos.checkout', $args), ['version' => 2, 'tendered' => '250'])->assertRedirectToRoute('pos.show', $args);
    expect($sale->refresh()->status)->toBe('paid')->and($sale->movements()->count())->toBe(1)->and($sale->tenders()->count())->toBe(1);
    expect($this->item->balances()->where('catalog_warehouse_id', $this->warehouse->id)->sole()->quantity)->toBe(0);
    $shift = $this->branch->cashShifts()->whereNull('closed_at')->sole();
    expect($shift->totals()['cash_expected'])->toBe(64000);
    $this->get(route('cash-shifts.show', [$this->branch, $shift]))->assertOk()->assertSee($sale->reference())->assertSee('640.00');
    $this->get(route('pos.receipt', $args))->assertOk()->assertSee('ENDU FIX')->assertSee('ใบเสร็จหน้าร้าน')->assertSee('240.00');
    $this->actingAs($this->manager)->get(route('crm.customers.show', [$this->branch, $sale->customer]))->assertOk()->assertSee($sale->reference());
});

test('requires an available IMEI in sale stock for phone sales and rolls back if a second bill claims the same device', function () {
    $phone = $this->branch->catalogProducts()->create([
        'sku' => 'PHONE1', 'name' => 'มือถือ', 'kind' => 'phone', 'tracking' => 'serialized', 'unit' => 'เครื่อง', 'retail_satang' => 30000,
    ]);
    posReceive($this, $phone, $this->warehouse, 1, ['imei' => '123456789012345']);
    posReceive($this, $phone, $this->inspection, 1, ['imei' => '987654321098765']);
    posOpen($this, $this->cashier, $this->front);
    $this->getJson(route('pos.units', [$this->branch, $phone->id]))->assertJsonCount(1)->assertJsonFragment(['label' => '123456789012345 · คลังขาย']);
    $this->getJson(route('pos.products', [$this->branch, 'q' => 'PHONE1']))->assertJsonPath('0.available', 1);
    $unit = $phone->units()->whereNotNull('imei')->where('catalog_warehouse_id', $this->warehouse->id)->sole();
    $parked = $phone->units()->where('catalog_warehouse_id', $this->inspection->id)->sole();
    $this->post(route('pos.store', $this->branch), posPayload($this, ['items' => [['product_id' => $phone->id, 'quantity' => 1]]]))->assertSessionHasErrors('items.0.unit_id');
    $this->post(route('pos.store', $this->branch), posPayload($this, ['items' => [['product_id' => $phone->id, 'unit_id' => $parked->id, 'quantity' => 1]]]))->assertSessionHasErrors('items.0.unit_id');
    posReceive($this, $this->item, $this->warehouse, 1);

    $line = ['product_id' => $phone->id, 'unit_id' => $unit->id, 'quantity' => 1];
    $this->post(route('pos.store', $this->branch), posPayload($this, ['items' => [$line]]))->assertSessionHasNoErrors();
    $this->post(route('pos.store', $this->branch), posPayload($this, ['items' => [['product_id' => $this->item->id, 'quantity' => 1], $line]]))->assertSessionHasNoErrors();
    [$first, $second] = $this->branch->posSales()->orderBy('id')->get()->all();
    $this->post(route('pos.checkout', [$this->branch, $first]), ['version' => 1, 'tendered' => '300'])->assertSessionHasNoErrors();
    $this->post(route('pos.checkout', [$this->branch, $second]), ['version' => 1, 'tendered' => '400'])->assertSessionHasErrors('items');
    expect($second->refresh()->status)->toBe('draft')->and($second->movements()->count())->toBe(0)->and($second->tenders()->count())->toBe(0);
    expect($this->item->balances()->sole()->quantity)->toBe(1)->and($unit->refresh()->available)->toBeFalse();
});

test('keeps the saved group price, binds discount requests to the draft and checks prices again before selling', function () {
    posReceive($this, $this->item, $this->warehouse, 1);
    posOpen($this, $this->cashier, $this->front);
    $this->getJson(route('pos.products', [$this->branch, 'q' => 'CABLE-1']))->assertJsonPath('0.prices.partner', 7000);
    $data = posPayload($this, ['price_group' => 'partner', 'document_discount' => '20']);
    $this->post(route('pos.store', $this->branch), $data)->assertSessionHasNoErrors();
    $sale = $this->branch->posSales()->sole();
    $args = [$this->branch, $sale];
    expect($sale->total_satang)->toBe(5000);
    $this->post(route('pos.checkout', $args), ['version' => 1, 'tendered' => '50'])->assertSessionHasErrors('tendered');
    $this->post(route('pos.approval', $args), ['version' => 1, 'reason' => 'ส่วนลดหน้าร้าน'])->assertSessionHasNoErrors();
    $approval = $sale->refresh()->approval;
    $decision = route('organization-approvals.decide', [$this->branch, $approval]);
    $this->patch($decision, ['status' => 'approved', 'decision_reason' => 'OK'])->assertForbidden();
    $this->patch(route('pos.update', $args), ['version' => 1] + $data)->assertSessionHasNoErrors();
    expect($approval->refresh()->status)->toBe('withdrawn');
    $this->post(route('pos.approval', $args), ['version' => 2, 'reason' => 'ส่วนลดหน้าร้าน'])->assertSessionHasNoErrors();
    $approval = $sale->refresh()->approval;
    $this->actingAs($this->manager)->get(route('organization-approvals.index', $this->branch))->assertOk()->assertSee($sale->reference());
    $this->patch(route('organization-approvals.decide', [$this->branch, $approval]), ['status' => 'approved', 'decision_reason' => 'OK'])->assertSessionHasNoErrors();
    $this->item->update(['partner_satang' => 7500, 'version' => 2]);
    $this->actingAs($this->cashier)->get(route('pos.show', $args))->assertOk()->assertSee('50.00');
    $this->post(route('pos.checkout', $args), ['version' => 2, 'tendered' => '50'])->assertSessionHasErrors('items');
    $this->patch(route('pos.update', $args), ['version' => 1] + $data)->assertConflict();
    $this->patch(route('pos.update', $args), ['version' => 2] + $data)->assertSessionHasNoErrors();
    expect($sale->refresh()->total_satang)->toBe(5500)->and($sale->approval_id)->toBeNull();
    $this->post(route('pos.checkout', $args), ['version' => 3, 'tendered' => '55'])->assertSessionHasErrors('tendered');
});

test('records seller credit changes and restores the warehouse position and cash in the refund shift without changing an old close', function () {
    $bin = $this->warehouse->locations()->create(['code' => 'A1', 'name' => 'ชั้น 1']);
    posReceive($this, $this->item, $this->warehouse, 1);
    posReceive($this, $this->item, $this->warehouse, 1, location: $bin);
    posOpen($this, $this->cashier, $this->front);
    $this->post(route('pos.store', $this->branch), posPayload($this, ['salesperson_id' => $this->pc->id, 'items' => [
        ['product_id' => $this->item->id, 'quantity' => 2],
    ]]))->assertSessionHasNoErrors();
    $sale = $this->branch->posSales()->sole();
    $args = [$this->branch, $sale];
    $this->post(route('pos.checkout', $args), ['version' => 1, 'tendered' => '200'])->assertSessionHasNoErrors();
    $this->post(route('pos.void', $args), ['version' => 1, 'reason' => 'คืนสินค้า'])->assertForbidden();
    $original = $this->branch->cashShifts()->sole();
    $this->patch(route('cash-shifts.close', [$this->branch, $original]), ['counted_cash' => '600', 'counted_transfer' => '0'])->assertSessionHasNoErrors();
    $this->actingAs($this->manager)->post(route('pos.void', $args), ['version' => 1, 'reason' => 'คืนสินค้า'])->assertSessionHasErrors('reason');
    $this->post(route('pos.credit', $args), ['version' => 1, 'salesperson_id' => $this->anotherPc->id, 'reason' => 'แก้ผู้ดูแลลูกค้า'])->assertSessionHasNoErrors();
    expect($sale->creditChanges()->sole()->previous_user_id)->toBe($this->pc->id);
    expect($sale->refresh()->salesperson_id)->toBe($this->anotherPc->id);
    posOpen($this, $this->manager, $this->back);

    $this->post(route('pos.void', $args), ['version' => 1, 'reason' => 'คืนสินค้า'])->assertConflict();
    $this->post(route('pos.void', $args), ['version' => 2, 'reason' => 'คืนสินค้า'])->assertSessionHasNoErrors();
    $this->post(route('pos.void', $args), ['version' => 2, 'reason' => 'คืนสินค้า'])->assertSessionHasNoErrors();
    $current = $this->branch->cashShifts()->whereNull('closed_at')->sole();
    expect($current->totals()['cash_expected'])->toBe(20000)->and($original->refresh()->closing['cash_expected'])->toBe(60000);
    $balance = $this->item->balances()->sole();
    expect($balance->quantity)->toBe(2)->and($balance->locations()->sole()->quantity)->toBe(1)->and($balance->stock_value_satang)->toBe(8000);
    expect($sale->movements()->where('kind', 'sale_return')->count())->toBe(2);
    expect($sale->tenders()->count())->toBe(2);
    $this->get(route('pos.receipt', $args))->assertNotFound();
    $this->assertDatabaseHas('audit_events', ['subject_type' => 'pos_sale', 'subject_reference' => $sale->reference(), 'actor_id' => $this->manager->id]);
});

test('keeps customer search, credit and paused sales scoped to the branch and staff permissions', function () {
    $this->actingAs($this->pc);
    $customer = $this->other->customers()->create([
        'submission_id' => (string) Str::uuid(), 'name' => 'Other Name', 'phone' => '0892119090', 'name_key' => 'other name',
        'phone_key' => '0892119090', 'marketing_preference' => 'unspecified', 'service_channel' => 'phone',
    ]);
    $this->postJson(route('pos.customers', $this->branch), ['q' => 'Other Name'])->assertExactJson([]);
    $this->post(route('pos.store', $this->branch), posPayload($this, ['customer_mode' => 'existing', 'customer_id' => $customer->id]))->assertNotFound();
    $this->post(route('pos.store', $this->branch), posPayload($this, ['salesperson_id' => $this->anotherPc->id]))->assertForbidden();
    $this->post(route('pos.store', $this->branch), posPayload($this, ['customer_mode' => 'new', 'new_customer' => ['name' => 'ลูกค้าทดสอบ', 'phone' => '0812345678']]))
        ->assertSessionHasNoErrors();
    $sale = $this->branch->posSales()->sole();
    $this->postJson(route('pos.customers', $this->branch), ['q' => '0812345678'])
        ->assertJsonPath('0.label', 'ลูกค้าทดสอบ · ••••5678')->assertDontSee('0812345678');
    $this->post(route('pos.store', $this->branch), posPayload($this, ['customer_mode' => 'new', 'new_customer' => ['name' => 'ซ้ำ', 'phone' => '0812345678']]))
        ->assertSessionHasErrors('new_customer.phone');
    $this->post(route('pos.void', [$this->branch, $sale]), ['version' => 1, 'reason' => 'ลูกค้าเปลี่ยนใจ'])->assertSessionHasNoErrors();
    expect($sale->refresh()->status)->toBe('voided')->and($sale->tenders()->count())->toBe(0)->and($sale->movements()->count())->toBe(0);
});

test('takes an approved discount and rejects a later checkout for the last quantity unit', function () {
    posReceive($this, $this->item, $this->warehouse, 1);
    posOpen($this, $this->cashier, $this->front);
    $this->post(route('pos.store', $this->branch), posPayload($this, ['document_discount' => '15']))->assertSessionHasNoErrors();
    $this->post(route('pos.store', $this->branch), posPayload($this))->assertSessionHasNoErrors();
    [$first, $second] = $this->branch->posSales()->orderBy('id')->get()->all();
    $args = [$this->branch, $first];
    $this->post(route('pos.approval', $args), ['version' => 1, 'reason' => 'ส่วนลดลูกค้า'])->assertSessionHasNoErrors();
    $this->actingAs($this->manager)->patch(route('organization-approvals.decide', [$this->branch, $first->refresh()->approval]), [
        'status' => 'approved', 'decision_reason' => 'ตรวจส่วนลดแล้ว',
    ])->assertSessionHasNoErrors();
    $this->actingAs($this->cashier)->post(route('pos.checkout', $args), ['version' => 1, 'tendered' => '85'])->assertSessionHasNoErrors();
    $this->post(route('pos.checkout', [$this->branch, $second]), ['version' => 1, 'tendered' => '100'])->assertSessionHasErrors('items');
    expect($first->refresh()->status)->toBe('paid')->and($second->refresh()->status)->toBe('draft');
    expect($this->item->balances()->sole()->quantity)->toBe(0)->and($second->tenders()->count())->toBe(0);
});
