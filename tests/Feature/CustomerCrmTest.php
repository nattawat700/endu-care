<?php

use App\Enums\BranchRole;
use App\Enums\RepairStatus;
use App\Models\AuditEvent;
use App\Models\Customer;
use App\Models\CustomerAppointment;
use App\Models\CustomerDevice;
use App\Models\Organization;
use App\Models\RepairJob;
use App\Models\RepairQuotation;
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
    $this->otherBranch = $organization->branches()->create(['code' => 'care', 'name' => 'ENDU CARE']);
    $this->reception = User::factory()->create(['name' => 'พนักงานรับนัด']);
    $this->technician = User::factory()->create();
    $this->otherStaff = User::factory()->create();
    $this->branch->memberships()->create(['user_id' => $this->reception->id, 'role' => BranchRole::Reception]);
    $this->branch->memberships()->create(['user_id' => $this->technician->id, 'role' => BranchRole::Technician]);
    $this->otherBranch->memberships()->create(['user_id' => $this->otherStaff->id, 'role' => BranchRole::Reception]);

    $this->fields = [
        'submission_id' => (string) Str::uuid(), 'name' => 'คุณสมหมาย ทดสอบ', 'phone' => '081-234-5678',
        'contact_name' => 'ผู้ติดต่อเดิม', 'contact_phone' => '089-012-3456',
        'email' => 'customer@example.test', 'delivery_address' => 'ที่อยู่รับส่งเดิม',
        'billing_address' => 'ที่อยู่ออกเอกสารเดิม', 'service_channel' => 'line', 'marketing_preference' => 'opted_out',
    ];
    $this->intake = [
        'submission_id' => (string) Str::uuid(), 'customer_name' => $this->fields['name'], 'customer_phone' => $this->fields['phone'],
        'customer_address' => $this->fields['billing_address'], 'product' => 'โทรศัพท์', 'device' => 'iPhone 13',
        'issue' => 'จอแตก', 'device_condition' => 'กระจกมีรอย', 'warranty_status' => 'covered', 'policy_number' => 'POL-01',
    ];
});

test('reception can search a registered customer and receives a duplicate warning for the same normalized phone or name', function () {
    $store = route('crm.customers.store', $this->branch);
    $this->get(route('crm.customers.index', $this->branch))->assertRedirectToRoute('login');
    $this->actingAs($this->reception);
    $form = route('crm.customers.create', $this->branch);
    $this->get($form)->assertOk()->assertSee('ชื่อผู้ติดต่อ');
    $this->post($store, $this->fields)->assertSessionHasNoErrors();
    $customer = Customer::sole();
    $this->post($store, $this->fields)->assertRedirectToRoute('crm.customers.show', [$this->branch, $customer]);
    $index = route('crm.customers.index', $this->branch);
    foreach (['สมหมาย', '081 234', '+66 81 234 5678'] as $query) {
        $this->get($index.'?'.http_build_query(['q' => $query]))->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertSee($this->fields['name']);
    }
    $this->get($index.'?q=%25')->assertOk()->assertDontSee($this->fields['name']);

    $samePhone = ['submission_id' => (string) Str::uuid(), 'name' => 'ผู้ใช้เบอร์ร่วม', 'phone' => '+66 81 234 5678'] + $this->fields;
    $this->from($form)->followingRedirects()->post($store, $samePhone)->assertOk()->assertSee('ตรวจแล้วและยืนยันบันทึก');
    $this->post($store, ['submission_id' => (string) Str::uuid(), 'name' => 'คุณสมหมาย   ทดสอบ', 'phone' => '0891231239'] + $this->fields)
        ->assertSessionHasErrors('duplicate');
    $this->assertDatabaseCount('customers', 1);
    $this->post($store, ['confirm_duplicate' => '1'] + $samePhone)->assertSessionHasNoErrors();
    $this->assertDatabaseCount('customers', 2);
});

test('contact and communication settings remain independent and editing the address keeps issued document snapshots', function () {
    $this->actingAs($this->reception)->post(route('crm.customers.store', $this->branch), $this->fields)->assertSessionHasNoErrors();
    $customer = Customer::sole();
    $device = $customer->customerDevices()->create(['model' => 'iPhone 13', 'imei' => '358111111111111']);
    $selection = ['customer_id' => $customer->id, 'customer_device_id' => $device->id];
    $this->get(route('repairs.create', ['branch' => $this->branch] + $selection))->assertOk()
        ->assertSee('ที่อยู่ออกเอกสารเดิม')->assertSee('358111111111111');
    $this->post(route('repairs.store', $this->branch), ['imei' => '358222222222222'] + $selection + $this->intake)
        ->assertSessionHasErrors('imei');
    $this->post(route('repairs.store', $this->branch), $selection + $this->intake)->assertSessionHasNoErrors();
    $repair = RepairJob::sole();
    expect($repair->customer_device_id)->toBe($device->id)->and($repair->imei)->toBe('358111111111111');
    $repair->status = RepairStatus::Diagnosing;
    $repair->save();
    $this->post(route('repairs.quotations.store', [$this->branch, $repair]), [
        'submission_id' => (string) Str::uuid(), 'base_revision' => 0, 'valid_until' => '2026-12-03', 'terms' => 'เงื่อนไขทดสอบ',
        'items' => [['type' => 'labor', 'description' => 'ค่าแรงทดสอบ', 'quantity' => 1, 'unit_price' => '500.00']],
    ])->assertSessionHasNoErrors();
    $quote = RepairQuotation::sole();

    $this->patch(route('crm.customers.update', [$this->branch, $customer]), [
        'contact_name' => 'ผู้ติดต่อใหม่', 'delivery_address' => 'ที่อยู่รับส่งใหม่', 'billing_address' => 'ที่อยู่ออกเอกสารใหม่',
        'service_channel' => 'email',
    ] + $this->fields)->assertSessionHasNoErrors();
    $this->get(route('crm.customers.show', [$this->branch, $customer]))->assertOk()
        ->assertSee('ผู้ติดต่อใหม่')->assertSee('ที่อยู่รับส่งใหม่')->assertSee('ที่อยู่ออกเอกสารใหม่')->assertSee('ไม่ต้องการรับข่าว');
    expect($customer->refresh()->service_channel)->toBe('email')->and($customer->marketing_preference)->toBe('opted_out');
    $this->get(route('repairs.print', [$this->branch, $repair]))->assertOk()
        ->assertSee('ที่อยู่ออกเอกสารเดิม')->assertDontSee('ที่อยู่ออกเอกสารใหม่');
    $this->get(route('repairs.quotations.show', [$this->branch, $repair, $quote]))->assertOk()
        ->assertSee('ที่อยู่ออกเอกสารเดิม')->assertDontSee('ที่อยู่ออกเอกสารใหม่');
    $event = AuditEvent::where('subject_type', 'customer')->where('operation', 'updated')->sole();
    expect($event->after_values)->toBe(['service_channel' => 'email'])
        ->and($event->toJson())->not->toContain('ที่อยู่รับส่งใหม่', 'ผู้ติดต่อใหม่');
});

test('a device shows its service and warranty history and a past repair can only be linked after matching customer and device', function () {
    $this->actingAs($this->reception)->post(route('crm.customers.store', $this->branch), $this->fields)->assertSessionHasNoErrors();
    $customer = Customer::sole();
    $params = [$this->branch, $customer];
    $store = route('crm.devices.store', $params);
    $this->post($store, ['model' => 'iPhone 13'])->assertSessionHasErrors('serial_number');
    $this->post($store, ['model' => 'iPhone 13', 'serial_number' => 'abc123', 'imei' => '358111111111111', 'warranty_until' => '2027-02-25'])
        ->assertSessionHasNoErrors();
    $device = CustomerDevice::sole();
    $this->post($store, ['model' => 'อีกรุ่น', 'serial_number' => 'ABC123'])->assertSessionHasErrors('serial_number');
    $this->post(route('repairs.store', $this->branch), ['serial_number' => 'abc123', 'imei' => '358111111111111'] + $this->intake)->assertSessionHasNoErrors();
    $past = RepairJob::sole();
    $link = route('crm.devices.link-repair', [...$params, $device]);
    $this->post($link, ['reference' => 'REP-999999'])->assertSessionHasErrors('reference');
    $past->update(['imei' => '358222222222222']);
    $this->post($link, ['reference' => $past->reference()])->assertSessionHasErrors('reference');
    expect($past->refresh()->customer_id)->toBeNull();
    $past->update(['imei' => '358111111111111']);
    $this->post($link, ['reference' => $past->reference()])->assertSessionHasNoErrors();
    $this->get(route('crm.devices.show', [...$params, $device]))->assertOk()
        ->assertSee('2027-02-25')->assertSee($past->reference())->assertSee('POL-01')->assertSee('ในประกัน');
    expect($past->refresh()->customer_device_id)->toBe($device->id);
});

test('appointments can be booked rescheduled and cancelled with Thai time and active branch staff only', function () {
    $this->actingAs($this->reception)->post(route('crm.customers.store', $this->branch), $this->fields)->assertSessionHasNoErrors();
    $customer = Customer::sole();
    $store = route('crm.appointments.store', [$this->branch, $customer]);
    $form = route('crm.appointments.create', [$this->branch, $customer]);
    $this->get($form)->assertOk()->assertSee($this->reception->name)->assertDontSee($this->otherStaff->name);
    $fields = ['submission_id' => (string) Str::uuid(), 'kind' => 'repair', 'starts_at' => '2026-11-26T14:30', 'responsible_user_id' => $this->reception->id];
    $this->post($store, ['responsible_user_id' => $this->otherStaff->id] + $fields)->assertSessionHasErrors('responsible_user_id');
    $this->post($store, ['starts_at' => '2026-11-25T08:59'] + $fields)->assertSessionHasErrors('starts_at');
    $this->post($store, $fields)->assertSessionHasNoErrors();
    $this->post($store, $fields)->assertSessionHasNoErrors();
    $appointment = CustomerAppointment::sole();
    expect($appointment->starts_at->utc()->format('Y-m-d H:i'))->toBe('2026-11-26 07:30');
    $this->get(route('crm.appointments.index', $this->branch))->assertOk()->assertSee('26/11/2026 14:30');
    $edit = route('crm.appointments.edit', [$this->branch, $appointment]);
    $this->get($edit)->assertOk()->assertSee('2026-11-26T14:30');
    $change = route('crm.appointments.update', [$this->branch, $appointment]);
    $this->patch($change, ['action' => 'reschedule', 'version' => 0, 'starts_at' => '2026-11-27T10:00'] + $fields)->assertSessionHasNoErrors();
    $this->patch($change, ['action' => 'completed', 'version' => 0])->assertSessionHasErrors('action');
    $this->patch($change, ['action' => 'cancelled', 'version' => 1])->assertSessionHasNoErrors();
    $this->patch($change, ['action' => 'reschedule', 'version' => 2] + $fields)->assertSessionHasErrors('action');
    $this->get(route('crm.appointments.index', ['branch' => $this->branch, 'status' => 'cancelled']))->assertOk()
        ->assertSee('27/11/2026 10:00')->assertSee($this->reception->name);
    expect($appointment->refresh()->status)->toBe('cancelled');
});

test('CRM routes and repair intake enforce role and branch scope for customer device and appointment records', function () {
    $this->actingAs($this->reception)->post(route('crm.customers.store', $this->branch), $this->fields)->assertSessionHasNoErrors();
    $customer = Customer::sole();
    $device = $customer->customerDevices()->create(['model' => 'เครื่องทดสอบ', 'imei' => '111111111111111']);
    $this->post(route('crm.appointments.store', [$this->branch, $customer]), [
        'submission_id' => (string) Str::uuid(), 'kind' => 'receive', 'starts_at' => '2026-11-26T09:00', 'responsible_user_id' => $this->reception->id,
    ])->assertSessionHasNoErrors();
    $appointment = CustomerAppointment::sole();
    $this->actingAs($this->technician)->get(route('crm.customers.show', [$this->branch, $customer]))->assertForbidden();
    $this->actingAs($this->otherStaff)->get(route('crm.customers.show', [$this->branch, $customer]))->assertNotFound();
    $this->get(route('crm.customers.show', [$this->otherBranch, $customer]))->assertNotFound();
    $this->get(route('crm.appointments.edit', [$this->otherBranch, $appointment]))->assertNotFound();
    $this->post(route('repairs.store', $this->otherBranch), ['customer_id' => $customer->id, 'customer_device_id' => $device->id] + $this->intake)
        ->assertNotFound();
    $this->assertDatabaseCount('repair_jobs', 0);

    $this->actingAs($this->reception)->post(route('crm.customers.store', $this->branch), [
        'name' => 'ลูกค้าอีกราย', 'phone' => '0899990000', 'submission_id' => (string) Str::uuid(),
    ] + $this->fields)->assertSessionHasNoErrors();
    $differentCustomer = Customer::where('name', 'ลูกค้าอีกราย')->sole();
    $this->get(route('crm.devices.show', [$this->branch, $differentCustomer, $device]))->assertNotFound();
});
