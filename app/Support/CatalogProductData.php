<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\CatalogBarcode;
use App\Models\CatalogProduct;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CatalogProductData
{
    public const PRICES = ['retail' => 'ราคาปลีก', 'dealer' => 'ราคาช่าง', 'partner' => 'ราคาพาร์ตเนอร์', 'cost' => 'ต้นทุน'];

    public const COLUMNS = ['sku', 'name', 'kind', 'tracking', 'category', 'brand', 'unit', 'models', 'barcodes', 'retail', 'dealer', 'partner', 'cost'];

    /** @return array{product: array, models: ?list<string>, barcodes: ?list<array>} */
    public static function prepare(array $input, bool $canSeeGroups, bool $canSeeCost): array
    {
        foreach ($input as $key => $value) {
            if (is_string($value)) {
                $input[$key] = trim($value) === '' ? null : trim($value);
            }
        }
        if (is_string($input['sku'] ?? null)) {
            $input['sku'] = strtoupper($input['sku']);
        }
        $fields = Validator::make($input, [
            'sku' => ['required', 'regex:/\A[A-Z0-9][A-Z0-9._-]{0,31}\z/'],
            'name' => ['required', 'string', 'max:140'],
            'kind' => ['required', Rule::in(array_keys(CatalogProduct::KINDS))],
            'tracking' => ['required', Rule::in(array_keys(CatalogProduct::TRACKING))],
            'category' => ['nullable', 'string', 'max:80'],
            'brand' => ['nullable', 'string', 'max:80'],
            'unit' => ['required', 'string', 'max:30'],
            'models' => ['nullable', 'string', 'max:4000'],
            'barcodes' => ['nullable', 'string', 'max:4000'],
            'retail' => ['required', QuotationAmounts::MONEY],
            'dealer' => [$canSeeGroups ? 'nullable' : 'prohibited', QuotationAmounts::MONEY],
            'partner' => [$canSeeGroups ? 'nullable' : 'prohibited', QuotationAmounts::MONEY],
            'cost' => [$canSeeCost ? 'nullable' : 'prohibited', QuotationAmounts::MONEY],
        ], ['sku.regex' => 'SKU ใช้ A–Z ตัวเลข จุด ขีดกลาง หรือขีดล่าง สูงสุด 32 ตัว'])->validate();

        if (in_array($fields['kind'], ['labor', 'service'], true) && $fields['tracking'] !== 'none') {
            throw ValidationException::withMessages(['tracking' => 'ค่าแรงและบริการต้องเลือกไม่มีสต๊อก']);
        }

        $models = self::lines($fields['models'] ?? null);
        if (count($models) > 30 || collect($models)->contains(fn (string $model) => Str::length($model) > 100)
            || count(array_unique(array_map(Str::lower(...), $models))) !== count($models)) {
            throw ValidationException::withMessages(['models' => 'ระบุรุ่นได้สูงสุด 30 รุ่น รุ่นละไม่เกิน 100 ตัวอักษรและต้องไม่ซ้ำกัน']);
        }

        $barcodes = [];
        foreach (self::lines($fields['barcodes'] ?? null) as $line) {
            [$code, $unit, $count] = array_pad(array_map('trim', explode('|', $line, 3)), 3, '');
            $code = strtoupper($code);
            if (! preg_match('/\A[A-Z0-9][A-Z0-9._-]{0,39}\z/', $code) || $unit === '' || Str::length($unit) > 30
                || ! preg_match('/\A[1-9][0-9]{0,3}\z/', $count) || isset($barcodes[$code])
                || $code === $fields['sku'] || count($barcodes) >= 20
                || ($fields['tracking'] === 'serialized' && $count !== '1')) {
                throw ValidationException::withMessages(['barcodes' => 'บาร์โค้ดใช้รูปแบบ รหัส|หน่วย|จำนวนต่อหน่วย สูงสุด 20 รหัสและต้องไม่ซ้ำ สินค้ารายเครื่องใช้จำนวน 1 ต่อรหัส']);
            }
            $barcodes[$code] = ['code' => $code, 'unit' => $unit, 'multiplier' => (int) $count];
        }

        $product = array_intersect_key($fields, array_flip(['sku', 'name', 'kind', 'tracking', 'category', 'brand', 'unit']));
        foreach (array_keys(self::PRICES) as $key) {
            if (array_key_exists($key, $fields) && ($key !== 'cost' || $canSeeCost) && (! in_array($key, ['dealer', 'partner'], true) || $canSeeGroups)) {
                $product[$key.'_satang'] = $fields[$key] === null ? null : QuotationAmounts::hundredths($fields[$key]);
            }
        }

        return [
            'product' => $product,
            'models' => array_key_exists('models', $input) ? $models : null,
            'barcodes' => array_key_exists('barcodes', $input) ? array_values($barcodes) : null,
        ];
    }

    /** Called within a transaction that locks the branch. */
    public static function save(Branch $branch, array $data, ?CatalogProduct $current, ?int $version): CatalogProduct
    {
        $product = $current === null ? $branch->catalogProducts()->make() : $branch->catalogProducts()->lockForUpdate()->findOrFail($current->id);
        if ($current !== null && $product->version !== $version) {
            throw ValidationException::withMessages(['version' => 'ข้อมูลสินค้าถูกเปลี่ยน กรุณาโหลดข้อมูลล่าสุดแล้วลองอีกครั้ง']);
        }

        self::checkConflicts($branch, $data, $product->exists ? $product : null);
        $product->fill($data['product'] + ['version' => $product->exists ? $product->version + 1 : 1])->save();
        if ($data['barcodes'] !== null) {
            $product->barcodes()->delete();
            $product->barcodes()->createMany(array_map(fn ($barcode) => ['branch_id' => $branch->id] + $barcode, $data['barcodes']));
        }
        if ($data['models'] !== null) {
            $product->compatibilities()->delete();
            $product->compatibilities()->createMany(array_map(fn ($model) => ['device_model' => $model], $data['models']));
        }

        return $product;
    }

    public static function checkConflicts(Branch $branch, array $data, ?CatalogProduct $product): void
    {
        $fields = $data['product'];
        if ($product !== null && ($product->tracking !== $fields['tracking'] || $product->unit !== $fields['unit']) && $product->movements()->exists()) {
            $field = $product->tracking !== $fields['tracking'] ? 'tracking' : 'unit';
            throw ValidationException::withMessages([$field => 'รายการนี้มีประวัติคลังแล้ว จึงเปลี่ยนวิธีนับสต๊อกหรือหน่วยฐานไม่ได้']);
        }

        $codes = array_column($data['barcodes'] ?? [], 'code');
        if ($data['barcodes'] === null && $product !== null
            && $product->barcodes()->where(fn ($query) => $query->where('code', $fields['sku'])
                ->when($fields['tracking'] === 'serialized', fn ($query) => $query->orWhere('multiplier', '!=', 1)))->exists()) {
            throw ValidationException::withMessages(['barcodes' => 'บาร์โค้ดเดิมซ้ำกับ SKU หรือใช้หน่วยมากกว่า 1 กับสินค้ารายเครื่อง']);
        }
        if ($branch->catalogProducts()->when($product !== null, fn ($query) => $query->whereKeyNot($product->id))
            ->whereIn('sku', [$fields['sku'], ...$codes])->exists()
            || CatalogBarcode::where('branch_id', $branch->id)->when($product !== null, fn ($query) => $query->where('catalog_product_id', '!=', $product->id))
                ->whereIn('code', [$fields['sku'], ...$codes])->exists()) {
            throw ValidationException::withMessages(['sku' => 'SKU หรือบาร์โค้ดซ้ำกับสินค้าอื่นในสาขานี้']);
        }

    }

    private static function lines(?string $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/u', $value ?? '') ?: []), fn ($line) => $line !== ''));
    }
}
