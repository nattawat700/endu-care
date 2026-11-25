<?php

use App\Enums\BranchRole;
use App\Enums\QuotationDecision;
use App\Enums\RepairKind;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\RepairPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(Carbon::parse('2026-11-25 11:30:00', 'Asia/Bangkok'));
    $this->branch = Organization::create(['code' => 'endu', 'name' => 'ENDU'])
        ->branches()->create(['code' => 'fix', 'name' => 'ENDU FIX',
            'document_profile' => ['name' => 'ENDU FIX หัวเอกสารสาขา', 'address' => 'ที่อยู่สาขาตัวอย่าง']]);
    $this->otherBranch = Organization::create(['code' => 'elsewhere', 'name' => 'Another operation'])
        ->branches()->create(['code' => 'shop', 'name' => 'Another shop']);
    foreach (['frontdesk' => BranchRole::Reception, 'cashier' => BranchRole::Cashier, 'accounting' => BranchRole::Accounting, 'tech' => BranchRole::Technician] as $name => $role) {
        $this->{$name} = User::factory()->create();
        $this->branch->memberships()->create(['user_id' => $this->{$name}->id, 'role' => $role]);
    }
    $this->otherBranch->memberships()->create(['user_id' => $this->frontdesk->id, 'role' => BranchRole::Reception]);
    $point = $this->branch->paymentPoints()->create(['code' => 'test-point', 'name' => 'Test']);
    $printer = $this->branch->printerProfiles()->create(['name' => 'Test', 'paper' => 'a4', 'device_name' => 'Test']);
    foreach (['frontdesk', 'cashier', 'accounting'] as $name) {
        $counter = $this->branch->counters()->create(['code' => $name, 'name' => $name, 'printer_profile_id' => $printer->id, 'payment_point_id' => $point->id]);
        $this->branch->cashShifts()->create([
            'branch_counter_id' => $counter->id, 'branch_payment_point_id' => $point->id, 'active_counter' => $counter->id,
            'opened_by' => $this->{$name}->id, 'active_operator' => $this->{$name}->id, 'opening_satang' => 100000, 'submission_id' => (string) Str::uuid(),
        ]);
    }

    $this->intake = ['created_by' => $this->frontdesk->id,
        'customer_name' => 'ลูกค้าทดสอบ', 'customer_phone' => '0812345678', 'customer_address' => 'ที่อยู่ลูกค้าทดสอบ',
        'device' => 'iPhone 13', 'issue' => 'จอเสีย', 'work_kind' => RepairKind::Quick, 'fixed_price_satang' => 65000];
    $this->repair = $this->branch->repairs()->create(['submission_id' => (string) Str::uuid()] + $this->intake);
    $this->index = route('repairs.payments.index', [$this->branch, $this->repair]);
    $this->store = route('repairs.payments.store', [$this->branch, $this->repair]);
});

function paymentDocumentFields($test, array $overrides = []): array
{
    $billing = $test->get($test->index)->assertOk()->viewData('billing');

    return $overrides + ['submission_id' => (string) Str::uuid(), 'billing_version' => $billing['version'],
        'confirmed' => '1', 'channel' => 'cash', 'document_type' => 'receipt', 'amount' => '200.00'];
}

test('fixed price deposits and cash receipts share the quotation layout and persist each real receipt once', function () {
    $this->get($this->index)->assertRedirectToRoute('login');
    $this->actingAs($this->frontdesk)->get(route('repairs.show', [$this->branch, $this->repair]))
        ->assertOk()->assertSee('รับเงินและออกใบเสร็จ');
    $deposit = paymentDocumentFields($this, ['document_type' => 'deposit', 'note' => 'มัดจำ <test>']);
    $this->from($this->index)->post($this->store, ['amount' => '650.00'] + $deposit)->assertSessionHasErrors('document_type');
    $this->from($this->index)->post($this->store, ['amount' => '650.01'] + $deposit)->assertSessionHasErrors('amount');
    $this->from($this->index)->post($this->store, ['confirmed' => '0'] + $deposit)->assertSessionHasErrors('confirmed');
    $this->assertDatabaseEmpty('repair_payments');

    $this->post($this->store, $deposit)->assertSessionHasNoErrors();
    $payment = $this->repair->payments()->sole();
    $show = route('repairs.payments.show', [$this->branch, $this->repair, $payment]);
    $this->post($this->store, $deposit)->assertRedirect($show);
    $this->from($this->index)->post($this->store, ['submission_id' => (string) Str::uuid()] + $deposit)->assertSessionHasErrors('billing_version');
    $document = $this->get($show)->assertOk()->assertSee('ใบรับเงินมัดจำ')->assertSee('Deposit Receipt')
        ->assertSee('quotation-doc-head', false)->assertSee('quotation-signatures', false)->assertSee('650.00 บาท')
        ->assertSee('200.00 บาท')->assertSee('450.00 บาท')->assertSee($this->repair->reference())->assertSee($payment->paymentReference())
        ->assertSee('25/11/2026')->assertSee('ที่อยู่สาขาตัวอย่าง')->assertSee('ที่อยู่ลูกค้าทดสอบ')
        ->assertSee('มัดจำ &lt;test&gt;', false)->assertDontSee('<test>', false);
    $print = route('repairs.payments.print', [$this->branch, $this->repair, $payment]);
    $pdf = route('repairs.payments.print', [$this->branch, $this->repair, $payment, 'format' => 'pdf']);
    $document->assertSee('href="'.e($pdf).'"', false);
    $this->get($print)->assertOk()->assertSee('data-payment-print-page', false)->assertSee($payment->reference())
        ->assertSee('450.00 บาท')->assertDontSee('endu-shell--staff', false);
    expect($payment->remainingSatang())->toBe(45000);
    $this->assertDatabaseCount('repair_payments', 1);

    $this->branch->update(['document_profile' => ['name' => 'หัวสาขาเปลี่ยนใหม่']]);
    $this->actingAs($this->cashier);
    $cash = paymentDocumentFields($this, ['document_type' => 'cash_receipt', 'amount' => '450.00']);
    $this->post($this->store, $cash)->assertSessionHasNoErrors();
    $final = $this->repair->payments()->latest('id')->firstOrFail();
    $this->get(route('repairs.payments.show', [$this->branch, $this->repair, $final]))->assertOk()
        ->assertSee('ใบเสร็จเงินสด')->assertSee('Cash Receipt')->assertSee('รับชำระก่อนครั้งนี้')->assertSee('0.00 บาท')
        ->assertSee('หัวสาขาเปลี่ยนใหม่')->assertDontSee('ที่อยู่ลูกค้าทดสอบ');
    $this->get($show)->assertOk()->assertSee('ที่อยู่สาขาตัวอย่าง')->assertSee('450.00 บาท')->assertDontSee('หัวสาขาเปลี่ยนใหม่');
    $this->get($this->index)->assertSee('ยอดที่ต้องชำระครบแล้ว');
    $this->assertDatabaseCount('repair_payments', 2);
});

test('payment amount follows the latest accepted quote including its payable adjustment and leaves earlier document amounts unchanged', function () {
    $this->actingAs($this->frontdesk);
    $items = [['description' => 'หน้าจอ OLED ทดสอบ', 'type' => 'part', 'quantity' => 1, 'unit' => 'ชิ้น', 'unit_satang' => 180000, 'total_satang' => 180000]];
    $quote = $this->repair->quotations()->create([
        'revision' => 1, 'submission_id' => (string) Str::uuid(), 'organization_name' => 'ENDU',
        'branch_name' => $this->branch->name, 'customer_name' => 'ลูกค้าตามใบเสนอราคา', 'device' => $this->repair->device,
        'issued_by' => $this->frontdesk->id, 'issuer_name' => $this->frontdesk->name, 'terms' => 'ตามตกลง',
        'items' => $items, 'total_satang' => 180000, 'requires_internal_approval' => true,
        'amount_breakdown' => ['subtotal_satang' => 180000, 'vat_mode' => 'none', 'payable_satang' => 175000, 'withholding_satang' => 5000],
        'document_details' => ['credit_days' => 2],
    ]);
    $noApproved = paymentDocumentFields($this);
    $this->from($this->index)->post($this->store, $noApproved)->assertSessionHasErrors('billing_version');
    $quote->forceFill(['approved_at' => now(), 'internal_decision' => QuotationDecision::Approved])->save();
    $this->travel(3)->days();
    $this->get($this->index)->assertOk()->assertSee('ค้างรับ');
    $agreed = paymentDocumentFields($this, ['amount' => '1800']);
    $this->from($this->index)->post($this->store, $agreed)->assertSessionHasErrors('amount');
    $this->post($this->store, ['amount' => '1750'] + $agreed)->assertSessionHasNoErrors();
    $payment = $this->repair->payments()->sole();
    expect($payment->repair_quotation_id)->toBe($quote->id);
    expect($payment->amount_satang)->toBe(175000);
    $this->get(route('repairs.payments.show', [$this->branch, $this->repair, $payment]))->assertOk()
        ->assertSee('ใบเสร็จรับเงิน')->assertSee($quote->reference())->assertSee('หน้าจอ OLED ทดสอบ')
        ->assertSee('ลูกค้าตามใบเสนอราคา')->assertSee('1,800.00 บาท')->assertSee('1,750.00 บาท');
});

test('transfer proof is private and tied to one verified receipt with branch and repair authorization', function () {
    Storage::fake('local');
    $this->actingAs($this->accounting);
    $lookup = route('payment-documents.index', [$this->branch, 'repair_reference' => $this->repair->reference()]);
    $this->get($lookup)->assertRedirect($this->index);
    $details = paymentDocumentFields($this, ['channel' => 'transfer', 'transfer_reference' => 'bank-example-01']);
    $this->from($this->index)->post($this->store, $details)->assertSessionHasErrors('slip');
    $sampleSlip = fn () => UploadedFile::fake()->createWithContent('sample-slip.png', file_get_contents(public_path('images/endu-fix-receipt-logo.png')));
    $this->from($this->index)->post($this->store, $details + ['slip' => UploadedFile::fake()->createWithContent('bad.png', 'not an image')])->assertSessionHasErrors('slip');
    $this->from($this->index)->post($this->store, ['document_type' => 'cash_receipt'] + $details + ['slip' => $sampleSlip()])->assertSessionHasErrors('document_type');
    $this->post($this->store, $details + ['slip' => $sampleSlip()])->assertSessionHasNoErrors();
    $payment = $this->repair->payments()->sole();
    $receipt = route('repairs.payments.show', [$this->branch, $this->repair, $payment]);
    $this->post($this->store, $details + ['slip' => $sampleSlip()])->assertRedirect($receipt);
    $this->patch(route('repairs.payments.verify', [$this->branch, $this->repair, $payment]), ['decision' => 'confirmed'])->assertSessionHasNoErrors();
    $line = $payment->lines()->sole();
    $fresh = paymentDocumentFields($this, ['channel' => 'transfer', 'transfer_reference' => 'other-ref', 'slip' => $sampleSlip()]);
    $this->from($this->index)->post($this->store, $fresh)->assertSessionHasErrors('transfer_reference');
    $this->assertDatabaseCount('repair_payments', 1);
    $slipDirectory = RepairPayment::slipDirectory($this->branch);
    expect(Storage::disk('local')->allFiles($slipDirectory))->toBe([$slipDirectory.'/'.$line->slip_filename]);
    $slip = route('repairs.payments.slip', [$this->branch, $this->repair, $payment, 'line' => $line->id]);
    $this->get($receipt)->assertOk()->assertSee('เปิดสลิป โอนเงิน')->assertDontSee($line->slip_filename);
    $this->get($slip)->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->get(route('payment-documents.index', $this->branch))->assertOk()->assertSee($payment->reference());

    $this->actingAs($this->tech)->get($slip)->assertForbidden();
    $this->get(route('repairs.payments.print', [$this->branch, $this->repair, $payment]))->assertForbidden();
    $this->get(route('repairs.payments.print', [$this->branch, $this->repair, $payment, 'format' => 'pdf']))->assertForbidden();
    $this->post($this->store, $details)->assertForbidden();
    $sameBranchRepair = $this->branch->repairs()->create(['submission_id' => (string) Str::uuid()] + $this->intake);
    $otherBranchRepair = $this->otherBranch->repairs()->create(['submission_id' => (string) Str::uuid()] + $this->intake);
    $this->actingAs($this->frontdesk)->get(route('repairs.payments.slip', [$this->branch, $sameBranchRepair, $payment]))->assertNotFound();
    $this->get(route('repairs.payments.slip', [$this->otherBranch, $otherBranchRepair, $payment]))->assertNotFound();
    $this->get(route('repairs.payments.slip', [$this->otherBranch, $this->repair, $payment]))->assertNotFound();
    $this->get(route('repairs.payments.print', [$this->otherBranch, $otherBranchRepair, $payment]))->assertNotFound();
    $events = AuditEvent::where('subject_type', 'repair_payment')->where('subject_reference', $payment->reference())->where('operation', 'created')->sole();
    expect($events->after_values)->not->toHaveKeys(['slip_filename', 'transfer_reference', 'document']);
});
