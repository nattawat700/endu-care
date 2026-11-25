<?php

namespace App\Http\Controllers;

use App\Enums\BranchPermission;
use App\Enums\RepairStatus;
use App\Models\Branch;
use App\Models\IssuedDocument;
use App\Models\PosSale;
use App\Models\RepairJob;
use App\Models\RepairPayment;
use App\Models\RepairRefund;
use App\Models\ServiceClaim;
use App\Models\User;
use App\Support\DocumentIssuance;
use App\Support\DocumentPdf;
use App\Support\DocumentPrintHistory;
use App\Support\TaxDocumentData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ServiceDocumentController extends Controller
{
    public const SOURCES = [
        'repair' => ['delivery', 'warranty', 'delivery_warranty', 'return'],
        'payment' => ['tax_full', 'tax_short'],
        'pos' => ['tax_full', 'tax_short', 'return', 'refund'],
        'refund' => ['refund'],
        'claim' => ['claim', 'claim_result'],
    ];

    public function index(Branch $branch): Response
    {
        $this->authorize($branch);
        $documents = $branch->issuedDocuments()->latest('id')->cursorPaginate(20, cursorName: 'documents');
        $claims = $branch->serviceClaims()->latest('id')->cursorPaginate(10, cursorName: 'claims');
        $activity = DB::table('document_prints')->where('branch_id', $branch->id)->join('users', 'users.id', '=', 'document_prints.printed_by')
            ->orderByDesc('document_prints.id')->simplePaginate(12, ['document_prints.number', 'document_prints.printed_at', 'users.name'], 'print_history');

        return response()->view('documents.index', compact('branch', 'documents', 'claims', 'activity'))->header('Cache-Control', 'private, no-store');
    }

    public function source(Branch $branch, string $source, int $id): Response
    {
        $this->authorize($branch);
        $record = $this->resolve($branch, $source, $id);
        $kinds = self::SOURCES[$source];
        $documents = $branch->issuedDocuments()->where(['source_type' => $source, 'source_id' => $id])->get()->keyBy('kind');
        $submissionId = (string) Str::uuid();

        return response()->view('documents.source', compact('branch', 'record', 'source', 'kinds', 'documents', 'submissionId'))
            ->header('Cache-Control', 'private, no-store');
    }

    public function issue(Request $request, Branch $branch, string $source, int $id): RedirectResponse
    {
        $this->authorize($branch);
        $fields = $request->validate(['kind' => ['required', Rule::in(self::SOURCES[$source] ?? [])]]);
        $kind = $fields['kind'];
        $doc = DB::transaction(function () use ($branch, $source, $id, $kind) {
            $branch = Branch::lockForUpdate()->findOrFail($branch->id);
            $this->authorize($branch);
            $record = $this->resolve($branch, $source, $id);
            $existing = $branch->issuedDocuments()->where(['source_type' => $source, 'source_id' => $id, 'kind' => $kind])->first();
            if ($existing) {
                return $existing;
            }

            if (in_array($kind, ['tax_full', 'tax_short'], true)) {
                if ($branch->issuedDocuments()->where(['source_type' => $source, 'source_id' => $id])->whereIn('kind', ['tax_full', 'tax_short'])->exists()) {
                    throw ValidationException::withMessages(['kind' => 'ต้นทางนี้ออกใบกำกับภาษีแล้ว กรุณาใช้ฉบับเดิมหรือให้บัญชีจัดการการเปลี่ยนประเภท']);
                }
                $snapshot = $source === 'payment'
                    ? TaxDocumentData::repair($branch, $branch->repairs()->lockForUpdate()->findOrFail($record->repair_job_id), $record, $kind)
                    : TaxDocumentData::pos($branch, $record, $kind);
            } else {
                $snapshot = match ($source) {
                    'repair' => $this->closure($record, $kind),
                    'claim' => $this->claimDocument($branch, $record, $kind),
                    'refund' => $this->refundDocument($branch, $record),
                    'pos' => $this->posReversal($record, $kind),
                };
            }

            return DocumentIssuance::issue($branch, $kind, $source, $id, $this->reference($record), $snapshot);
        }, 3);

        return redirect()->route('documents.show', [$branch, $doc]);
    }

    public function show(Branch $branch, IssuedDocument $issuedDocument): Response
    {
        $this->authorize($branch);
        $record = $this->resolve($branch, $issuedDocument->source_type, $issuedDocument->source_id);
        $prints = DocumentPrintHistory::query($branch, 'issued', $issuedDocument->id)
            ->join('users', 'users.id', '=', 'document_prints.printed_by')->orderByDesc('document_prints.id')
            ->simplePaginate(15, ['document_prints.id', 'document_prints.printed_at', 'users.name']);

        return response()->view('documents.show', ['document' => $issuedDocument] + compact('branch', 'prints', 'record'))->header('Cache-Control', 'private, no-store');
    }

    public function print(Branch $branch, IssuedDocument $issuedDocument, DocumentPdf $pdf): Response
    {
        $this->authorize($branch);
        $document = $issuedDocument;
        $this->resolve($branch, $document->source_type, $document->source_id);
        $sellerProfile = $document->snapshot['seller'];

        return DB::transaction(function () use ($branch, $document, $sellerProfile, $pdf) {
            $branch->issuedDocuments()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            $copy = DocumentPrintHistory::query($branch, 'issued', $document->id)->exists();
            $receiptId = $document->snapshot['receipt_id'] ?? null;
            $attachedReceipt = $receiptId === null ? null : $branch->repairPayments()->with(['repairJob', 'lines'])->findOrFail($receiptId);
            $additionalSellerProfiles = $attachedReceipt ? [$attachedReceipt->document['seller']] : [];
            $canViewPrivateCustomerDetails = Gate::allows('permission', [$branch, BranchPermission::PrivateCustomerDetails]);
            $response = $pdf->render('documents.print', compact('branch', 'document', 'sellerProfile', 'copy', 'attachedReceipt',
                'additionalSellerProfiles', 'canViewPrivateCustomerDetails'), $document->number);
            if ($attachedReceipt) {
                DocumentPrintHistory::record($response, $branch, 'repair_payment', $attachedReceipt->id, $attachedReceipt->reference());
            }

            return DocumentPrintHistory::record($response, $branch, 'issued', $document->id, $document->number);
        });
    }

    public function receiveClaim(Request $request, Branch $branch, string $source, int $id): RedirectResponse
    {
        $this->authorize($branch);
        abort_unless(in_array($source, ['repair', 'pos'], true), 404);
        $fields = $request->validate([
            'submission_id' => ['required', 'uuid'], 'claimant' => ['required', 'string', 'max:150'],
            'item' => ['required', 'string', 'max:180'], 'issue' => ['required', 'string', 'max:1500'],
        ]);
        $claim = DB::transaction(function () use ($request, $branch, $source, $id, $fields) {
            $branch = Branch::lockForUpdate()->findOrFail($branch->id);
            $this->authorize($branch);
            $record = $this->resolve($branch, $source, $id);
            $eligible = $source === 'repair' ? $record->status === RepairStatus::Delivered : $record->status === 'paid';
            abort_unless($eligible, 409);
            $identity = $source === 'repair' ? ['repair_job_id' => $id] : ['pos_sale_id' => $id];
            $current = $branch->serviceClaims()->where('submission_id', $fields['submission_id'])->first();
            if ($current) {
                abort_unless($current->received_by === $request->user()->id && $current->getAttribute(array_key_first($identity)) === $id, 409);

                return $current;
            }

            return $branch->serviceClaims()->create($identity + [
                'submission_id' => $fields['submission_id'], 'customer_id' => $record->customer_id,
                'received_by' => $request->user()->id, 'intake' => [
                    'claimant' => $fields['claimant'], 'item' => $fields['item'], 'issue' => $fields['issue'],
                    'reference' => $record->reference(), 'warranty_end' => $source === 'repair' ? ($record->closure?->details['warranty_end'] ?? null) : null,
                ],
            ]);
        }, 3);

        return redirect()->route('documents.source', [$branch, 'claim', $claim->id])->with('status', 'บันทึกรับเคลมแล้ว');
    }

    public function reviewClaim(Request $request, Branch $branch, ServiceClaim $serviceClaim): RedirectResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::ReviewServiceClaims]);
        $fields = $request->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])], 'reason' => ['required', 'string', 'max:1000']]);
        DB::transaction(function () use ($request, $branch, $serviceClaim, $fields) {
            $branch = Branch::lockForUpdate()->findOrFail($branch->id);
            Gate::authorize('permission', [$branch, BranchPermission::ReviewServiceClaims]);
            $claim = $branch->serviceClaims()->lockForUpdate()->findOrFail($serviceClaim->id);
            abort_unless($claim->status === 'received', 409);
            $claim->forceFill([
                'status' => $fields['decision'], 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(),
                'review' => ['reason' => $fields['reason'], 'reviewer' => $request->user()->name],
            ])->save();
        }, 3);

        return back()->with('status', 'บันทึกผลพิจารณาเคลมแล้ว');
    }

    private function authorize(Branch $branch): void
    {
        Gate::authorize('permission', [$branch, BranchPermission::IssueServiceDocuments]);
    }

    private function resolve(Branch $branch, string $source, int $id): RepairJob|RepairPayment|PosSale|RepairRefund|ServiceClaim
    {
        return match ($source) {
            'repair' => $branch->repairs()->findOrFail($id),
            'payment' => $branch->repairPayments()->findOrFail($id),
            'pos' => $branch->posSales()->findOrFail($id),
            'refund' => RepairRefund::where('branch_id', $branch->id)->findOrFail($id),
            'claim' => $branch->serviceClaims()->findOrFail($id),
            default => abort(404),
        };
    }

    private function reference(RepairJob|RepairPayment|PosSale|RepairRefund|ServiceClaim $record): string
    {
        return $record instanceof RepairRefund ? 'REFUND-'.$record->id : $record->reference();
    }

    private function closure(RepairJob $job, string $kind): array
    {
        $closure = $job->closure;
        $details = $closure?->details;
        $valid = match ($kind) {
            'return' => $closure?->outcome === RepairStatus::ReturnedUnrepaired->value,
            'warranty' => $closure?->outcome === RepairStatus::Delivered->value && $details['warranty_days'] > 0,
            default => $closure?->outcome === RepairStatus::Delivered->value,
        };
        if (! $valid) {
            throw ValidationException::withMessages(['kind' => 'ยังไม่มีบันทึกส่งมอบ ส่งคืน หรือระยะประกันที่ใช้กับเอกสารนี้']);
        }

        $snapshot = $details + ['closed_at' => $closure->closed_at->toIso8601String()];
        if ($kind === 'delivery_warranty') {
            $receipt = $job->payments()->where('status', 'confirmed')->whereIn('document_type', ['receipt', 'cash_receipt'])->latest('id')->first();
            if ($receipt) {
                $snapshot['receipt_id'] = $receipt->id;
                $snapshot['receipt_reference'] = $receipt->reference();
            }
        }

        return $snapshot;
    }

    private function claimDocument(Branch $branch, ServiceClaim $claim, string $kind): array
    {
        $origin = $claim->repair_job_id ? $branch->repairs()->findOrFail($claim->repair_job_id) : $branch->posSales()->findOrFail($claim->pos_sale_id);
        if ($kind === 'claim_result' && $claim->status === 'received') {
            throw ValidationException::withMessages(['kind' => 'กรุณาบันทึกผลพิจารณาเคลมก่อนออกเอกสาร']);
        }

        return $claim->intake + ['reference' => $origin->reference(), 'decision' => ServiceClaim::STATUSES[$claim->status],
            'review' => $claim->review, 'received_at' => $claim->created_at->toIso8601String(), 'reviewed_at' => $claim->reviewed_at?->toIso8601String()];
    }

    private function refundDocument(Branch $branch, RepairRefund $refund): array
    {
        if ($refund->status !== 'completed') {
            throw ValidationException::withMessages(['kind' => 'ออกหลักฐานได้เมื่อบันทึกคืนเงินจริงแล้ว']);
        }
        $payment = $branch->repairPayments()->findOrFail($refund->repair_payment_id);

        return [
            'reference' => $payment->reference(), 'repair' => $payment->repairJob->reference(), 'channel' => RepairPayment::CHANNELS[$refund->channel],
            'amount' => $refund->amount_satang, 'reason' => $refund->reason, 'review' => $refund->review_note,
            'reviewed_by' => $refund->reviewer?->name, 'settled_at' => $refund->paid_at->toIso8601String(),
        ];
    }

    private function posReversal(PosSale $sale, string $kind): array
    {
        $tender = $sale->tenders()->where('direction', 'returned')->first();
        abort_unless($sale->status === 'voided' && $sale->paid_at !== null && ($kind === 'return' || $tender), 409);

        return [
            'reference' => $sale->reference(), 'channel' => 'เงินสด', 'amount' => $tender?->amount_satang ?? 0,
            'reason' => $sale->void_reason, 'settled_at' => $sale->voided_at->toIso8601String(),
            'reviewed_by' => User::find($sale->voided_by)?->name,
            'movements' => $sale->movements()->where('kind', 'sale_return')->orderBy('id')->get()->map->reference()->all(),
        ];
    }
}
