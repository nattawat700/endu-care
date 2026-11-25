<?php

namespace App\Http\Controllers;

use App\Enums\BranchPermission;
use App\Models\Branch;
use App\Models\CatalogProduct;
use App\Support\Code128;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CatalogLabelController extends Controller
{
    private const SIZES = ['60x30' => [60, 30], '100x40' => [100, 40], '120x50' => [120, 50]];

    public function print(Request $request, Branch $branch, CatalogProduct $catalogProduct): View
    {
        Gate::authorize('permission', [$branch, BranchPermission::ReadCatalog]);
        $fields = $request->validate([
            'type' => ['required', Rule::in(['sku', 'barcode', 'imei', 'price'])],
            'size' => ['required', Rule::in(array_keys(self::SIZES))],
            'copies' => ['required', 'integer', 'between:1,100'],
            'identifier' => ['nullable', 'required_if:type,barcode,imei', 'string', 'max:40'],
            'group' => ['nullable', Rule::in(['retail', 'dealer', 'partner'])],
        ]);
        $code = match ($fields['type']) {
            'barcode' => $catalogProduct->barcodes()->where('code', strtoupper($fields['identifier']))->firstOrFail()->code,
            'imei' => $catalogProduct->units()->where('imei', $fields['identifier'])->firstOrFail()->imei,
            default => $catalogProduct->sku,
        };
        $group = $fields['type'] === 'price' ? ($fields['group'] ?? 'retail') : 'retail';
        if ($group !== 'retail') {
            Gate::authorize('permission', [$branch, BranchPermission::CatalogGroupPrices]);
        }
        $price = $catalogProduct->{$group.'_satang'};
        if ($fields['type'] === 'price' && $price === null) {
            throw ValidationException::withMessages(['group' => 'ยังไม่ได้กำหนดราคากลุ่มนี้']);
        }
        [$width, $height] = self::SIZES[$fields['size']];
        $barcode = Code128::encode($code);
        if (($width - 8) / $barcode['width'] < 0.19) {
            throw ValidationException::withMessages(['size' => 'รหัสยาวเกินฉลากขนาดนี้ กรุณาเลือกฉลากกว้างขึ้น']);
        }

        return view('catalog.label-print', [
            'product' => $catalogProduct, 'branch' => $branch, 'width' => $width, 'height' => $height,
            'price' => $fields['type'] === 'price' ? $price : null, 'copies' => (int) $fields['copies'],
            'barcode' => $barcode, 'code' => $code, 'type' => $fields['type'],
        ]);
    }
}
