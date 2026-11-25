<?php

use App\Enums\BranchRole;
use App\Enums\RepairStatus;
use App\Models\CatalogMovement;
use App\Models\CatalogProduct;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $organization = Organization::create(['code' => 'endu', 'name' => 'ENDU']);
    $this->branch = $organization->branches()->create(['code' => 'fix', 'name' => 'ENDU FIX']);
    $this->other = $organization->branches()->create(['code' => 'other', 'name' => 'สาขาอื่น']);
    $this->stock = User::factory()->create();
    $this->reception = User::factory()->create();
    $this->cashier = User::factory()->create();
    $this->technician = User::factory()->create();
    foreach ([[$this->stock, BranchRole::Stock], [$this->reception, BranchRole::Reception],
        [$this->cashier, BranchRole::Cashier], [$this->technician, BranchRole::Technician]] as [$user, $role]) {
        $this->branch->memberships()->create(['user_id' => $user->id, 'role' => $role]);
    }
    $this->other->memberships()->create(['user_id' => $this->stock->id, 'role' => BranchRole::Stock]);
    $this->product = ['sku' => 'screen-001', 'name' => 'หน้าจอทดสอบ OLED', 'kind' => 'part', 'tracking' => 'quantity',
        'category' => 'หน้าจอ', 'brand' => 'ENDU', 'unit' => 'ชิ้น', 'retail' => '850.00', 'dealer' => '750.00', 'partner' => '650.00',
        'cost' => '321.99', 'models' => "iPhone 12\niPhone 12 Pro", 'barcodes' => "8851111|ชิ้น|1\n8852222|กล่อง|5"];
    $this->repair = $this->branch->repairs()->create(['submission_id' => (string) Str::uuid(), 'created_by' => $this->reception->id,
        'customer_name' => 'ลูกค้าทดสอบ', 'customer_phone' => '0811111111', 'device' => 'iPhone 12', 'issue' => 'จอแตก']);
    $this->repair->status = RepairStatus::Diagnosing;
    $this->repair->save();
});

test('catalog supports shared models multi-unit scans and price visibility across catalog and quotation', function () {
    Storage::fake('local');
    $image = UploadedFile::fake()->createWithContent('sample.png', file_get_contents(public_path('images/endu-fix-receipt-logo.png')));
    $this->actingAs($this->stock)->get(route('catalog.create', $this->branch))->assertOk()->assertSee('รูปสินค้า');
    $this->post(route('catalog.store', $this->branch), ['image' => $image] + $this->product)->assertSessionHasNoErrors();
    $product = CatalogProduct::sole();
    expect($product->sku)->toBe('SCREEN-001')->and($product->cost_satang)->toBe(32199);
    $this->get(route('catalog.show', [$this->branch, $product]))->assertOk()->assertSee('321.99 บาท');
    $this->get(route('catalog.image', [$this->branch, $product]))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->get(route('catalog.show', [$this->other, $product]))->assertNotFound();
    $this->post(route('catalog.store', $this->branch), ['sku' => '8851111', 'barcodes' => ''] + $this->product)->assertSessionHasErrors('sku');
    $this->post(route('catalog.store', $this->branch), ['sku' => 'NEW-001', 'kind' => 'service'] + $this->product)->assertSessionHasErrors('tracking');
    $this->patch(route('catalog.update', [$this->branch, $product]), ['version' => 1, 'name' => 'หน้าจอทดสอบ OLED รุ่นใหม่'] + $this->product)->assertSessionHasNoErrors();
    $this->patch(route('catalog.update', [$this->branch, $product]), ['version' => 1] + $this->product)->assertSessionHasErrors('version');
    foreach (['iPhone 12', 'iPhone 12 Pro'] as $model) {
        $this->get(route('catalog.pos', [$this->branch, 'model' => $model, 'q' => '8852222', 'brand' => 'ENDU', 'unit' => 'ชิ้น']))
            ->assertOk()->assertSee('หน้าจอทดสอบ OLED รุ่นใหม่')->assertSee('เท่ากับ 5 หน่วยฐาน');
    }
    $this->actingAs($this->cashier)->get(route('catalog.show', [$this->branch, $product]))->assertOk()
        ->assertSee('850.00 บาท')->assertDontSee('750.00 บาท')->assertDontSee('321.99 บาท');
    $this->getJson(route('repairs.catalog-lookup', [$this->branch, $this->repair, 'q' => '8852222']))->assertForbidden();
    $this->actingAs($this->reception)->getJson(route('repairs.catalog-lookup', [$this->branch, $this->repair, 'q' => '8852222', 'model' => 'iPhone 12 Pro']))
        ->assertOk()->assertJsonPath('0.unit', 'กล่อง')->assertJsonPath('0.prices.dealer', '3750.00')->assertJsonMissingPath('0.prices.cost');
    $this->get(route('repairs.quotations.create', [$this->branch, $this->repair]))->assertOk()->assertSee('เลือกสินค้าจากทะเบียน');
});

test('CSV preview reports duplicate invalid new and updated rows before writing and exports only authorized selected fields', function () {
    $this->actingAs($this->stock)->post(route('catalog.store', $this->branch), $this->product)->assertSessionHasNoErrors();
    $preview = route('catalog.import.preview', $this->branch);
    $transfer = route('catalog.transfer', $this->branch);
    $header = "sku,name,kind,tracking,unit,retail\n";
    $duplicate = $header."NEW,ใหม่,part,quantity,ชิ้น,10\nNEW,ซ้ำ,part,quantity,ชิ้น,10\nBAD,,part,quantity,ชิ้น,10\n";
    $this->post($preview, ['sheet' => UploadedFile::fake()->createWithContent('invalid.csv', $duplicate)])->assertRedirect($transfer);
    $this->get($transfer)->assertOk()->assertSee('ซ้ำในไฟล์')->assertSee('แถว 4')->assertDontSee('ยืนยันนำเข้า');
    $this->assertDatabaseCount('catalog_products', 1);

    $valid = $header."SCREEN-001,หน้าจอใหม่,part,quantity,ชิ้น,855\nNEW,=2+3,service,none,ครั้ง,10\n";
    $this->post($preview, ['sheet' => UploadedFile::fake()->createWithContent('valid.csv', $valid)])->assertRedirect($transfer);
    $this->get($transfer)->assertOk()->assertSee('อัปเดต')->assertSee('เพิ่มใหม่')->assertSee('ยืนยันนำเข้า 2 รายการ');
    $this->assertDatabaseCount('catalog_products', 1);
    $token = session('catalog_import.'.$this->branch->id)['token'];
    $this->post(route('catalog.import.apply', $this->branch), ['token' => $token])->assertSessionHasNoErrors();
    $this->assertDatabaseCount('catalog_products', 2);
    $updated = CatalogProduct::where('sku', 'SCREEN-001')->sole();
    expect($updated->retail_satang)->toBe(85500)->and($updated->brand)->toBe('ENDU')
        ->and($updated->barcodes()->count())->toBe(2)->and($updated->compatibilities()->count())->toBe(2);

    $response = $this->actingAs($this->cashier)->get(route('catalog.export', [$this->branch, 'columns' => ['sku', 'name']]))->assertOk();
    expect($response->streamedContent())->toContain('sku,name', "'=2+3")->not->toContain('321.99', 'retail');
    $this->getJson(route('catalog.export', [$this->branch, 'columns' => ['sku', 'cost']]))->assertUnprocessable();
});

test('multiple warehouse receipts issues and returns preserve balance and print snapshots scoped to branch and work order', function () {
    $this->actingAs($this->stock)->post(route('catalog.store', $this->branch), $this->product)->assertSessionHasNoErrors();
    $this->post(route('catalog.warehouses.store', $this->branch), ['name' => 'คลังอะไหล่หลัก'])->assertSessionHasNoErrors();
    $warehouse = $this->branch->catalogWarehouses()->sole();
    $base = ['code' => '8852222', 'warehouse_id' => $warehouse->id, 'quantity' => 3, 'submission_id' => (string) Str::uuid()];
    $this->get(route('catalog.stock', $this->branch))->assertOk()->assertSee('คลังอะไหล่หลัก');
    $this->get(route('catalog.open-repair', [$this->branch, 'reference' => $this->repair->reference()]))->assertRedirectToRoute('repairs.parts.create', [$this->branch, $this->repair]);
    $this->get(route('repairs.parts.create', [$this->branch, $this->repair]))->assertOk()->assertSee('บันทึกใบเบิก');
    $this->post(route('catalog.stock.store', $this->branch), $base)->assertSessionHasNoErrors();
    $this->post(route('catalog.stock.store', $this->branch), $base)->assertSessionHasNoErrors();
    $parts = route('repairs.parts.store', [$this->branch, $this->repair]);
    $this->post($parts, ['submission_id' => (string) Str::uuid(), 'quantity' => 4] + $base)->assertSessionHasErrors('quantity');
    $this->post($parts, ['submission_id' => (string) Str::uuid(), 'quantity' => 2] + $base)->assertSessionHasNoErrors();
    $issue = $this->branch->catalogMovements()->where('kind', 'issue')->sole();
    expect((int) $issue->quantity)->toBe(10);
    $this->post($parts, ['submission_id' => (string) Str::uuid(), 'issue_id' => $issue->id, 'quantity' => 11])->assertSessionHasErrors('quantity');
    $this->post($parts, ['submission_id' => (string) Str::uuid(), 'issue_id' => $issue->id, 'quantity' => 4])->assertSessionHasNoErrors();
    $return = $this->branch->catalogMovements()->where('kind', 'return')->sole();
    expect((int) CatalogProduct::sole()->balances()->sole()->quantity)->toBe(9);
    $this->assertDatabaseCount('catalog_movements', 3);
    $print = route('catalog.movements.print', [$this->branch, $return]);
    $this->actingAs($this->cashier)->get($print)->assertOk()->assertSee('ใบคืนอะไหล่')->assertSee('SCREEN-001')
        ->assertSee('คลังอะไหล่หลัก')->assertSee($this->repair->reference())->assertSee($issue->reference())->assertSee('4 ชิ้น');
    $this->actingAs($this->technician)->get($print)->assertNotFound();
    $this->actingAs($this->stock)->get(route('catalog.movements.print', [$this->other, $return]))->assertNotFound();
});

test('serialized stock is received issued and returned by exact identifier and label runs include the requested copies', function () {
    $this->actingAs($this->stock)->post(route('catalog.store', $this->branch), [
        'sku' => 'PHONE-001', 'kind' => 'phone', 'tracking' => 'serialized', 'barcodes' => '',
    ] + $this->product)->assertSessionHasNoErrors();
    $product = CatalogProduct::sole();
    $warehouse = $this->branch->catalogWarehouses()->create(['name' => 'คลังรายเครื่อง']);
    $receive = route('catalog.stock.store', $this->branch);
    $input = ['code' => 'PHONE-001', 'warehouse_id' => $warehouse->id, 'imei' => '358111111111111', 'serial_number' => 'ABC123', 'quantity' => 1];
    $this->post($receive, ['submission_id' => (string) Str::uuid()] + $input)->assertSessionHasNoErrors();
    $this->post($receive, ['submission_id' => (string) Str::uuid()] + $input)->assertSessionHasErrors('serial_number');
    $parts = route('repairs.parts.store', [$this->branch, $this->repair]);
    $this->post($parts, ['imei' => '358222222222222', 'submission_id' => (string) Str::uuid()] + $input)->assertSessionHasErrors('serial_number');
    $this->post($parts, ['submission_id' => (string) Str::uuid()] + $input)->assertSessionHasNoErrors();
    expect($product->units()->sole()->available)->toBeFalse();
    $issue = CatalogMovement::where('kind', 'issue')->sole();
    $this->post($parts, ['submission_id' => (string) Str::uuid(), 'quantity' => 1, 'issue_id' => $issue->id])->assertSessionHasNoErrors();
    expect($product->units()->sole()->available)->toBeTrue();
    $print = route('catalog.labels', [$this->branch, $product, 'type' => 'imei', 'identifier' => $input['imei'], 'size' => '60x30', 'copies' => 3]);
    $content = $this->get($print)->assertOk()->assertSee('IMEI '.$input['imei'])->getContent();
    expect(substr_count($content, '<article class="catalog-label">'))->toBe(3);
    $this->actingAs($this->cashier)->get(route('catalog.labels', [$this->branch, $product, 'type' => 'price', 'group' => 'dealer', 'size' => '60x30', 'copies' => 1]))->assertForbidden();
});
