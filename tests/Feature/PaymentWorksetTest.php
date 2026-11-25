<?php

use App\Enums\BranchRole;
use App\Enums\RepairKind;
use App\Enums\RepairStatus;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    Storage::fake('local');
    $this->org = Organization::create(['code' => 'pilot', 'name' => 'Pilot',
        'approval_rules' => ['refund' => ['limit' => 1000, 'review_role' => 'owner']]]);
    $this->branch = $this->org->branches()->create(['code' => 'shop', 'name' => 'Pilot Shop']);
    $this->other = $this->org->branches()->create(['code' => 'other', 'name' => 'Other Shop']);
    foreach (['cashier' => BranchRole::Cashier, 'accounting' => BranchRole::Accounting, 'manager' => BranchRole::Manager, 'owner' => BranchRole::Owner] as $name => $role) {
        $this->{$name} = User::factory()->create();
        $this->branch->memberships()->create(['user_id' => $this->{$name}->id, 'role' => $role]);
    }
    $this->other->memberships()->create(['user_id' => $this->accounting->id, 'role' => BranchRole::Accounting]);
    $this->point = $this->branch->paymentPoints()->create(['code' => 'front', 'name' => 'Front']);
    $printer = $this->branch->printerProfiles()->create(['name' => 'Test', 'device_name' => 'Test', 'paper' => 'a4']);
    $this->counter = $this->branch->counters()->create(['code' => 'front', 'name' => 'Counter', 'payment_point_id' => $this->point->id, 'printer_profile_id' => $printer->id]);
    $this->repair = $this->branch->repairs()->create([
        'submission_id' => (string) Str::uuid(), 'created_by' => $this->cashier->id, 'customer_name' => 'Test',
        'customer_phone' => '0812345678', 'device' => 'Phone', 'issue' => 'Screen', 'work_kind' => RepairKind::Quick, 'fixed_price_satang' => 60000,
    ]);
    $this->routeArgs = [$this->branch, $this->repair];
    $this->url = route('repairs.payments.index', $this->routeArgs);
    $this->receive = route('repairs.payments.store', $this->routeArgs);
    $this->open = route('cash-shifts.open', $this->branch);
});

function payFixture(array $overrides = []): UploadedFile
{
    return UploadedFile::fake()->createWithContent('fixture.png', file_get_contents(public_path('images/endu-fix-receipt-logo.png')).($overrides['marker'] ?? ''));
}

test('shift ties drawer activity to its cashier and closes on reconciled actuals with an explanation for differences', function () {
    $this->actingAs($this->cashier);
    $billing = $this->get($this->url)->assertOk()->assertSee('เปิดกะก่อนรับเงิน')->viewData('billing');
    $post = ['submission_id' => (string) Str::uuid(), 'billing_version' => $billing['version'],
        'document_type' => 'deposit', 'lines' => ['cash' => '100'], 'tendered' => '200', 'confirmed' => 1];
    $this->post($this->receive, $post)->assertSessionHasErrors('shift');
    $open = ['counter_id' => $this->counter->id, 'opening_cash' => '200.00', 'submission_id' => (string) Str::uuid()];
    $this->post($this->open, $open)->assertSessionHasNoErrors();
    $shift = $this->branch->cashShifts()->sole();
    $this->post($this->open, $open)->assertRedirectToRoute('cash-shifts.show', [$this->branch, $shift]);
    $this->actingAs($this->accounting)->post($this->open, ['submission_id' => (string) Str::uuid()] + $open)->assertSessionHasErrors('counter_id');

    $this->actingAs($this->cashier);
    $this->post($this->receive, ['tendered' => '50'] + $post)->assertSessionHasErrors('tendered');
    $this->post($this->receive, $post)->assertSessionHasNoErrors();
    $row = $this->repair->payments()->sole();
    $this->post($this->receive, $post)->assertRedirectToRoute('repairs.payments.show', [...$this->routeArgs, $row]);
    expect($row->lines()->sole()->changeSatang())->toBe(10000);
    expect($shift->totals()['cash_expected'])->toBe(30000);
    expect($this->get($this->url)->assertOk()->viewData('billing')['state'])->toBe('ชำระบางส่วน');

    $drawer = route('cash-shifts.move', [$this->branch, $shift]);
    foreach (['deposit' => '30.15', 'top_up' => '20.00', 'expense' => '10.25'] as $kind => $amount) {
        $change = ['kind' => $kind, 'amount' => $amount, 'reason' => 'Test', 'submission_id' => (string) Str::uuid()];
        $this->post($drawer, $change)->assertSessionHasNoErrors();
        $this->post($drawer, $change)->assertSessionHasNoErrors();
    }
    expect($shift->totals()['cash_expected'])->toBe(27960);
    $close = route('cash-shifts.close', [$this->branch, $shift]);
    $this->patch($close, ['counted_cash' => '279.50', 'counted_transfer' => '0.00'])->assertSessionHasErrors('close_reason');
    $this->patch($close, ['counted_cash' => '279.50', 'counted_transfer' => '0.00', 'close_reason' => 'Counted 10 satang short'])->assertSessionHasNoErrors();
    expect($shift->refresh()->closing['cash_difference'])->toBe(-10);
    expect($shift->active_counter)->toBeNull();
    $this->get(route('cash-shifts.show', [$this->branch, $shift]))->assertOk()->assertSee('279.50 บาท');
    $this->actingAs($this->accounting)->get(route('cash-shifts.show', [$this->other, $shift]))->assertNotFound();
});

test('cash, transfer, QR and other can settle one bill only after verification and the slip stays scoped', function () {
    $this->actingAs($this->owner)->patch(route('branch-settings.payment-points.update', [$this->branch, $this->point]), [
        'name' => 'Front', 'code' => 'front', 'other_channel_name' => 'Card terminal', 'point_qr' => payFixture(),
    ])->assertSessionHasNoErrors();
    $this->actingAs($this->cashier)->get(route('cash-shifts.qr', [$this->branch, $this->point]))->assertOk();
    $this->post($this->open, ['counter_id' => $this->counter->id, 'opening_cash' => '200', 'submission_id' => (string) Str::uuid()])
        ->assertSessionHasNoErrors();
    $billing = $this->get($this->url)->assertOk()->assertSee('แสดง QR ของจุดรับเงิน')->assertSee('Card terminal')->viewData('billing');
    $fields = ['submission_id' => (string) Str::uuid(), 'billing_version' => $billing['version'],
        'document_type' => 'receipt', 'lines' => ['cash' => '100', 'transfer' => '250', 'qr' => '200', 'other' => '50'],
        'transfer_reference' => 'bank-1', 'qr_reference' => 'qr-1', 'confirmed' => 1];
    $proofs = fn () => ['slip' => payFixture(['marker' => 'a']), 'qr_slip' => payFixture(['marker' => 'b'])];
    $this->post($this->receive, $fields + $proofs())->assertSessionHasNoErrors();
    $payment = $this->repair->payments()->sole();
    $params = [...$this->routeArgs, $payment];
    $this->post($this->receive, $fields + $proofs())->assertRedirectToRoute('repairs.payments.show', $params);
    $this->get(route('repairs.payments.print', $params))->assertConflict();
    $this->get(route('repairs.payments.show', $params))->assertSee('รอตรวจยอด')->assertDontSee('พิมพ์ใบเสร็จรับเงิน');
    $shift = $this->branch->cashShifts()->sole();
    expect($shift->totals()['cash_expected'])->toBe(30000);
    expect($shift->totals()['transfer_expected'])->toBe(0);
    $this->patch(route('cash-shifts.close', [$this->branch, $shift]), ['counted_cash' => '300', 'counted_transfer' => '0'])->assertSessionHasErrors('close_reason');
    $review = route('repairs.payments.verify', $params);
    $this->patch($review, ['decision' => 'confirmed'])->assertForbidden();
    $this->actingAs($this->accounting)->patch($review, ['decision' => 'confirmed'])->assertSessionHasNoErrors();
    $this->patch($review, ['decision' => 'confirmed'])->assertSessionHasNoErrors();
    expect((int) $payment->refresh()->verified_by)->toBe($this->accounting->id);
    expect($shift->totals()['transfer_expected'])->toBe(45000);
    expect($shift->totals()['other_received'])->toBe(5000);
    expect($this->get($this->url)->assertOk()->viewData('billing')['state'])->toBe('ชำระครบ');
    $this->get(route('repairs.payments.print', $params))->assertOk()->assertSee('Card terminal')->assertSee('600.00');
    $line = $payment->lines()->where('channel', 'qr')->sole();
    $this->get(route('repairs.payments.slip', [...$params, 'line' => $line->id]))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->get(route('cash-shifts.qr', [$this->other, $this->point]))->assertNotFound();
});

test('refund reserves the original channel, follows the approval role, and applies to payment and drawer only once after proof', function () {
    $this->actingAs($this->cashier)->post($this->open, ['counter_id' => $this->counter->id, 'opening_cash' => '100', 'submission_id' => (string) Str::uuid()])
        ->assertSessionHasNoErrors();
    $billing = $this->get($this->url)->assertOk()->viewData('billing');
    $this->post($this->receive, ['submission_id' => (string) Str::uuid(), 'billing_version' => $billing['version'],
        'document_type' => 'receipt', 'lines' => ['cash' => '600'], 'confirmed' => 1])->assertSessionHasNoErrors();
    $payment = $this->repair->payments()->sole();
    $params = [...$this->routeArgs, $payment];
    $refund = route('repairs.payments.refunds.request', $params);
    $fields = ['line_id' => $payment->lines()->sole()->id, 'refund_amount' => '600', 'refund_reason' => 'Customer refund', 'submission_id' => (string) Str::uuid()];
    $this->post($refund, $fields)->assertSessionHasNoErrors();
    $this->post($refund, ['submission_id' => (string) Str::uuid()] + $fields)->assertSessionHasErrors('refund_amount');
    $row = $payment->refunds()->sole();
    $nested = [...$params, $row];
    $review = route('repairs.payments.refunds.review', $nested);
    $approve = ['decision' => 'approved', 'refund_review_note' => 'Verified'];
    $this->actingAs($this->manager)->patch($review, $approve)->assertForbidden();
    $this->actingAs($this->owner)->patch($review, $approve)->assertSessionHasNoErrors();
    expect($this->get($this->url)->assertOk()->viewData('billing')['paid'])->toBe(60000);
    $complete = route('repairs.payments.refunds.complete', $nested);
    $this->actingAs($this->cashier)->post($complete, ['paid' => 1])->assertSessionHasErrors('refund_proof');
    $this->post($complete, ['paid' => 1, 'refund_proof' => payFixture()])->assertSessionHasNoErrors();
    $this->post($complete, ['paid' => 1, 'refund_proof' => payFixture()])->assertSessionHasNoErrors();
    expect($this->branch->cashShifts()->sole()->totals()['cash_expected'])->toBe(10000);
    $this->get($this->url)->assertOk()->assertSee('คืนเงินครบ');
    $this->repair->forceFill(['status' => RepairStatus::ReturnedUnrepaired])->save();
    $this->get($this->url)->assertOk()->assertSee('คืนเงินครบ')->assertDontSee('บันทึกรับเงิน</button>', false);
    $this->get(route('repairs.payments.refunds.proof', $nested))->assertOk();
    expect($row->refresh()->status)->toBe('completed');
    expect($payment->amount_satang)->toBe(60000);
});
