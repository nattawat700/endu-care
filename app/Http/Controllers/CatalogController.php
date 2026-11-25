<?php

namespace App\Http\Controllers;

use App\Enums\BranchPermission;
use App\Models\Branch;
use App\Models\CatalogProduct;
use App\Models\RepairJob;
use App\Models\RepairQuotation;
use App\Support\CatalogProductData;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class CatalogController extends Controller
{
    public function index(Request $request, Branch $branch): View
    {
        Gate::authorize('permission', [$branch, BranchPermission::ReadCatalog]);
        $filters = [];
        foreach (['q', 'category', 'brand', 'unit', 'model'] as $key) {
            $value = $request->query($key);
            $filters[$key] = is_string($value) ? Str::substr(trim($value), 0, 140) : '';
        }
        $context = $request->routeIs('catalog.pos') ? 'pos' : 'catalog';
        $query = $branch->catalogProducts()
            ->withSum(['balances' => fn ($query) => $query->whereHas('warehouse', fn ($warehouses) => $warehouses->where('kind', 'sale'))], 'quantity')
            ->withCount(['units as available_units_count' => fn ($query) => $query->where('available', true)
                ->whereHas('warehouse', fn ($warehouses) => $warehouses->where('kind', 'sale'))]);
        foreach (['category', 'brand', 'unit'] as $field) {
            $query->when($filters[$field] !== '', fn ($query) => $query->where($field, $filters[$field]));
        }
        $query->when($filters['model'] !== '', fn ($query) => $query->whereHas('compatibilities', fn ($models) => $models->where('device_model', $filters['model'])));
        $scan = null;
        $scanRetail = null;
        if ($filters['q'] !== '') {
            $code = strtoupper($filters['q']);
            $escaped = strtr($filters['q'], ['!' => '!!', '%' => '!%', '_' => '!_']);
            $query->where(fn ($items) => $items->where('sku', $code)->orWhereRaw("name LIKE ? ESCAPE '!'", ['%'.$escaped.'%'])
                ->orWhereHas('barcodes', fn ($barcodes) => $barcodes->where('code', $code))
                ->orWhereHas('units', fn ($units) => $units->where('imei', $code)->orWhere('serial_number', $code)));
            $scannedProduct = (clone $query)->whereHas('barcodes', fn ($barcodes) => $barcodes->where('code', $code))
                ->with(['barcodes' => fn ($barcodes) => $barcodes->where('code', $code)])->first();
            $scan = $scannedProduct?->barcodes->first();
            $scanRetail = $scan === null ? null : RepairQuotation::formatBaht($scannedProduct->retail_satang * $scan->multiplier);
        }
        $products = $query->orderBy('sku')->orderBy('id')->cursorPaginate(25)->appends(array_filter($filters, fn ($value) => $value !== ''));
        $role = $branch->roleFor($request->user());

        return view('catalog.index', compact('branch', 'products', 'filters', 'role', 'scan', 'scanRetail', 'context'));
    }

    public function create(Branch $branch): View
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManageCatalog]);

        return view('catalog.form', ['branch' => $branch, 'product' => new CatalogProduct]);
    }

    public function quotationLookup(Request $request, Branch $branch, RepairJob $repair): JsonResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::DraftQuotation]);
        Gate::authorize('permission', [$branch, BranchPermission::ReadCatalog]);
        $repair->setRelation('branch', $branch);
        Gate::authorize('view', $repair);
        $fields = $request->validate(['q' => ['required', 'string', 'max:140'], 'model' => ['nullable', 'string', 'max:100']]);
        $code = strtoupper($fields['q']);
        $escaped = strtr($fields['q'], ['!' => '!!', '%' => '!%', '_' => '!_']);
        $products = $branch->catalogProducts()
            ->with(['barcodes' => fn ($query) => $query->where('code', $code)])
            ->when(isset($fields['model']), fn ($query) => $query->whereHas('compatibilities', fn ($models) => $models->where('device_model', $fields['model'])))
            ->where(fn ($query) => $query->where('sku', $code)->orWhereRaw("name LIKE ? ESCAPE '!'", ['%'.$escaped.'%'])
                ->orWhereHas('barcodes', fn ($barcodes) => $barcodes->where('code', $code)))
            ->orderBy('sku')->limit(15)->get();
        $canGroups = auth()->user()->can('permission', [$branch, BranchPermission::CatalogGroupPrices]);

        return response()->json($products->map(function (CatalogProduct $product) use ($canGroups): array {
            $scan = $product->barcodes->first();
            $multiplier = $scan?->multiplier ?? 1;
            $prices = [];
            foreach (['retail', ...($canGroups ? ['dealer', 'partner'] : [])] as $group) {
                $value = $product->{$group.'_satang'};
                if ($value !== null && $value * $multiplier <= 999999999) {
                    $prices[$group] = RepairQuotation::formatBaht($value * $multiplier, '');
                }
            }

            return [
                'description' => $product->sku.' · '.$product->name,
                'unit' => $scan?->unit ?? $product->unit,
                'type' => match ($product->kind) {
                    'part' => 'part', 'labor', 'service' => 'labor', default => 'product'
                },
                'prices' => $prices,
            ];
        })->values(), headers: ['Cache-Control' => 'private, no-store']);
    }

    public function show(Branch $branch, CatalogProduct $catalogProduct): View
    {
        Gate::authorize('permission', [$branch, BranchPermission::ReadCatalog]);
        $product = $catalogProduct->load(['barcodes', 'compatibilities', 'balances.warehouse', 'balances.locations.location']);
        $units = $product->units()->with(['warehouse', 'location'])->orderByDesc('id')->cursorPaginate(20);

        return view('catalog.show', compact('branch', 'product', 'units'));
    }

    public function edit(Branch $branch, CatalogProduct $catalogProduct): View
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManageCatalog]);

        return view('catalog.form', ['branch' => $branch, 'product' => $catalogProduct->load(['barcodes', 'compatibilities'])]);
    }

    public function store(Request $request, Branch $branch): RedirectResponse
    {
        return $this->save($request, $branch, null);
    }

    public function update(Request $request, Branch $branch, CatalogProduct $catalogProduct): RedirectResponse
    {
        return $this->save($request, $branch, $catalogProduct);
    }

    public function image(Branch $branch, CatalogProduct $catalogProduct): StreamedResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::ReadCatalog]);
        $disk = Storage::disk('local');
        abort_if($catalogProduct->image === null, 404);
        $path = 'catalog-images/'.$branch->id.'/'.$catalogProduct->image;
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, headers: ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    private function save(Request $request, Branch $branch, ?CatalogProduct $product): RedirectResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManageCatalog]);
        $permissions = $branch->roleFor($request->user());
        $data = CatalogProductData::prepare($request->only(CatalogProductData::COLUMNS),
            $permissions->allows(BranchPermission::CatalogGroupPrices), $permissions->allows(BranchPermission::Costs));
        $fields = $request->validate([
            'version' => [$product ? 'required' : 'nullable', 'integer', 'min:1'],
            'image' => ['bail', 'nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:2048', 'dimensions:max_width=6000,max_height=6000'],
            'remove_image' => ['sometimes', 'boolean'],
        ], ['image.max' => 'รูปต้องมีขนาดไม่เกิน 2 MB', 'image.dimensions' => 'รูปต้องไม่เกิน 6,000 พิกเซลต่อด้าน']);
        $newPath = isset($fields['image']) ? $fields['image']->store('catalog-images/'.$branch->id, 'local') : null;
        if ($newPath === false) {
            throw ValidationException::withMessages(['image' => 'บันทึกรูปสินค้าไม่สำเร็จ']);
        }
        $oldImage = $product?->image;
        $replacesImage = $newPath !== null || $request->boolean('remove_image');
        if ($replacesImage) {
            $data['product']['image'] = $newPath === null ? null : basename($newPath);
        }
        try {
            $saved = DB::transaction(function () use ($branch, $data, $product, $fields): CatalogProduct {
                Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();

                return CatalogProductData::save($branch, $data, $product, isset($fields['version']) ? (int) $fields['version'] : null);
            }, 3);
        } catch (Throwable $exception) {
            if ($newPath !== null) {
                Storage::disk('local')->delete($newPath);
            }

            throw $exception;
        }
        if ($replacesImage && $oldImage !== null) {
            Storage::disk('local')->delete('catalog-images/'.$branch->id.'/'.$oldImage);
        }

        return redirect()->route('catalog.show', [$branch, $saved])->with('status', 'บันทึกสินค้าแล้ว');
    }
}
