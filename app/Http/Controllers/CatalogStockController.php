<?php

namespace App\Http\Controllers;

use App\Enums\BranchPermission;
use App\Models\Branch;
use App\Models\CatalogMovement;
use App\Models\RepairJob;
use App\Support\CatalogStock;
use App\Support\QuotationAmounts;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CatalogStockController extends Controller
{
    public function index(Request $request, Branch $branch): View
    {
        Gate::authorize('permission', [$branch, BranchPermission::ReadPartsDocuments]);
        $role = $branch->roleFor($request->user());
        $kind = $request->query('kind');
        $jobId = $request->query('job');
        $movements = $branch->catalogMovements()->with(['repair:id', 'actor:id,name', 'integrationOrder:id,reference'])
            ->when(! $role->allows(BranchPermission::ManageCatalogStock), fn ($query) => $query->whereIn('kind', ['issue', 'return']))
            ->when(! $role->allows(BranchPermission::ReadRepairs) && $role->allows(BranchPermission::ReadAssignedRepairs),
                fn ($query) => $query->whereHas('repair', fn ($jobs) => $jobs->where('technician_id', $request->user()->id)))
            ->when(is_string($kind) && isset(CatalogMovement::KINDS[$kind]), fn ($query) => $query->where('kind', $kind))
            ->when(is_string($jobId) && ctype_digit($jobId), fn ($query) => $query->where('repair_job_id', $jobId))
            ->orderByDesc('id')->cursorPaginate(25)->withQueryString();

        return view('catalog.stock', ['branch' => $branch, 'role' => $role, 'movements' => $movements,
            'warehouses' => $role->allows(BranchPermission::ManageCatalogStock) ? $branch->catalogWarehouses()->orderBy('name')->get() : collect(), 'kind' => $kind]);
    }

    public function warehouse(Request $request, Branch $branch): RedirectResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManageCatalogStock]);
        $fields = $request->validate(['name' => ['required', 'string', 'max:80']]);
        DB::transaction(function () use ($branch, $fields): void {
            Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $branch->catalogWarehouses()->firstOrCreate($fields);
        }, 3);

        return back()->with('status', 'บันทึกคลังแล้ว');
    }

    public function create(Branch $branch): View
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManageCatalogStock]);

        return $this->form($branch, null);
    }

    public function createForRepair(Branch $branch, RepairJob $repair): View
    {
        $this->authorizeRepairParts($branch, $repair);

        return $this->form($branch, $repair);
    }

    public function openRepair(Request $request, Branch $branch): RedirectResponse
    {
        abort_unless($request->user()->can('permission', [$branch, BranchPermission::ManageCatalogStock])
            || $request->user()->can('permission', [$branch, BranchPermission::ManageRepairParts]), 403);
        $fields = $request->validate(['reference' => ['required', 'string', 'regex:/^(?:REP-)?[0-9]{1,18}$/iD']]);
        $repair = $branch->repairs()->find((int) preg_replace('/^REP-/i', '', $fields['reference']));
        if ($repair === null) {
            throw ValidationException::withMessages(['reference' => 'ไม่พบใบงานในสาขานี้']);
        }
        $this->authorizeRepairParts($branch, $repair);

        return redirect()->route('repairs.parts.create', [$branch, $repair]);
    }

    public function receive(Request $request, Branch $branch): RedirectResponse
    {
        return $this->record($request, $branch, null);
    }

    public function forRepair(Request $request, Branch $branch, RepairJob $repair): RedirectResponse
    {
        return $this->record($request, $branch, $repair);
    }

    public function print(Branch $branch, CatalogMovement $catalogMovement): View
    {
        Gate::authorize('permission', [$branch, BranchPermission::ReadPartsDocuments]);
        $role = $branch->roleFor(auth()->user());
        $movement = $catalogMovement->load(['actor:id,name', 'repair:id,technician_id,branch_id', 'stockUnit', 'integrationOrder:id,reference', 'sourceMovement:id,kind', 'posSale:id']);
        if (in_array($movement->kind, ['receive', 'sale', 'sale_return'], true)) {
            abort_unless($role->allows(BranchPermission::ManageCatalogStock), 404);
        } elseif (! $role->allows(BranchPermission::ManageCatalogStock) && $role->allows(BranchPermission::ReadAssignedRepairs)) {
            abort_unless($movement->repair?->technician_id === auth()->id(), 404);
        }

        return view('catalog.movement-print', compact('branch', 'movement'));
    }

    private function form(Branch $branch, ?RepairJob $repair): View
    {
        $issues = $repair?->catalogMovements()->where('kind', 'issue')->with('stockUnit')->withSum('returns', 'quantity')->latest('id')->simplePaginate(25, pageName: 'issues');
        $warehouses = $branch->catalogWarehouses()
            ->when($repair !== null, fn ($query) => $query->where('kind', 'sale'))
            ->with(['locations' => fn ($query) => $query->orderBy('code')])->orderBy('name')->get();
        $submissionId = (string) Str::uuid();

        return view('catalog.movement-form', compact('branch', 'repair', 'issues', 'warehouses', 'submissionId'));
    }

    private function record(Request $request, Branch $branch, ?RepairJob $repair): RedirectResponse
    {
        if ($repair === null) {
            Gate::authorize('permission', [$branch, BranchPermission::ManageCatalogStock]);
        } else {
            $this->authorizeRepairParts($branch, $repair);
        }
        $fields = $request->validate([
            'submission_id' => ['required', 'uuid'],
            'issue_id' => [$repair ? 'nullable' : 'prohibited', 'integer', 'min:1'],
            'code' => ['required_without:issue_id', 'nullable', 'string', 'max:40'],
            'warehouse_id' => ['required_without:issue_id', 'nullable', 'integer', 'min:1'],
            'location_id' => [Rule::prohibitedIf($request->filled('issue_id')), 'nullable', 'integer', 'min:1'],
            'quantity' => ['required', 'integer', 'between:1,999999'],
            'receipt_cost' => [$repair === null ? 'nullable' : 'prohibited', QuotationAmounts::MONEY],
            'serial_number' => ['nullable', 'string', 'max:80'],
            'imei' => ['nullable', 'digits:15'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $movement = DB::transaction(function () use ($request, $branch, $repair, $fields): CatalogMovement {
            Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $job = $repair === null ? null : $branch->repairs()->lockForUpdate()->findOrFail($repair->id);
            if ($job !== null) {
                $this->authorizeRepairParts($branch, $job);
            }
            $existing = $branch->catalogMovements()->where('submission_id', $fields['submission_id'])->first();
            if ($existing !== null) {
                $expectedKind = isset($fields['issue_id']) ? 'return' : ($job ? 'issue' : 'receive');
                abort_unless($existing->repair_job_id === $job?->id && $existing->kind === $expectedKind
                    && (int) $existing->created_by === $request->user()->id
                    && (int) $existing->issue_id === (int) ($fields['issue_id'] ?? 0), 409);

                return $existing;
            }

            $issue = isset($fields['issue_id']) ? $job?->catalogMovements()->where('kind', 'issue')->findOrFail($fields['issue_id']) : null;
            $kind = $issue ? 'return' : ($job ? 'issue' : 'receive');
            if ($kind === 'issue' && $job->status->isClosed()) {
                throw ValidationException::withMessages(['code' => 'ใบงานปิดแล้ว จึงเบิกอะไหล่เพิ่มไม่ได้']);
            }
            $barcode = null;
            if ($issue !== null) {
                $product = $branch->catalogProducts()->lockForUpdate()->findOrFail($issue->catalog_product_id);
            } else {
                $code = strtoupper($fields['code']);
                $product = $branch->catalogProducts()->where(fn ($query) => $query->where('sku', $code)
                    ->orWhereHas('barcodes', fn ($barcodes) => $barcodes->where('code', $code)))->lockForUpdate()->first();
                if ($product === null || $product->tracking === 'none') {
                    throw ValidationException::withMessages(['code' => 'ไม่พบ SKU หรือบาร์โค้ดที่ติดตามสต๊อกในสาขานี้']);
                }
                $barcode = $product->barcodes()->where('code', $code)->first();
            }

            $warehouse = $branch->catalogWarehouses()->findOrFail($issue?->catalog_warehouse_id ?? $fields['warehouse_id']);
            $locationId = $issue?->catalog_location_id ?? $fields['location_id'] ?? null;
            $location = $locationId === null ? null : $warehouse->locations()->findOrFail($locationId);
            $quantity = (int) $fields['quantity'] * ($barcode?->multiplier ?? 1);

            return CatalogStock::move($branch, $product, $warehouse, $kind, $quantity, $fields, $request->user()->id, $job?->id, $issue, location: $location);
        }, 3);

        return redirect()->route('catalog.movements.print', [$branch, $movement])->with('status', 'บันทึกความเคลื่อนไหวคลังแล้ว');
    }

    private function authorizeRepairParts(Branch $branch, RepairJob $repair): void
    {
        if (Gate::allows('permission', [$branch, BranchPermission::ManageCatalogStock])) {
            return;
        }

        Gate::authorize('permission', [$branch, BranchPermission::ManageRepairParts]);
        $repair->setRelation('branch', $branch);
        Gate::authorize('view', $repair);
    }
}
