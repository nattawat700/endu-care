<?php

use App\Enums\BranchRole;
use App\Enums\RepairStatus;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\RepairPayment;
use App\Models\User;
use App\Support\RepairBilling;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    Storage::fake('local');
    $org = Organization::create(['code' => 'docs', 'name' => 'Issued docs']);
    $this->branch = $org->branches()->create(['code' => 'a', 'name' => 'Main branch']);
    $this->other = $org->branches()->create(['code' => 'b', 'name' => 'Other branch']);
    foreach ([
        'owner' => BranchRole::Owner, 'manager' => BranchRole::Manager, 'cashier' => BranchRole::Cashier,
        'technician' => BranchRole::Technician, 'otherTechnician' => BranchRole::Technician,
        'front' => BranchRole::Reception, 'pc' => BranchRole::Pc,
    ] as $name => $role) {
        $this->{$name} = User::factory()->create();
        $this->branch->memberships()->create(['user_id' => $this->{$name}->id, 'role' => $role]);
    }
    $this->other->memberships()->create(['user_id' => $this->owner->id, 'role' => BranchRole::Owner]);
    $this->job = $this->branch->repairs()->make([
        'submission_id' => (string) Str::uuid(), 'created_by' => $this->front->id, 'customer_name' => 'PRIVATE CUSTOMER 22',
        'customer_phone' => '0819992222', 'device' => 'Phone', 'issue' => 'Charging', 'fixed_price_satang' => 60000,
    ]);
    $this->job->technician()->associate($this->technician);
    $this->job->save();
    $this->args = [$this->branch, $this->job];
    $point = $this->branch->paymentPoints()->create(['code' => 'p', 'name' => 'Main point']);
    $printer = $this->branch->printerProfiles()->create(['name' => 'A4', 'device_name' => 'Test Printer', 'paper' => 'a4']);
    $this->counter = $this->branch->counters()->create([
        'code' => 'main', 'name' => 'Main counter', 'payment_point_id' => $point->id, 'printer_profile_id' => $printer->id,
    ]);
});

function openIssuedTestShift($test): void
{
    $test->actingAs($test->cashier)->post(route('cash-shifts.open', $test->branch), [
        'counter_id' => $test->counter->id, 'opening_cash' => '1000', 'submission_id' => (string) Str::uuid(),
    ])->assertSessionHasNoErrors();
}

function issueTestPayment($test, array $fields): RepairPayment
{
    $test->actingAs($test->cashier)->post(route('repairs.payments.store', $test->args), $fields + [
        'document_type' => 'deposit', 'confirmed' => 1, 'submission_id' => (string) Str::uuid(),
        'billing_version' => RepairBilling::summary($test->job->fresh())['version'],
    ])->assertSessionHasNoErrors();

    return $test->job->payments()->latest('id')->firstOrFail();
}

function originalTestQuote($test): array
{
    $test->job->forceFill(['status' => RepairStatus::AwaitingApproval])->save();
    $payload = [
        'submission_id' => (string) Str::uuid(), 'base_revision' => 0, 'terms' => 'ต้นฉบับประเมินซ่อม',
        'items' => [['type' => 'labor', 'description' => 'ตรวจระบบไฟ', 'quantity' => 1, 'unit_price' => '700']],
    ];
    $test->actingAs($test->front)->post(route('repairs.quotations.store', $test->args), $payload)->assertSessionHasNoErrors();
    $quote = $test->job->quotations()->sole();
    $args = [...$test->args, $quote];
    $test->post(route('repairs.quotations.submit-review', $args), ['request_reason' => 'ตรวจราคา'])->assertSessionHasNoErrors();
    $test->actingAs($test->manager)->patch(route('repairs.quotations.decide-review', $args), [
        'decision' => 'approved', 'decision_reason' => 'ผ่านการตรวจ',
        'signature' => UploadedFile::fake()->createWithContent('signature.png', file_get_contents(public_path('images/endu-care-workspace.png'))),
    ])->assertSessionHasNoErrors();
    $test->actingAs($test->front)->patch(route('repairs.quotations.approve', $args), [
        'approval_channel' => 'counter', 'confirmation' => 1,
    ])->assertSessionHasNoErrors();

    return [$quote->refresh(), $payload];
}

function issuedTestImage(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('proof.png', file_get_contents(public_path('images/endu-fix-receipt-logo.png')));
}

test('assigned staff see deposit totals and limited proof while pending transfers and other jobs are excluded', function () {
    openIssuedTestShift($this);
    $deposit = issueTestPayment($this, ['lines' => ['cash' => '150.25']]);
    $receipt = issueTestPayment($this, ['document_type' => 'receipt', 'lines' => ['cash' => '49.75']]);
    $pending = issueTestPayment($this, ['lines' => ['transfer' => '70'], 'transfer_reference' => 'PRIVATE-BANK-REF', 'slip' => issuedTestImage()]);
    $url = route('repairs.show', $this->args);
    $proof = route('repairs.payments.deposit-proof', [...$this->args, $deposit]);
    $this->actingAs($this->technician)->get($url)->assertOk()->assertSee('มัดจำและยอดคงเหลือ')
        ->assertSee('150.25 บาท')->assertSee('400.00 บาท')->assertSee('มัดจำรอตรวจยอด 70.00 บาท')
        ->assertSee($deposit->reference())->assertDontSee('PRIVATE-BANK-REF');
    $this->get($proof)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertSee($deposit->reference())
        ->assertSee('150.25 บาท')->assertDontSee('PRIVATE CUSTOMER 22')->assertDontSee('0819992222')->assertDontSee('เปิดใบรับเงินต้นทาง');
    foreach ([$receipt, $pending] as $record) {
        $this->get(route('repairs.payments.deposit-proof', [...$this->args, $record]))->assertNotFound();
    }
    $this->get(route('repairs.payments.show', [...$this->args, $deposit]))->assertForbidden();
    $this->actingAs($this->otherTechnician)->get($proof)->assertNotFound();
    $this->actingAs($this->pc)->get($url)->assertOk()->assertDontSee('มัดจำและยอดคงเหลือ');
    $this->get($proof)->assertNotFound();
    $this->actingAs($this->front)->get($proof)->assertOk()->assertSee('เปิดใบรับเงินต้นทาง');
    $this->actingAs($this->owner)->get(route('repairs.payments.deposit-proof', [$this->other, $this->job, $deposit]))->assertNotFound();
});

test('completed refunds reduce net deposit and keep the original receipt and refund trace', function () {
    openIssuedTestShift($this);
    $deposit = issueTestPayment($this, ['lines' => ['cash' => '200']]);
    $args = [...$this->args, $deposit];
    $this->post(route('repairs.payments.refunds.request', $args), [
        'line_id' => $deposit->lines()->sole()->id, 'refund_amount' => '50', 'refund_reason' => 'แก้ยอดรับมัดจำ',
        'submission_id' => (string) Str::uuid(),
    ])->assertSessionHasNoErrors();
    $refund = $deposit->refunds()->sole();
    $this->actingAs($this->owner)->patch(route('repairs.payments.refunds.review', [...$args, $refund]), [
        'decision' => 'approved', 'refund_review_note' => 'ตรวจยอดเดิมแล้ว',
    ])->assertSessionHasNoErrors();
    $this->actingAs($this->cashier)->post(route('repairs.payments.refunds.complete', [...$args, $refund]), [
        'paid' => 1, 'refund_proof' => issuedTestImage(),
    ])->assertSessionHasNoErrors();
    $this->get(route('repairs.show', $this->args))->assertOk()->assertSee('200.00 บาท')->assertSee('150.00 บาท')
        ->assertSee('450.00 บาท')->assertSee('คืนเงินมัดจำแล้ว 50.00 บาท');
    $this->get(route('repairs.payments.show', $args))->assertOk()->assertSee('REFUND-'.$refund->id)->assertSee($deposit->reference());
    expect($deposit->refresh()->amount_satang)->toBe(20000);
    $this->assertDatabaseHas('audit_events', ['subject_type' => 'repair_refund', 'subject_reference' => 'REFUND-'.$refund->id, 'operation' => 'updated']);
    expect(fn () => $refund->refresh()->update(['amount_satang' => 1]))->toThrow(ValidationException::class);
    expect(fn () => $deposit->update(['amount_satang' => 1]))->toThrow(ValidationException::class);
    expect(fn () => $deposit->delete())->toThrow(ValidationException::class);
    expect(fn () => $deposit->lines()->sole()->update(['amount_satang' => 1]))->toThrow(ValidationException::class);
});

test('owner cancels an issued quote with a reason, retains its source and opens a fresh revision', function () {
    [$quote, $payload] = originalTestQuote($this);
    $args = [...$this->args, $quote];
    $void = route('repairs.quotations.void', $args);
    $snapshot = $quote->only(['items', 'total_satang', 'approved_at']);
    $this->actingAs($this->manager)->post($void, ['void_reason' => 'ตรวจพบว่าระบุเงื่อนไขผิด'])->assertForbidden();
    $this->actingAs($this->owner)->post($void)->assertSessionHasErrors('void_reason');
    $this->post($void, ['void_reason' => 'ตรวจพบว่าระบุเงื่อนไขผิด'])->assertRedirectToRoute('repairs.quotations.show', $args);
    $this->post($void, ['void_reason' => 'เขียนทับ'])->assertRedirectToRoute('repairs.quotations.show', $args);
    $this->get(route('repairs.quotations.show', $args))->assertOk()->assertSee('ตรวจพบว่าระบุเงื่อนไขผิด')->assertSee('ดูประวัติเอกสารนี้');
    $this->get(route('repairs.quotations.print', $args))->assertOk()->assertSee('ยกเลิกแล้ว / VOID')->assertSee('700.00')->assertDontSee('quotation-unreleased', false);
    $this->get(route('repairs.quotations.signature', $args))->assertOk();
    expect($quote->refresh()->only(['items', 'total_satang']))->toEqual(array_intersect_key($snapshot, array_flip(['items', 'total_satang'])));
    expect($quote->approved_at)->toEqual($snapshot['approved_at']);
    expect($quote->canRelease())->toBeFalse()->and($this->job->refresh()->status)->toBe(RepairStatus::AwaitingApproval);
    expect(AuditEvent::where('subject_reference', $quote->reference())->whereNotNull('after_values->voided_at')->sole()->actor_id)->toBe($this->owner->id);
    expect(fn () => $quote->forceFill(['void_reason' => 'เขียนทับ'])->save())->toThrow(ValidationException::class);
    expect(fn () => $quote->delete())->toThrow(ValidationException::class);
    $this->actingAs($this->front)->patch(route('repairs.quotations.approve', $args), ['approval_channel' => 'counter', 'confirmation' => 1])
        ->assertSessionHasErrors('confirmation');
    $this->post(route('repairs.quotations.store', $this->args), ['base_revision' => 1, 'submission_id' => (string) Str::uuid(), 'revision_reason' => 'จัดทำฉบับใหม่'] + $payload)
        ->assertSessionHasNoErrors();
    expect($this->job->quotations()->count())->toBe(2);
});

test('an issued quote linked to money already received remains in force until correction is recorded', function () {
    [$quote] = originalTestQuote($this);
    expect(fn () => $quote->update(['total_satang' => 1]))->toThrow(ValidationException::class);
    openIssuedTestShift($this);
    $deposit = issueTestPayment($this, ['lines' => ['cash' => '100']]);
    expect($deposit->repair_quotation_id)->toBe($quote->id);
    $this->actingAs($this->owner)->post(route('repairs.quotations.void', [...$this->args, $quote]), ['void_reason' => 'แก้ราคา'])
        ->assertSessionHasErrors('void_reason');
    expect($quote->refresh()->voided_at)->toBeNull();
});
