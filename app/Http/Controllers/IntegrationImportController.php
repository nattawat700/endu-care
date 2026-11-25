<?php

namespace App\Http\Controllers;

use App\Enums\BranchPermission;
use App\Models\Branch;
use App\Models\IntegrationImport;
use App\Support\CatalogProductData;
use App\Support\CatalogSpreadsheet;
use App\Support\CustomerData;
use App\Support\IntegrationImportPlan;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IntegrationImportController extends Controller
{
    public function index(Branch $branch): View
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManageIntegrations]);
        $imports = $branch->integrationImports()->latest('id')->simplePaginate(20);

        return view('integrations.imports', compact('branch', 'imports') + ['batch' => null]);
    }

    public function show(Branch $branch, IntegrationImport $integrationImport): View
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManageIntegrations]);

        return view('integrations.imports', ['branch' => $branch, 'batch' => $integrationImport, 'imports' => null]);
    }

    public function preview(Request $request, Branch $branch): RedirectResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManageIntegrations]);
        $fields = $request->validate(['kind' => ['required', Rule::in(array_keys(IntegrationImport::KINDS))],
            'sheet' => ['required', 'file', 'extensions:csv,xlsx', 'mimes:csv,txt,xlsx', 'max:2048']]);
        [$columns, $required] = $this->schema($fields['kind']);
        $rows = CatalogSpreadsheet::read($fields['sheet'], $columns, $required);
        $batch = DB::transaction(function () use ($branch, $request, $fields, $rows): IntegrationImport {
            Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $plan = IntegrationImportPlan::inspect($branch, $fields['kind'], $rows);
            $hasErrors = collect($plan['report'])->contains(fn ($row) => $row['status'] === 'error');
            $branch->integrationImports()->where('created_by', $request->user()->id)->where('kind', $fields['kind'])
                ->where('status', 'preview')->update(['status' => 'expired', 'payload' => null]);

            return $branch->integrationImports()->create([
                'created_by' => $request->user()->id, 'kind' => $fields['kind'],
                'status' => $hasErrors ? 'invalid' : 'preview',
                'report' => $plan['report'],
                'payload' => $hasErrors ? null : ['input' => $rows, 'fingerprint' => $this->fingerprint($plan)],
            ]);
        }, 3);

        return redirect()->route('integrations.imports.show', [$branch, $batch]);
    }

    public function apply(Request $request, Branch $branch, IntegrationImport $integrationImport): RedirectResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManageIntegrations]);
        $saved = DB::transaction(function () use ($branch, $integrationImport, $request): bool {
            Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $batch = $branch->integrationImports()->lockForUpdate()->findOrFail($integrationImport->id);
            abort_unless((int) $batch->created_by === $request->user()->id, 403);
            if ($batch->status === 'applied') {
                return true;
            }
            if ($batch->status !== 'preview' || $batch->payload === null) {
                throw ValidationException::withMessages(['sheet' => 'กรุณาตรวจไฟล์ใหม่ก่อนยืนยัน']);
            }
            $payload = $batch->payload;
            $plan = IntegrationImportPlan::inspect($branch, $batch->kind, $payload['input']);
            if ($payload['fingerprint'] !== $this->fingerprint($plan)) {
                $batch->update(['status' => 'expired', 'payload' => null]);

                return false;
            }
            $batch->update(['report' => IntegrationImportPlan::apply($branch, $batch->kind, $plan, $request->user()->id),
                'payload' => null, 'status' => 'applied']);

            return true;
        }, 3);

        return redirect()->route('integrations.imports.show', [$branch, $integrationImport])
            ->with($saved ? 'status' : 'import_notice', $saved ? 'บันทึกผลนำเข้าแล้ว' : 'ทะเบียนเปลี่ยนหลังตรวจไฟล์ กรุณาตรวจไฟล์อีกครั้ง');
    }

    public function template(Request $request, Branch $branch): StreamedResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManageIntegrations]);
        $fields = $request->validate(['kind' => ['required', Rule::in(array_keys(IntegrationImport::KINDS))]]);
        [$columns] = $this->schema($fields['kind']);
        $sample = match ($fields['kind']) {
            'products' => ['sku' => 'DEMO-001', 'name' => 'อะไหล่ตัวอย่าง', 'kind' => 'part', 'tracking' => 'quantity', 'unit' => 'ชิ้น', 'retail' => '100.00'],
            'customers' => ['name' => 'ลูกค้าตัวอย่าง', 'phone' => '0810000000', 'service_channel' => 'phone', 'marketing_preference' => 'unspecified'],
            'opening' => ['sku' => 'DEMO-001', 'warehouse' => 'คลังหลัก', 'quantity' => '10'],
        };

        return CatalogSpreadsheet::download('integration-'.$fields['kind'].'.csv', $columns, [array_map(fn ($key) => $sample[$key] ?? '', $columns)]);
    }

    public function report(Branch $branch, IntegrationImport $integrationImport): StreamedResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManageIntegrations]);
        $rows = [];
        foreach ($integrationImport->report as $number => $row) {
            $rows[] = [(string) $number, $row['reference'], $row['status'], $row['reason'], (string) ($row['record'] ?? '')];
        }

        return CatalogSpreadsheet::download('import-'.$integrationImport->id.'.csv', ['row', 'reference', 'status', 'reason', 'record_id'], $rows);
    }

    private function schema(string $kind): array
    {
        return match ($kind) {
            'products' => [CatalogProductData::COLUMNS, ['sku', 'name', 'kind', 'tracking', 'unit', 'retail']],
            'customers' => [CustomerData::COLUMNS, ['name', 'phone']],
            'opening' => [IntegrationImportPlan::OPENING, ['sku', 'warehouse', 'quantity']],
        };
    }

    private function fingerprint(array $plan): string
    {
        return hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR));
    }
}
