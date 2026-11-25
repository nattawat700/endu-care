<?php

use App\Enums\BranchRole;
use App\Enums\RepairStatus;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $organization = Organization::create(['code' => 'endu', 'name' => 'ENDU']);
    $this->branch = $organization->branches()->create(['code' => 'fix', 'name' => 'ENDU FIX']);
    $this->other = Organization::create(['code' => 'other', 'name' => 'Other organization'])
        ->branches()->create(['code' => 'care', 'name' => 'Other branch']);
    foreach (['hq' => BranchRole::Hq, 'stock' => BranchRole::Stock, 'frontdesk' => BranchRole::Reception,
        'tech' => BranchRole::Technician, 'otherTech' => BranchRole::Technician, 'cashier' => BranchRole::Cashier] as $name => $role) {
        $this->{$name} = User::factory()->create();
        $this->branch->memberships()->create(['user_id' => $this->{$name}->id, 'role' => $role]);
    }
    $this->other->memberships()->create(['user_id' => $this->hq->id, 'role' => BranchRole::Hq]);
    $this->warehouse = $this->branch->catalogWarehouses()->create(['name' => 'Main shop', 'kind' => 'sale']);
    $this->inspection = $this->branch->catalogWarehouses()->create(['name' => 'Inspection', 'kind' => 'inspection']);
    $this->location = $this->warehouse->locations()->create(['code' => 'A-01', 'name' => 'Shelf A']);
    $this->otherLocation = $this->inspection->locations()->create(['code' => 'H-01', 'name' => 'Hold shelf']);
    $this->product = $this->branch->catalogProducts()->create(['sku' => 'PART-X', 'name' => 'Repair part',
        'kind' => 'part', 'tracking' => 'quantity', 'unit' => 'piece', 'retail_satang' => 100000, 'cost_satang' => 30500, 'version' => 1]);
    $this->repair = $this->branch->repairs()->create(['submission_id' => (string) Str::uuid(), 'created_by' => $this->frontdesk->id,
        'customer_name' => 'Test customer', 'customer_phone' => '0811001100', 'device' => 'iPhone 13', 'issue' => 'Damaged screen']);
    $this->repair->status = RepairStatus::Diagnosing;
    $this->repair->technician_id = $this->tech->id;
    $this->repair->save();
    $this->receive = route('catalog.stock.store', $this->branch);
    $this->parts = route('repairs.parts.store', [$this->branch, $this->repair]);
    $this->fields = ['code' => $this->product->sku, 'warehouse_id' => $this->warehouse->id, 'location_id' => $this->location->id];
});

test('only owners and HQ configure warehouse types and storage locations scoped to the selected branch', function () {
    $settings = route('branch-settings.warehouses.index', $this->branch);
    $this->actingAs($this->stock)->get($settings)->assertForbidden();
    $this->actingAs($this->hq)->get($settings)->assertOk()->assertSee('คลังพักตรวจ')->assertSee('Shelf A');
    $this->post(route('branch-settings.warehouses.store', $this->branch), ['name' => 'New warehouse', 'kind' => 'unknown'])->assertSessionHasErrors('kind');
    $this->post(route('branch-settings.warehouses.store', $this->branch), ['name' => 'New warehouse', 'kind' => 'inspection'])->assertSessionHasNoErrors();
    $warehouse = $this->branch->catalogWarehouses()->where('name', 'New warehouse')->sole();
    $add = route('branch-settings.locations.store', [$this->branch, $warehouse]);
    $this->post($add, ['code' => 'a-01', 'name' => 'New shelf'])->assertSessionHasNoErrors();
    $this->post($add, ['code' => 'A-01', 'name' => 'Duplicate'])->assertSessionHasErrors('code');
    $location = $warehouse->locations()->sole();
    expect($location->code)->toBe('A-01');
    $this->patch(route('branch-settings.locations.update', [$this->branch, $warehouse, $location]), [
        'code' => 'A-02', 'name' => 'Renamed shelf',
    ])->assertSessionHasNoErrors();
    $this->patch(route('branch-settings.locations.update', [$this->branch, $this->warehouse, $location]), [
        'code' => 'B-01', 'name' => 'Wrong shelf',
    ])->assertNotFound();
    $this->patch(route('branch-settings.warehouses.update', [$this->other, $warehouse]), [
        'name' => 'Taken', 'kind' => 'sale',
    ])->assertNotFound();
    $this->assertDatabaseHas('audit_events', ['subject_type' => 'catalog_location', 'branch_id' => $this->branch->id, 'operation' => 'updated']);
});

test('repair part issues reduce stock at the exact shelf and partial returns restore stock and original moving average cost', function () {
    $this->actingAs($this->stock);
    $this->post($this->receive, ['quantity' => 2, 'receipt_cost' => '100.00', 'submission_id' => (string) Str::uuid()] + $this->fields)->assertSessionHasNoErrors();
    $this->post($this->receive, ['quantity' => 1, 'receipt_cost' => '100.01', 'submission_id' => (string) Str::uuid()] + $this->fields)->assertSessionHasNoErrors();
    $this->post($this->receive, ['quantity' => 18, 'receipt_cost' => '700.00', 'submission_id' => (string) Str::uuid(),
        'warehouse_id' => $this->inspection->id, 'location_id' => $this->otherLocation->id] + $this->fields)->assertSessionHasNoErrors();
    $this->get(route('catalog.pos', $this->branch))->assertOk()->assertViewHas('products', fn ($products) => (int) $products->first()->balances_sum_quantity === 3);
    $this->post($this->parts, ['quantity' => 1, 'warehouse_id' => $this->inspection->id, 'location_id' => $this->otherLocation->id,
        'submission_id' => (string) Str::uuid()] + $this->fields)->assertSessionHasErrors('warehouse_id');
    $this->post($this->parts, ['quantity' => 1, 'location_id' => $this->otherLocation->id, 'submission_id' => (string) Str::uuid()] + $this->fields)->assertNotFound();
    $this->post($this->parts, ['quantity' => 1, 'location_id' => null, 'submission_id' => (string) Str::uuid()] + $this->fields)->assertSessionHasErrors('location_id');

    $this->actingAs($this->tech)->get(route('repairs.parts.create', [$this->branch, $this->repair]))->assertOk()
        ->assertDontSee('Hold shelf')->assertSee('Shelf A')->assertDontSee('ต้นทุนรับเข้า');
    $input = ['quantity' => 3, 'submission_id' => (string) Str::uuid()] + $this->fields;
    $this->post($this->parts, $input)->assertSessionHasNoErrors();
    $this->post($this->parts, $input)->assertSessionHasNoErrors();
    $issue = $this->repair->catalogMovements()->sole();
    expect($issue->costForQuantity(3))->toBe(30001)->and($issue->catalog_location_id)->toBe($this->location->id);
    $balance = $this->product->balances()->where('catalog_warehouse_id', $this->warehouse->id)->sole();
    expect((int) $balance->quantity)->toBe(0)->and((int) $balance->locations()->sole()->quantity)->toBe(0);
    $print = route('catalog.movements.print', [$this->branch, $issue]);
    $this->get($print)->assertOk()->assertSee($this->repair->reference())->assertSee('Shelf A')->assertDontSee('300.01');
    $this->actingAs($this->otherTech)->get(route('repairs.parts.create', [$this->branch, $this->repair]))->assertNotFound();
    $this->post($this->parts, ['quantity' => 1, 'issue_id' => $issue->id, 'submission_id' => (string) Str::uuid()])->assertNotFound();
    $this->actingAs($this->frontdesk)->get(route('repairs.show', [$this->branch, $this->repair]))->assertOk()
        ->assertSee('เบิกหรือคืนอะไหล่')->assertDontSee('ต้นทุนอะไหล่สุทธิ');

    $this->product->update(['cost_satang' => 99000]);
    $this->actingAs($this->hq)->patch(route('branch-settings.locations.update', [$this->branch, $this->warehouse, $this->location]), [
        'code' => 'A-01', 'name' => 'New shelf name',
    ])->assertSessionHasNoErrors();
    $this->get(route('repairs.show', [$this->branch, $this->repair]))->assertSee('300.01 บาท');
    $this->get($print)->assertOk()->assertSee('300.01 บาท')->assertSee('Shelf A')->assertDontSee('New shelf name');
    $this->actingAs($this->cashier);
    $return = ['quantity' => 2, 'issue_id' => $issue->id, 'submission_id' => (string) Str::uuid()];
    $this->post($this->parts, $return)->assertSessionHasNoErrors();
    $this->post($this->parts, $return)->assertSessionHasNoErrors();
    $this->post($this->parts, ['quantity' => 2, 'submission_id' => (string) Str::uuid()] + $return)->assertSessionHasErrors('quantity');
    $this->actingAs($this->hq)->get(route('repairs.show', [$this->branch, $this->repair]))->assertSee('100.01 บาท');
    expect($issue->returns()->sole()->costForQuantity(2))->toBe(20000);
    $this->post($this->parts, ['quantity' => 1, 'submission_id' => (string) Str::uuid()] + $return)->assertSessionHasNoErrors();
    $this->actingAs($this->stock)->get(route('catalog.pos', $this->branch))->assertViewHas('products', fn ($products) => (int) $products->first()->balances_sum_quantity === 3);
    expect((int) $balance->refresh()->stock_value_satang)->toBe(30001)
        ->and((int) $balance->locations()->sole()->quantity)->toBe(3)
        ->and($this->repair->partsCost())->toBe(['total' => 0, 'pending' => 0]);
    $audit = AuditEvent::where('subject_reference', $issue->reference())->sole();
    expect($audit->after_values)->not->toHaveKeys(['total_cost_satang', 'unit_cost_satang']);
});

test('serialized repair parts keep their intake cost and location and may be returned after closure', function () {
    $this->product->update(['tracking' => 'serialized']);
    $input = ['imei' => '358000000000001', 'serial_number' => 'TEST-SERIAL', 'quantity' => 1] + $this->fields;
    $this->actingAs($this->stock)->post($this->receive, ['receipt_cost' => '412.32', 'submission_id' => (string) Str::uuid()] + $input)->assertSessionHasNoErrors();
    $this->product->update(['cost_satang' => 99999]);
    $this->actingAs($this->frontdesk)->post($this->parts, ['submission_id' => (string) Str::uuid()] + array_diff_key($input, ['location_id' => true]))->assertSessionHasNoErrors();
    $issue = $this->repair->catalogMovements()->sole();
    expect($issue->costForQuantity(1))->toBe(41232)->and($issue->catalog_location_id)->toBe($this->location->id)
        ->and($this->product->units()->sole()->available)->toBeFalse();
    $this->repair->status = RepairStatus::ReturnedUnrepaired;
    $this->repair->save();
    $this->post($this->parts, ['submission_id' => (string) Str::uuid()] + $input)->assertSessionHasErrors('code');
    $this->post($this->parts, ['quantity' => 1, 'issue_id' => $issue->id, 'submission_id' => (string) Str::uuid()])->assertSessionHasNoErrors();
    expect($issue->returns()->sole()->costForQuantity(1))->toBe(41232)->and($this->product->units()->sole()->available)->toBeTrue();
});

test('older stock without receipts of known cost remains usable and outstanding unknown cost is shown separately', function () {
    $this->product->balances()->create(['branch_id' => $this->branch->id, 'catalog_warehouse_id' => $this->warehouse->id, 'quantity' => 2]);
    $this->actingAs($this->frontdesk)->post($this->parts, ['quantity' => 1, 'location_id' => null, 'submission_id' => (string) Str::uuid()] + $this->fields)
        ->assertSessionHasNoErrors();
    $issue = $this->repair->catalogMovements()->sole();
    expect($issue->costForQuantity(1))->toBeNull();
    $this->actingAs($this->hq)->get(route('repairs.show', [$this->branch, $this->repair]))->assertOk()
        ->assertSee('มี 1 ใบเบิกคงค้างที่ยังไม่มีต้นทุนที่ยืนยันได้');
});
