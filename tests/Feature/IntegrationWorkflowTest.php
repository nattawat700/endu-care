<?php

use App\Enums\BranchRole;
use App\Models\IntegrationConnection;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $organization = Organization::create(['code' => 'endu', 'name' => 'ENDU']);
    $this->branch = $organization->branches()->create(['code' => 'fix', 'name' => 'ENDU FIX']);
    $this->other = Organization::create(['code' => 'other', 'name' => 'กิจการอื่น'])
        ->branches()->create(['code' => 'shop', 'name' => 'สาขาอื่น']);
    $this->manager = User::factory()->create();
    $this->otherManager = User::factory()->create();
    $this->reception = User::factory()->create();
    foreach ([[$this->branch, $this->manager, BranchRole::Manager], [$this->other, $this->otherManager, BranchRole::Manager],
        [$this->branch, $this->reception, BranchRole::Reception]] as [$branch, $user, $role]) {
        $branch->memberships()->create(['user_id' => $user->id, 'role' => $role]);
    }
    $this->warehouse = $this->branch->catalogWarehouses()->create(['name' => 'Main']);
    $this->secret = str_repeat('TestKey', 6);
    $this->actingAs($this->manager);
});

function integrationConnection($test, string $kind, string $account, array $options = []): IntegrationConnection
{
    $test->post(route('integrations.connections.store', $test->branch), $options + [
        'kind' => $kind, 'account_ref' => $account, 'name' => strtoupper($kind), 'enabled' => 1, 'webhook_secret' => $test->secret,
    ])->assertSessionHasNoErrors()->assertRedirect();

    return $test->branch->integrationConnections()->where('kind', $kind)->where('account_ref', $account)->sole();
}

function integrationEvent($test, IntegrationConnection $connection, string $id, array $data, array $options = [])
{
    $topic = ['endu' => 'catalog.product', 'store' => 'order.upsert', 'shipping' => 'shipment.updated'][$connection->kind];
    $body = json_encode($options + ['event_id' => $id, 'account' => $connection->account_ref, 'topic' => $topic, 'data' => $data], JSON_THROW_ON_ERROR);
    $timestamp = $options['timestamp'] ?? (string) now()->timestamp;
    $signature = hash_hmac('sha256', $timestamp.'.'.$connection->public_id.'.'.$body, $options['key'] ?? $test->secret);

    return $test->call('POST', route('integrations.webhook', $connection->public_id), [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_X_ENDU_TIMESTAMP' => $timestamp, 'HTTP_X_ENDU_SIGNATURE' => 'sha256='.$signature,
    ], $body);
}

function integrationPreview($test, string $kind, string $contents)
{
    $test->post(route('integrations.imports.preview', $test->branch), [
        'kind' => $kind, 'sheet' => UploadedFile::fake()->createWithContent('import.csv', $contents),
    ])->assertSessionHasNoErrors()->assertRedirect();

    return $test->branch->integrationImports()->latest('id')->firstOrFail();
}

function integrationProduct($test, string $sku, array $attributes = [])
{
    return $test->branch->catalogProducts()->create($attributes + [
        'sku' => $sku, 'name' => $sku, 'kind' => 'part', 'tracking' => 'quantity', 'unit' => 'ชิ้น',
        'retail_satang' => 10000, 'cost_satang' => 2500, 'version' => 1,
    ]);
}

test('product customer and opening imports preview all dispositions and keep retries and branch data safe', function () {
    $this->get(route('integrations.index', $this->branch))->assertOk();
    $header = "sku,name,kind,tracking,unit,retail,cost\n";
    $original = "TEST-1,Original,part,quantity,piece,200.00,75.00\n";
    $batch = integrationPreview($this, 'products', $header.$original);
    $this->get(route('integrations.imports.show', [$this->branch, $batch]))->assertOk()->assertSee('TEST-1');
    $this->assertDatabaseEmpty('catalog_products');
    $this->post(route('integrations.imports.apply', [$this->branch, $batch]))->assertSessionHasNoErrors();
    $this->post(route('integrations.imports.apply', [$this->branch, $batch]))->assertSessionHasNoErrors();
    expect($batch->refresh()->status)->toBe('applied')->and($batch->payload)->toBeNull();
    $product = $this->branch->catalogProducts()->sole();
    $updated = str_replace('Original', 'Updated', $original);
    $batch = integrationPreview($this, 'products', $header.$updated."TEST-2,New,part,quantity,piece,100.00,20.00\n");
    expect(array_values(array_column($batch->report, 'status')))->toBe(['update', 'create']);
    $this->post(route('integrations.imports.apply', [$this->branch, $batch]))->assertSessionHasNoErrors();
    expect($product->refresh()->name)->toBe('Updated');
    $batch = integrationPreview($this, 'products', $header.$updated."Z,Invalid,unknown,quantity,piece,5,1\n");
    expect(array_values(array_column($batch->report, 'status')))->toBe(['skip', 'error']);
    $this->get(route('integrations.imports.show', [$this->branch, $batch]))->assertOk()->assertSee('ผิดพลาด 1');
    $this->post(route('integrations.imports.apply', [$this->branch, $batch]))->assertSessionHasErrors('sheet');
    $csv = $this->get(route('integrations.imports.report', [$this->branch, $batch]))->assertOk()->streamedContent();
    expect($csv)->toContain('row,reference,status,reason,record_id', 'skip', 'error');

    $customer = integrationPreview($this, 'customers', "name,phone\nCustomer one,0812341234\n");
    $this->post(route('integrations.imports.apply', [$this->branch, $customer]))->assertSessionHasNoErrors();
    expect($this->branch->customers()->sole()->phone)->toBe('0812341234');
    $this->get(route('integrations.imports.show', [$this->branch, $customer]))->assertOk()->assertDontSee('0812341234');
    $this->actingAs($this->otherManager)->get(route('integrations.imports.show', [$this->other, $customer]))->assertNotFound();
    $this->actingAs($this->reception)->get(route('integrations.imports.index', $this->branch))->assertForbidden();
    $this->actingAs($this->manager);

    $serialProduct = integrationProduct($this, 'PHONE-1', ['kind' => 'phone', 'tracking' => 'serialized']);
    $openingCsv = "sku,warehouse,quantity,serial_number,imei\nTEST-1,Main,4,,\nPHONE-1,Main,1,SERIAL-01,351234567890123\n";
    $opening = integrationPreview($this, 'opening', $openingCsv);
    $this->assertDatabaseEmpty('catalog_movements');
    $this->post(route('integrations.imports.apply', [$this->branch, $opening]))->assertSessionHasNoErrors();
    expect((int) $product->balances()->sole()->quantity)->toBe(4)->and($serialProduct->units()->sole()->imei)->toBe('351234567890123');
    expect(array_values(array_column(integrationPreview($this, 'opening', $openingCsv)->report, 'status')))->toBe(['skip', 'skip']);
    $stale = integrationPreview($this, 'products', $header.str_replace('Updated', 'Old preview', $updated));
    $product->update(['name' => 'Newer update', 'version' => $product->version + 1]);
    $this->post(route('integrations.imports.apply', [$this->branch, $stale]))->assertSessionHas('import_notice');
    expect($product->refresh()->name)->toBe('Newer update')->and($stale->refresh()->status)->toBe('expired');
});

test('scanner resolves scoped SKU barcode and IMEI and uses the selected branch printer paper', function () {
    $product = integrationProduct($this, 'PHONE-001', ['kind' => 'phone', 'tracking' => 'serialized']);
    $product->barcodes()->create(['branch_id' => $this->branch->id, 'code' => '885098765', 'unit' => 'ชิ้น', 'multiplier' => 1]);
    $this->post(route('catalog.stock.store', $this->branch), [
        'code' => $product->sku, 'warehouse_id' => $this->warehouse->id, 'quantity' => 1, 'imei' => '358123456789012',
        'serial_number' => 'UNIT-01', 'submission_id' => (string) Str::uuid(),
    ])->assertSessionHasNoErrors();
    foreach (['phone-001', '885098765', '358123456789012', 'unit-01'] as $code) {
        $this->get(route('integrations.devices', [$this->branch, 'code' => $code]))->assertOk()->assertSee('PHONE-001');
    }
    $this->get(route('integrations.devices', [$this->branch, 'code' => '358123456789012']))->assertSee('UNIT-01');
    $sizes = ['a4' => '210mm 297mm', 'a5' => '210mm 148mm', 'thermal_80' => '80mm 100mm', 'thermal_58' => '58mm 100mm'];
    foreach ($sizes as $paper => $size) {
        $this->post(route('branch-settings.printer-profiles.store', $this->branch), [
            'name' => 'Printer '.$paper, 'paper' => $paper, 'device_name' => 'Test printer',
        ])->assertSessionHasNoErrors();
        $profile = $this->branch->printerProfiles()->where('paper', $paper)->sole();
        $this->get(route('integrations.devices.print', [$this->branch, $profile]))->assertOk()->assertSee($size)->assertSee('Test printer');
    }
    $this->actingAs($this->otherManager)->get(route('integrations.devices.print', [$this->other, $profile]))->assertNotFound();
    $this->get(route('integrations.devices', [$this->other, 'code' => '358123456789012']))->assertOk()->assertDontSee('UNIT-01');
});

test('webhook authentication replay deduplication and protected connection settings are scoped to the source', function () {
    $connection = integrationConnection($this, 'endu', 'catalog-1');
    $product = integrationProduct($this, 'PART-001');
    $product->compatibilities()->create(['device_model' => 'iPhone 13']);
    $data = ['external_sku' => 'SKUZA-001', 'revision' => 2, 'name' => 'External part', 'device_model' => 'iPhone 13'];
    integrationEvent($this, $connection, 'ev-1', $data, ['key' => 'wrong'])->assertUnauthorized();
    integrationEvent($this, $connection, 'ev-1', $data, ['account' => 'other-account'])->assertUnauthorized();
    integrationEvent($this, $connection, 'ev-1', $data, ['timestamp' => (string) now()->subMinutes(6)->timestamp])->assertUnauthorized();
    integrationEvent($this, $connection, 'ev-1', $data)->assertOk()->assertJsonPath('status', 'applied');
    integrationEvent($this, $connection, 'ev-1', $data)->assertOk()->assertJsonPath('status', 'duplicate');
    integrationEvent($this, $connection, 'ev-1', ['revision' => 3] + $data)->assertConflict();
    integrationEvent($this, $connection, 'ev-old', ['revision' => 1] + $data)->assertOk()->assertJsonPath('status', 'ignored');
    integrationEvent($this, $connection, 'ev-invalid', ['revision' => 0] + $data)->assertUnprocessable();
    expect($connection->events()->count())->toBe(3)->and($connection->offers()->sole()->revision)->toBe(2);
    $this->post(route('integrations.connections.links', [$this->branch, $connection]), [
        'external_sku' => 'skuza-001', 'local_sku' => $product->sku, 'external_model' => '13', 'local_model' => 'iPhone 13',
    ])->assertSessionHasNoErrors();
    $this->get(route('integrations.connections.show', [$this->branch, $connection]))->assertOk()
        ->assertSee('SKUZA-001')->assertSee('External part')->assertSee('PART-001')->assertDontSee($this->secret);
    expect($connection->getRawOriginal('secret'))->not->toContain($this->secret);
    $line = integrationConnection($this, 'line', 'line-1', ['connector_token' => 'private-token-sample']);
    integrationConnection($this, 'payment', 'collection-01');
    expect($line->credentials['token'])->toBe('private-token-sample')->and($line->getRawOriginal('credentials'))->not->toContain('private-token-sample');
    $this->get(route('integrations.connections.show', [$this->branch, $line]))->assertOk()->assertDontSee('private-token-sample');
    $this->patch(route('integrations.connections.update', [$this->branch, $connection]), ['name' => 'Disabled', 'enabled' => 0])->assertSessionHasNoErrors();
    integrationEvent($this, $connection, 'ev-2', $data)->assertUnauthorized();
    $this->actingAs($this->otherManager)->get(route('integrations.connections.show', [$this->other, $connection]))->assertNotFound();
});

test('online order confirmation is atomic idempotent and shipping cannot replace a newer status or use another account', function () {
    $this->travelTo(now()->setDate(2026, 11, 25)->setTime(4, 0));
    $connection = integrationConnection($this, 'store', 'shop-1');
    $courier = integrationConnection($this, 'shipping', 'carrier-1');
    $product = integrationProduct($this, 'PART-001');
    $anotherProduct = integrationProduct($this, 'PART-002');
    $shelf = $this->warehouse->locations()->create(['code' => 'PACK-01', 'name' => 'Online orders']);
    foreach ([[$product, 'EXT-1'], [$anotherProduct, 'EXT-2']] as [$item, $key]) {
        $this->post(route('catalog.stock.store', $this->branch), [
            'code' => $item->sku, 'warehouse_id' => $this->warehouse->id, 'quantity' => 3, 'submission_id' => (string) Str::uuid(),
            'location_id' => $shelf->id,
        ])->assertSessionHasNoErrors();
        $this->post(route('integrations.connections.links', [$this->branch, $connection]), [
            'external_sku' => $key, 'local_sku' => $item->sku,
        ])->assertSessionHasNoErrors();
    }
    $items = [['external_sku' => 'EXT-1', 'quantity' => 2], ['external_sku' => 'EXT-2', 'quantity' => 4]];
    $data = ['reference' => 'ORDER-1', 'revision' => 1, 'items' => $items];
    integrationEvent($this, $connection, 'order-event-1', $data)->assertOk()->assertJsonPath('status', 'applied');
    $order = $connection->orders()->sole();
    $details = route('integrations.orders.show', [$this->branch, $connection, $order]);
    $confirm = route('integrations.orders.confirm', [$this->branch, $connection, $order]);
    $review = $this->get($details)->assertOk()->assertSee('PART-001')->viewData('mappingVersion');
    $fields = ['warehouse_id' => $this->warehouse->id, 'revision' => 1, 'mapping_version' => $review];
    $this->post($confirm, $fields)->assertSessionHasErrors('quantity');
    expect((int) $product->balances()->sole()->quantity)->toBe(3)->and($order->refresh()->status)->toBe('pending');
    $items[1]['quantity'] = 1;
    integrationEvent($this, $connection, 'order-event-2', ['revision' => 2, 'items' => $items] + $data)->assertOk();
    $this->post($confirm, $fields)->assertSessionHasErrors('revision');
    $product->update(['version' => 2]);
    $this->post($confirm, ['revision' => 2] + $fields)->assertSessionHasErrors('revision');
    $fresh = ['revision' => 2, 'mapping_version' => $this->get($details)->assertOk()->viewData('mappingVersion')] + $fields;
    $this->post($confirm, $fresh)->assertSessionHasNoErrors();
    $this->post($confirm, $fresh)->assertSessionHasNoErrors();
    expect((int) $product->balances()->sole()->quantity)->toBe(1)->and((int) $anotherProduct->balances()->sole()->quantity)->toBe(2);
    expect((int) $product->balances()->sole()->locations()->sole()->quantity)->toBe(1);
    $this->assertDatabaseCount('catalog_movements', 4);
    $this->get(route('catalog.movements.print', [$this->branch, $product->movements()->where('kind', 'sale')->sole()]))->assertOk()->assertSee('ORDER-1');
    $shipping = ['tracking_number' => 'TRACK-1', 'status' => 'delivered', 'occurred_at' => '2026-11-25T03:59:00Z',
        'store_account' => 'shop-1', 'order_ref' => 'ORDER-1'];
    integrationEvent($this, $courier, 'ship-invalid', ['store_account' => 'shop-unknown'] + $shipping)->assertUnprocessable();
    integrationEvent($this, $courier, 'ship-delivered', $shipping)->assertOk();
    integrationEvent($this, $courier, 'ship-older', ['status' => 'in_transit', 'occurred_at' => '2026-11-25T03:58:00Z'] + $shipping)
        ->assertOk()->assertJsonPath('status', 'ignored');
    expect($courier->shipments()->sole()->status)->toBe('delivered')->and($order->refresh()->status)->toBe('confirmed');
    $this->get($details)->assertOk()->assertSee('TRACK-1')->assertSee('ส่งสำเร็จ');
    $this->actingAs($this->reception)->post($confirm, $fresh)->assertForbidden();
});

test('accounting batch preserves source references and balances and cannot export the same movement twice', function () {
    $connection = integrationConnection($this, 'accounting', 'accounts-1', [
        'inventory_account' => '1150', 'receiving_account' => '2110', 'repair_account' => '5110', 'sales_cost_account' => '5210',
    ]);
    integrationProduct($this, 'PART-001');
    $this->post(route('catalog.stock.store', $this->branch), [
        'code' => 'PART-001', 'warehouse_id' => $this->warehouse->id, 'quantity' => 3, 'submission_id' => (string) Str::uuid(),
    ])->assertSessionHasNoErrors();
    $movement = $this->branch->catalogMovements()->sole();
    $this->get(route('integrations.accounting.index', [$this->branch, $connection]))->assertOk()->assertSee($movement->reference());
    $uri = route('integrations.accounting.store', [$this->branch, $connection]);
    $batch = ['movements' => [$movement->id], 'submission_id' => (string) Str::uuid()];
    $this->post($uri, $batch)->assertSessionHasNoErrors();
    $this->post($uri, $batch)->assertSessionHasNoErrors();
    $this->post($uri, ['submission_id' => (string) Str::uuid()] + $batch)->assertSessionHasErrors('movements');
    $export = $connection->accountingExports()->sole();
    $download = route('integrations.accounting.download', [$this->branch, $connection, $export]);
    $csv = $this->get($download)->assertOk()->streamedContent();
    expect($csv)->toContain('source_document,date,sku,account,debit,credit', 'PART-001,1150,75.00,0.00', 'PART-001,2110,0.00,75.00', $movement->reference());
    $this->actingAs($this->reception)->get($download)->assertForbidden();
});
