<?php

use App\Enums\BranchRole;
use App\Enums\RepairStatus;
use App\Models\Organization;
use App\Models\RepairJob;
use App\Models\RepairPayment;
use App\Models\User;
use App\Support\DocumentPdf;
use App\Support\RepairBilling;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    Storage::fake('local');
    $organization = Organization::create(['code' => 'workset6', 'name' => 'Test Org']);
    $this->branch = $organization->branches()->create([
        'code' => 'fix', 'name' => 'Test Branch',
        'document_profile' => ['name' => 'ผู้ขายทดสอบ', 'address' => 'ที่อยู่ผู้ขาย', 'tax_id' => '0123456789000'],
    ]);
    $this->other = $organization->branches()->create(['code' => 'other', 'name' => 'Other']);
    foreach (['owner' => BranchRole::Owner, 'manager' => BranchRole::Manager, 'front' => BranchRole::Reception,
        'cashier' => BranchRole::Cashier, 'tech' => BranchRole::Technician, 'accounting' => BranchRole::Accounting] as $key => $role) {
        $this->{$key} = User::factory()->create();
        $this->branch->memberships()->create(['user_id' => $this->{$key}->id, 'role' => $role]);
    }
    $this->other->memberships()->create(['user_id' => $this->owner->id, 'role' => BranchRole::Owner]);
    $this->customer = $this->branch->customers()->create([
        'name' => 'ลูกค้าทดสอบ', 'name_key' => 'ลูกค้าทดสอบ', 'phone' => '0821112222', 'phone_key' => '0821112222',
        'service_channel' => 'phone', 'marketing_preference' => 'unspecified', 'submission_id' => (string) Str::uuid(),
    ]);
    $this->jobFields = [
        'customer_id' => $this->customer->id, 'created_by' => $this->front->id, 'customer_name' => $this->customer->name,
        'device' => 'iPhone 14 Test', 'issue' => 'Test issue', 'customer_phone' => '0821112222', 'fixed_price_satang' => 10700,
    ];
    $this->job = worksetSixJob($this, RepairStatus::Ready);
    $point = $this->branch->paymentPoints()->create(['code' => 'point', 'name' => 'Test Point']);
    $printer = $this->branch->printerProfiles()->create(['name' => 'A4', 'device_name' => 'A4', 'paper' => 'a4']);
    $this->counter = $this->branch->counters()->create(['code' => 'front', 'name' => 'Front', 'payment_point_id' => $point->id, 'printer_profile_id' => $printer->id]);
});

function worksetSixJob($test, RepairStatus $status, array $fields = []): RepairJob
{
    $job = $test->branch->repairs()->make($fields + $test->jobFields + ['submission_id' => (string) Str::uuid()]);
    $job->technician()->associate($test->tech);
    $job->status = $status;
    $job->save();

    return $job;
}

function worksetSixShift($test): void
{
    $test->actingAs($test->cashier)->post(route('cash-shifts.open', $test->branch), [
        'submission_id' => (string) Str::uuid(), 'counter_id' => $test->counter->id, 'opening_cash' => '800',
    ])->assertSessionHasNoErrors();
}

function worksetSixPay($test, RepairJob $job, string $amount, string $type = 'receipt'): RepairPayment
{
    $test->actingAs($test->cashier)->post(route('repairs.payments.store', [$test->branch, $job]), [
        'submission_id' => (string) Str::uuid(), 'billing_version' => RepairBilling::summary($job->fresh())['version'],
        'document_type' => $type, 'lines' => ['cash' => $amount], 'confirmed' => 1,
    ])->assertSessionHasNoErrors();

    return $job->payments()->latest('id')->firstOrFail();
}

function worksetSixSettings($test, bool $short = false): void
{
    $test->actingAs($test->owner)->patch(route('documents.settings.tax', $test->branch), [
        'enabled' => 1, 'abbreviated' => $short ? 1 : 0, 'rate' => '7', 'office_number' => '00000',
    ])->assertSessionHasNoErrors();
}

test('stores separate buyer identity and safely scopes the customer history and balances', function () {
    $args = [$this->branch, $this->customer];
    $edit = route('crm.customers.tax-profile', $args);
    $view = route('crm.customers.show', $args);
    $fields = ['type' => 'company', 'name' => 'ผู้ซื้อภาษีทดสอบ', 'address' => 'ที่อยู่ออกภาษี', 'tax_id' => '1234567890000', 'office' => 'branch', 'office_number' => '00008'];
    $this->actingAs($this->front)->patch($edit, ['office_number' => '00000'] + $fields)->assertSessionHasErrors('office_number');
    $this->patch($edit, $fields)->assertSessionHasNoErrors();
    $this->customer->refresh();
    expect($this->customer->tax_profile['office_number'])->toBe('00008')->and($this->customer->name)->toBe('ลูกค้าทดสอบ');
    expect(DB::table('customers')->where('id', $this->customer->id)->value('tax_profile'))->not->toContain('1234567890000');
    $this->get($view)->assertOk()->assertSee('ข้อมูลออกใบกำกับภาษี')->assertSee('ผู้ซื้อภาษีทดสอบ')->assertSee('107.00 บาท');
    $this->actingAs($this->cashier)->patch($edit, $fields)->assertForbidden();
    $this->actingAs($this->owner)->get(route('crm.customers.show', [$this->other, $this->customer]))->assertNotFound();
});

test('issues full tax from paid source, keeps issuer and buyer snapshots and reserves a branch sequence', function () {
    worksetSixShift($this);
    $receipt = worksetSixPay($this, $this->job, '107');
    $source = [$this->branch, 'payment', $receipt->id];
    $url = route('documents.issue', $source);
    $this->post($url, ['kind' => 'tax_full'])->assertSessionHasErrors('kind');
    worksetSixSettings($this);
    $this->get(route('documents.settings', $this->branch))->assertOk()->assertSee('ชุดเลขเอกสาร');
    $this->patch(route('documents.settings.series', $this->branch), ['kind' => 'tax_full', 'prefix' => 'VAT', 'next_number' => 21])->assertSessionHasNoErrors();
    $this->actingAs($this->cashier)->post($url, ['kind' => 'tax_full'])->assertSessionHasErrors('kind');
    $this->actingAs($this->front)->patch(route('crm.customers.tax-profile', [$this->branch, $this->customer]), [
        'type' => 'company', 'name' => 'บริษัทเดิม', 'address' => 'ที่อยู่บริษัทเดิม', 'tax_id' => '9876543210000', 'office' => 'head',
    ])->assertSessionHasNoErrors();
    $this->actingAs($this->accounting)->get(route('documents.source', $source))->assertOk();
    $this->post($url, ['kind' => 'tax_full'])->assertSessionHasNoErrors();
    $doc = $this->branch->issuedDocuments()->sole();
    expect($doc->number)->toBe('VAT-FIX-000021')->and($doc->snapshot['vat'])->toBe(700)->and($doc->snapshot['base'])->toBe(10000);
    $this->post($url, ['kind' => 'tax_full'])->assertRedirectToRoute('documents.show', [$this->branch, $doc]);
    $this->job->refresh();
    $this->branch->update(['document_profile' => ['name' => 'ผู้ขายใหม่', 'address' => 'ที่อยู่ใหม่', 'tax_id' => '9999999999999']]);
    $this->actingAs($this->front)->patch(route('crm.customers.tax-profile', [$this->branch, $this->customer]), [
        'type' => 'individual', 'name' => 'บุคคลคนใหม่', 'address' => 'ที่อยู่คนใหม่',
    ])->assertSessionHasNoErrors();
    $this->get(route('documents.show', [$this->branch, $doc]))->assertOk()->assertSee('บริษัทเดิม')->assertSee('ผู้ขายทดสอบ')->assertSee('7.00 บาท')
        ->assertDontSee('บุคคลคนใหม่')->assertDontSee('ผู้ขายใหม่');
    expect(fn () => $doc->update(['number' => 'CHANGED']))->toThrow(ValidationException::class);
    $this->actingAs($this->owner)->patch(route('documents.settings.series', $this->branch), ['kind' => 'tax_full', 'prefix' => 'VAT', 'next_number' => 21])
        ->assertSessionHasErrors('next_number');
    $this->get(route('documents.show', [$this->other, $doc]))->assertNotFound();
});

test('abbreviated tax needs permission and can issue once without buyer data, with print history', function () {
    worksetSixShift($this);
    $receipt = worksetSixPay($this, $this->job, '107');
    $params = [$this->branch, 'payment', $receipt->id];
    worksetSixSettings($this);
    $this->actingAs($this->cashier)->post(route('documents.issue', $params), ['kind' => 'tax_short'])->assertSessionHasErrors('kind');
    $this->patch(route('documents.settings.tax', $this->branch), ['enabled' => 1, 'abbreviated' => 1, 'rate' => '7', 'office_number' => '00000'])->assertForbidden();
    worksetSixSettings($this, short: true);
    $this->actingAs($this->cashier)->post(route('documents.issue', $params), ['kind' => 'tax_short'])->assertSessionHasNoErrors();
    $doc = $this->branch->issuedDocuments()->sole();
    $this->post(route('documents.issue', $params), ['kind' => 'tax_full'])->assertSessionHasErrors('kind');
    $this->get(route('documents.show', [$this->branch, $doc]))->assertOk()->assertSee('ยอดรวมนี้รวมภาษีมูลค่าเพิ่มแล้ว')->assertDontSee('ผู้ซื้อ / ผู้รับบริการ');
    $this->mock(DocumentPdf::class, function ($mock) use ($doc) {
        foreach ([false, true] as $copy) {
            $mock->shouldReceive('render')->once()->withArgs(fn ($view, $data, $number) => $view === 'documents.print' && $number === $doc->number && $data['copy'] === $copy)
                ->andReturn(response('%PDF-1.7', 200, ['Content-Type' => 'application/pdf']));
        }
    });
    $print = route('documents.print', [$this->branch, $doc]);
    $this->post($print)->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->post($print)->assertOk();
    $this->assertDatabaseCount('document_prints', 2);
    $this->assertDatabaseHas('document_prints', ['number' => $doc->number, 'printed_by' => $this->cashier->id]);
    $this->get(route('documents.show', [$this->branch, $doc]))->assertOk()->assertSee('พิมพ์ซ้ำ');
    $this->get(route('documents.index', $this->branch))->assertOk()->assertSee($doc->number);
    $this->actingAs($this->tech)->post($print)->assertForbidden();
});

test('delivers a settled ready repair and issues its delivery, warranty and receipt bundle', function () {
    $params = [$this->branch, $this->job];
    $data = ['next_status' => 'delivered', 'workflow_version' => 0, 'assignment_version' => 0,
        'recipient' => 'ผู้รับแทน', 'warranty_days' => 30, 'warranty_terms' => 'เฉพาะอาการเดิม'];
    $this->actingAs($this->cashier)->patch(route('repairs.change-status', $params), $data)->assertSessionHasErrors('next_status');
    worksetSixShift($this);
    $payment = worksetSixPay($this, $this->job, '107', 'cash_receipt');
    $this->patch(route('repairs.change-status', $params), $data)->assertSessionHasNoErrors();
    expect($this->job->refresh()->status)->toBe(RepairStatus::Delivered);
    expect($this->job->closure->details['recipient'])->toBe('ผู้รับแทน')->and($this->job->closure->details['remaining'])->toBe(0);
    expect($this->job->closure->details['warranty_end'])->toBe(now('Asia/Bangkok')->addDays(29)->toDateString());
    $this->get(route('repairs.show', $params))->assertOk()->assertSee('ประกันงานซ่อม')->assertSee('เฉพาะอาการเดิม');
    foreach (['delivery', 'warranty', 'delivery_warranty'] as $kind) {
        $this->post(route('documents.issue', [$this->branch, 'repair', $this->job->id]), ['kind' => $kind])->assertSessionHasNoErrors();
    }
    $bundle = $this->branch->issuedDocuments()->where('kind', 'delivery_warranty')->sole();
    expect($bundle->snapshot['receipt_id'])->toBe($payment->id);
    $this->get(route('documents.show', [$this->branch, $bundle]))->assertOk()->assertSee($payment->reference())->assertSee('ผู้รับแทน');
    expect(fn () => $this->job->closure->delete())->toThrow(ValidationException::class);
});

test('records claim approval for the original repair and lists it only in its customer and branch', function () {
    $this->job->forceFill(['status' => RepairStatus::Delivered])->save();
    $source = [$this->branch, 'repair', $this->job->id];
    $claimData = ['submission_id' => (string) Str::uuid(), 'claimant' => 'ผู้แจ้งทดสอบ', 'item' => 'เครื่องเดิม', 'issue' => 'อาการเดิมกลับมา'];
    $this->actingAs($this->front)->post(route('documents.claims.store', $source), $claimData)->assertSessionHasNoErrors();
    $claim = $this->branch->serviceClaims()->sole();
    $this->post(route('documents.claims.store', $source), $claimData)->assertRedirectToRoute('documents.source', [$this->branch, 'claim', $claim->id]);
    $claimIssue = route('documents.issue', [$this->branch, 'claim', $claim->id]);
    $this->post($claimIssue, ['kind' => 'claim'])->assertSessionHasNoErrors();
    $intake = $this->branch->issuedDocuments()->sole();
    $this->post($claimIssue, ['kind' => 'claim_result'])->assertSessionHasErrors('kind');
    $review = route('documents.claims.review', [$this->branch, $claim]);
    $decision = ['decision' => 'approved', 'reason' => 'ตรวจแล้วเป็นอาการเดิม'];
    $this->patch($review, $decision)->assertForbidden();
    $this->actingAs($this->manager)->patch($review, $decision)->assertSessionHasNoErrors();
    $this->patch($review, ['decision' => 'rejected'] + $decision)->assertConflict();
    $this->post($claimIssue, ['kind' => 'claim_result'])->assertSessionHasNoErrors();
    $doc = $this->branch->issuedDocuments()->where('kind', 'claim_result')->sole();
    $this->get(route('documents.show', [$this->branch, $intake]))->assertOk()->assertSee('รับเคลม · รอพิจารณา');
    $this->get(route('documents.show', [$this->branch, $doc]))->assertOk()->assertSee('อนุมัติเคลม')->assertSee($this->job->reference());
    $this->get(route('crm.customers.show', [$this->branch, $this->customer]))->assertOk()->assertSee($claim->reference());
    $this->actingAs($this->owner)->get(route('documents.source', [$this->other, 'claim', $claim->id]))->assertNotFound();
});

test('handles a cancelled repair deposit through source refund and permits closing only after resolution', function () {
    worksetSixShift($this);
    $deposit = worksetSixPay($this, $this->job, '50', 'deposit');
    $params = [$this->branch, $this->job];
    $data = ['next_status' => 'returned_unrepaired', 'workflow_version' => 0, 'assignment_version' => 0,
        'recipient' => 'ผู้รับคืน', 'return_reason' => 'unrepairable', 'workflow_note' => 'ซ่อมไม่ได้', 'retention_reason' => 'พยายามปิดจากบัญชีแคชเชียร์'];
    $this->patch(route('repairs.change-status', $params), $data)->assertSessionHasErrors('retention_reason');
    $payment = [...$params, $deposit];
    $this->post(route('repairs.payments.refunds.request', $payment), [
        'line_id' => $deposit->lines()->sole()->id, 'refund_amount' => '50', 'refund_reason' => 'คืนเต็มจำนวน',
        'submission_id' => (string) Str::uuid(),
    ])->assertSessionHasNoErrors();
    $refund = $deposit->refunds()->sole();
    $this->actingAs($this->manager)->patch(route('repairs.change-status', $params), $data)->assertSessionHasErrors('next_status');
    $this->patch(route('repairs.payments.refunds.review', [...$payment, $refund]), [
        'decision' => 'approved', 'refund_review_note' => 'อนุมัติคืนตามยอดเดิม',
    ])->assertSessionHasNoErrors();
    $this->actingAs($this->cashier)->post(route('repairs.payments.refunds.complete', [...$payment, $refund]), [
        'paid' => 1, 'refund_proof' => UploadedFile::fake()->createWithContent('proof.png', file_get_contents(public_path('images/endu-care-workspace.png'))),
    ])->assertSessionHasNoErrors();
    $this->patch(route('repairs.change-status', $params), array_diff_key($data, ['retention_reason' => true]))->assertSessionHasNoErrors();
    $this->post(route('documents.issue', [$this->branch, 'refund', $refund->id]), ['kind' => 'refund'])->assertSessionHasNoErrors();
    $this->post(route('documents.issue', [$this->branch, 'repair', $this->job->id]), ['kind' => 'return'])->assertSessionHasNoErrors();
    $record = $this->branch->issuedDocuments()->where('kind', 'refund')->sole();
    $this->get(route('documents.show', [$this->branch, $record]))->assertOk()->assertSee($deposit->reference())->assertSee('อนุมัติคืนตามยอดเดิม');
    expect($deposit->refresh()->amount_satang)->toBe(5000);
    $this->job->refresh();
    expect($this->job->closure->details['refunded'])->toBe(5000);
    expect($this->job->closure->details['return_reason'])->toBe('ซ่อมไม่ได้');
    expect(RepairBilling::summary($this->job)['remaining'])->toBe(0);
});

test('POS supplies its settled sale, claim and completed reversal as document sources', function () {
    worksetSixSettings($this, short: true);
    worksetSixShift($this);
    $product = $this->branch->catalogProducts()->create([
        'sku' => 'SVC', 'name' => 'ค่าบริการทดสอบ', 'kind' => 'service', 'tracking' => 'none', 'retail_satang' => 10700, 'unit' => 'ครั้ง',
    ]);
    $this->post(route('pos.store', $this->branch), [
        'counter_id' => $this->counter->id, 'submission_id' => (string) Str::uuid(),
        'customer_mode' => 'existing', 'customer_id' => $this->customer->id, 'price_group' => 'retail',
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
    ])->assertSessionHasNoErrors();
    $sale = $this->branch->posSales()->sole();
    $this->post(route('pos.checkout', [$this->branch, $sale]), ['version' => 1, 'tendered' => '107'])->assertSessionHasNoErrors();
    $pos = [$this->branch, 'pos', $sale->id];
    $this->post(route('documents.issue', $pos), ['kind' => 'tax_short'])->assertSessionHasNoErrors();
    $this->post(route('documents.claims.store', $pos), ['submission_id' => (string) Str::uuid(), 'claimant' => 'ผู้ซื้อ', 'item' => 'ค่าบริการทดสอบ', 'issue' => 'ขอตรวจงานอีกครั้ง'])
        ->assertSessionHasNoErrors();
    $claim = $this->branch->serviceClaims()->sole();
    expect($claim->pos_sale_id)->toBe($sale->id);
    $till = $this->branch->counters()->create([
        'code' => 'back', 'name' => 'Back', 'payment_point_id' => $this->counter->payment_point_id, 'printer_profile_id' => $this->counter->printer_profile_id,
    ]);
    $this->actingAs($this->manager)->post(route('cash-shifts.open', $this->branch), [
        'submission_id' => (string) Str::uuid(), 'counter_id' => $till->id, 'opening_cash' => '500',
    ])->assertSessionHasNoErrors();
    $this->post(route('pos.void', [$this->branch, $sale]), ['version' => 1, 'reason' => 'ยกเลิกบริการ'])->assertSessionHasNoErrors();
    $this->post(route('documents.issue', $pos), ['kind' => 'refund'])->assertSessionHasNoErrors();
    $doc = $this->branch->issuedDocuments()->where('kind', 'refund')->sole();
    expect($doc->snapshot['amount'])->toBe(10700)->and($doc->source_reference)->toBe($sale->reference());
    $this->get(route('documents.show', [$this->branch, $doc]))->assertOk()->assertSee($sale->reference())->assertSee('ยกเลิกบริการ');
});
