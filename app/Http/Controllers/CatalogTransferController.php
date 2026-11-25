<?php

namespace App\Http\Controllers;

use App\Enums\BranchPermission;
use App\Models\Branch;
use App\Models\CatalogProduct;
use App\Models\RepairQuotation;
use App\Support\CatalogProductData;
use App\Support\CatalogSpreadsheet;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CatalogTransferController extends Controller
{
    public function index(Request $request, Branch $branch): View
    {
        Gate::authorize('permission', [$branch, BranchPermission::ReadCatalog]);

        return view('catalog.transfer', [
            'branch' => $branch, 'allowed' => $this->columns($branch), 'preview' => $request->session()->get('catalog_import.'.$branch->id),
        ]);
    }

    public function preview(Request $request, Branch $branch): RedirectResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManageCatalog]);
        $request->session()->forget('catalog_import.'.$branch->id);
        $fields = $request->validate(['sheet' => ['required', 'file', 'extensions:csv,xlsx', 'mimes:csv,txt,xlsx', 'max:2048']]);
        $canSeeCost = auth()->user()->can('permission', [$branch, BranchPermission::Costs]);
        $canSeeGroups = auth()->user()->can('permission', [$branch, BranchPermission::CatalogGroupPrices]);
        $input = CatalogSpreadsheet::read($fields['sheet']);
        $rows = [];
        $used = [];
        foreach ($input as $number => $row) {
            $sku = Str::limit((string) ($row['sku'] ?? ''), 32, '');
            try {
                $data = CatalogProductData::prepare($row, $canSeeGroups, $canSeeCost);
                $existing = $branch->catalogProducts()->where('sku', $data['product']['sku'])->first();
                foreach ([$data['product']['sku'], ...array_column($data['barcodes'] ?? [], 'code')] as $code) {
                    $used[$code][] = $number;
                }
                CatalogProductData::checkConflicts($branch, $data, $existing);
                $rows[$number] = ['sku' => $sku, 'action' => $existing ? 'update' : 'create', 'version' => $existing?->version, 'id' => $existing?->id, 'data' => $data];
            } catch (ValidationException $exception) {
                $rows[$number] = ['sku' => $sku, 'errors' => $exception->errors()];
            }
        }
        foreach ($used as $code => $numbers) {
            if (count($numbers) > 1) {
                foreach ($numbers as $number) {
                    $rows[$number]['errors']['duplicate'][] = 'รหัส '.$code.' ซ้ำในไฟล์';
                }
            }
        }
        $request->session()->put('catalog_import.'.$branch->id, ['token' => (string) Str::uuid(), 'rows' => $rows]);

        return redirect()->route('catalog.transfer', $branch);
    }

    public function apply(Request $request, Branch $branch): RedirectResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManageCatalog]);
        $fields = $request->validate(['token' => ['required', 'uuid']]);
        $batch = $request->session()->get('catalog_import.'.$branch->id);
        if ($batch === null || $batch['token'] !== $fields['token'] || collect($batch['rows'])->contains(fn ($row) => isset($row['errors']))) {
            throw ValidationException::withMessages(['sheet' => 'กรุณาตรวจตัวอย่างที่ไม่มีแถวผิดพลาดก่อนยืนยัน']);
        }
        $allowed = $this->columns($branch);
        DB::transaction(function () use ($branch, $batch, $allowed): void {
            Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            foreach ($batch['rows'] as $row) {
                foreach (['dealer', 'partner', 'cost'] as $price) {
                    abort_if(array_key_exists($price.'_satang', $row['data']['product']) && ! in_array($price, $allowed, true), 403);
                }
                $current = $row['id'] === null ? null : $branch->catalogProducts()->findOrFail($row['id']);
                CatalogProductData::save($branch, $row['data'], $current, $row['version']);
            }
        }, 3);
        $request->session()->forget('catalog_import.'.$branch->id);

        return redirect()->route('catalog.index', $branch)->with('status', 'นำเข้าสินค้าสำเร็จ '.count($batch['rows']).' รายการ');
    }

    public function template(Branch $branch): StreamedResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManageCatalog]);
        $columns = $this->columns($branch);
        $sample = ['sku' => 'DEMO-001', 'name' => 'หน้าจอตัวอย่าง', 'kind' => 'part', 'tracking' => 'quantity', 'unit' => 'ชิ้น', 'retail' => '150.00'];

        return CatalogSpreadsheet::download('catalog-template.csv', $columns, [array_map(fn ($key) => $sample[$key] ?? '', $columns)]);
    }

    public function export(Request $request, Branch $branch): StreamedResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::ReadCatalog]);
        $fields = $request->validate(['columns' => ['required', 'array', 'list', 'min:1', 'max:12'],
            'columns.*' => ['required', 'string', 'distinct', Rule::in($this->columns($branch))]]);
        $columns = $fields['columns'];
        $rows = $branch->catalogProducts()
            ->when(in_array('models', $columns, true), fn ($query) => $query->with('compatibilities'))
            ->when(in_array('barcodes', $columns, true), fn ($query) => $query->with('barcodes'))
            ->lazyById()->map(fn (CatalogProduct $product) => array_map(fn ($column) => $this->value($product, $column), $columns));

        return CatalogSpreadsheet::download('catalog-'.$branch->code.'.csv', $columns, $rows);
    }

    private function columns(Branch $branch): array
    {
        $role = $branch->roleFor(auth()->user());

        return array_values(array_filter(CatalogProductData::COLUMNS, fn ($key) => match ($key) {
            'cost' => $role->allows(BranchPermission::Costs),
            'dealer', 'partner' => $role->allows(BranchPermission::CatalogGroupPrices),
            default => true,
        }));
    }

    private function value(CatalogProduct $product, string $column): string
    {
        if (isset(CatalogProductData::PRICES[$column])) {
            $price = $product->{$column.'_satang'};

            return $price === null ? '' : RepairQuotation::formatBaht($price, '');
        }

        return match ($column) {
            'models' => $product->compatibilities->pluck('device_model')->implode("\n"),
            'barcodes' => $product->barcodes->map(fn ($row) => $row->code.'|'.$row->unit.'|'.$row->multiplier)->implode("\n"),
            default => (string) $product->$column,
        };
    }
}
