<?php

namespace App\Http\Controllers;

use App\Enums\BranchPermission;
use App\Models\Branch;
use App\Models\IntegrationAccountingExport;
use App\Models\IntegrationConnection;
use App\Models\RepairQuotation;
use App\Support\CatalogSpreadsheet;
use App\Support\QuotationAmounts;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IntegrationAccountingController extends Controller
{
    public function index(Branch $branch, IntegrationConnection $integrationConnection): View
    {
        $this->authorizeAccounting($branch, $integrationConnection);
        $connection = $integrationConnection;
        $movements = $branch->catalogMovements()->whereNotIn('id', $this->exported($connection))->orderByDesc('id')->simplePaginate(25, pageName: 'movements');
        $exports = $connection->accountingExports()->latest('id')->simplePaginate(20, pageName: 'exports');
        $submissionId = (string) Str::uuid();

        return view('integrations.accounting', compact('branch', 'connection', 'movements', 'exports', 'submissionId'));
    }

    public function store(Request $request, Branch $branch, IntegrationConnection $integrationConnection): RedirectResponse
    {
        $this->authorizeAccounting($branch, $integrationConnection);
        $fields = $request->validate(['submission_id' => ['required', 'uuid'],
            'movements' => ['required', 'array', 'list', 'min:1', 'max:25'], 'movements.*' => ['required', 'integer', 'min:1', 'distinct'],
            'legacy_cost' => ['nullable', 'array', 'max:25'], 'legacy_cost.*' => ['nullable', QuotationAmounts::MONEY]]);
        $export = DB::transaction(function () use ($branch, $integrationConnection, $request, $fields): IntegrationAccountingExport {
            Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $connection = $branch->integrationConnections()->lockForUpdate()->findOrFail($integrationConnection->id);
            $existing = $connection->accountingExports()->where('submission_id', $fields['submission_id'])->first();
            if ($existing) {
                return $existing;
            }
            if (! $connection->enabled) {
                throw ValidationException::withMessages(['movements' => 'เปิดใช้โปรไฟล์บัญชีก่อนส่งออก']);
            }
            $movements = $branch->catalogMovements()->whereKey($fields['movements'])->whereNotIn('id', $this->exported($connection))->orderBy('id')->get();
            if ($movements->count() !== count($fields['movements'])) {
                throw ValidationException::withMessages(['movements' => 'มีรายการที่ส่งออกแล้วหรือไม่อยู่ในสาขานี้']);
            }
            $accounts = $connection->credentials;
            $rows = [];
            foreach ($movements as $movement) {
                $cost = $movement->costForQuantity($movement->quantity);
                if ($cost === null && isset($fields['legacy_cost'][$movement->id])) {
                    $cost = QuotationAmounts::hundredths($fields['legacy_cost'][$movement->id]) * $movement->quantity;
                }
                if ($cost === null) {
                    throw ValidationException::withMessages(['movements' => $movement->reference().' ยังไม่มีต้นทุนที่ตรวจแล้ว']);
                }
                $contra = $accounts[match ($movement->kind) {
                    'receive' => 'receiving_account',
                    'issue', 'return' => 'repair_account',
                    'sale', 'sale_return' => 'sales_cost_account',
                }];
                $inventory = $accounts['inventory_account'];
                [$debit, $credit] = in_array($movement->kind, ['receive', 'return', 'sale_return'], true) ? [$inventory, $contra] : [$contra, $inventory];
                $date = $movement->created_at->timezone('Asia/Bangkok')->toDateString();
                $amount = RepairQuotation::formatBaht($cost, '');
                $rows[] = [$movement->reference(), $date, $movement->sku, $debit, $amount, '0.00'];
                $rows[] = [$movement->reference(), $date, $movement->sku, $credit, '0.00', $amount];
            }
            $export = $connection->accountingExports()->create([
                'created_by' => $request->user()->id, 'submission_id' => $fields['submission_id'], 'rows' => $rows,
            ]);
            DB::table('integration_accounting_items')->insert($movements->map(fn ($movement) => [
                'integration_connection_id' => $connection->id, 'integration_accounting_export_id' => $export->id,
                'catalog_movement_id' => $movement->id,
            ])->all());

            return $export;
        }, 3);

        return redirect()->route('integrations.accounting.index', [$branch, $integrationConnection])->with('status', 'สร้างชุดบัญชี #'.$export->id.' แล้ว');
    }

    public function download(Branch $branch, IntegrationConnection $integrationConnection, IntegrationAccountingExport $accountingExport): StreamedResponse
    {
        $this->authorizeAccounting($branch, $integrationConnection);

        return CatalogSpreadsheet::download('warehouse-accounting-'.$accountingExport->id.'.csv',
            ['source_document', 'date', 'sku', 'account', 'debit', 'credit'], $accountingExport->rows);
    }

    private function authorizeAccounting(Branch $branch, IntegrationConnection $connection): void
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManageIntegrations]);
        Gate::authorize('permission', [$branch, BranchPermission::Costs]);
        abort_unless($connection->kind === 'accounting', 404);
    }

    private function exported(IntegrationConnection $connection): Builder
    {
        return DB::table('integration_accounting_items')->where('integration_connection_id', $connection->id)->select('catalog_movement_id');
    }
}
