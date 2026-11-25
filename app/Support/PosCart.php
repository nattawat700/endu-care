<?php

namespace App\Support;

use App\Enums\BranchPermission;
use App\Models\Branch;
use App\Models\CatalogBarcode;
use App\Models\CatalogProduct;
use App\Models\Customer;
use App\Models\PosSale;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PosCart
{
    public const GROUPS = ['retail' => 'ราคาปลีก', 'dealer' => 'ราคาสมาชิก', 'partner' => 'ราคาขายส่ง'];

    public const MAX_TOTAL = 999999999;

    public static function lookup(Branch $branch, string $term, bool $canSetGroup): array
    {
        $scan = strtoupper($term);
        $pattern = self::pattern($term);
        $products = self::products($branch)
            ->with(['barcodes' => fn ($query) => $query->where('code', $scan)])
            ->where(fn ($query) => $query->whereRaw("sku LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereRaw("name LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereHas('barcodes', fn ($codes) => $codes->where('code', $scan))
                ->orWhereHas('compatibilities', fn ($models) => $models->whereRaw("device_model LIKE ? ESCAPE '!'", [$pattern])))
            ->orderByRaw('CASE WHEN sku = ? OR EXISTS (SELECT 1 FROM catalog_barcodes WHERE catalog_product_id = catalog_products.id AND code = ?) THEN 0 ELSE 1 END', [$scan, $scan])
            ->orderBy('sku')->limit(18)->get();

        return $products->map(function (CatalogProduct $product) use ($canSetGroup, $scan): array {
            $barcode = $product->barcodes->first();

            return self::presentation($product, $barcode, $canSetGroup)
                + ['exact' => $barcode !== null || strtoupper($product->sku) === $scan];
        })->all();
    }

    public static function browse(Branch $branch, bool $canSetGroup, int $after = 0): array
    {
        $pageSize = 24;
        $products = self::products($branch)->where('id', '>', $after)->orderBy('id')->limit($pageSize + 1)->get();
        $page = $products->take($pageSize);

        return [
            'items' => $page->map(fn (CatalogProduct $product): array => self::presentation($product, null, $canSetGroup))->values()->all(),
            'next' => $products->count() > $pageSize ? $page->last()->id : null,
        ];
    }

    private static function products(Branch $branch): Builder
    {
        $inSaleWarehouse = fn ($query) => $query->whereHas('warehouse', fn ($warehouses) => $warehouses->where('kind', 'sale'));

        return $branch->catalogProducts()->getQuery()
            ->withSum(['balances as sale_quantity' => $inSaleWarehouse], 'quantity')
            ->withCount(['units as sale_units' => fn ($query) => $inSaleWarehouse($query)->where('available', true)
                ->where(fn ($units) => $units->where('catalog_products.kind', '<>', 'phone')->orWhereNotNull('imei'))]);
    }

    public static function presentation(CatalogProduct $product, ?CatalogBarcode $barcode, bool $canSetGroup): array
    {
        $prices = [];
        foreach ($canSetGroup ? array_keys(self::GROUPS) : ['retail'] as $group) {
            $amount = self::unitPrice($product, $group, $barcode);
            if ($amount !== null && $amount <= self::MAX_TOTAL) {
                $prices[$group] = $amount;
            }
        }

        $available = match ($product->tracking) {
            'quantity' => (int) $product->sale_quantity,
            'serialized' => (int) $product->sale_units,
            default => null,
        };

        return [
            'sku' => $product->sku, 'name' => $product->name, 'kind' => $product->kind, 'available' => $available,
            'product_id' => $product->id, 'label' => $product->sku.' · '.$product->name,
            'tracking' => $product->tracking, 'phone' => $product->kind === 'phone',
            'barcode' => $barcode?->code, 'unit_name' => $barcode?->unit ?? $product->unit,
            'multiplier' => $barcode?->multiplier ?? 1, 'prices' => $prices,
        ];
    }

    public static function calculate(Branch $branch, array $fields): array
    {
        $products = $branch->catalogProducts()->whereIn('id', array_column($fields['items'], 'product_id'))
            ->with('barcodes')->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $lines = [];
        $units = [];
        $subtotal = 0;
        $discounts = 0;
        foreach ($fields['items'] as $index => $row) {
            $product = $products->get($row['product_id']);
            if ($product === null) {
                self::invalid("items.{$index}.product_id", 'ไม่พบสินค้านี้ในสาขา');
            }
            $barcode = isset($row['barcode']) ? $product->barcodes->firstWhere('code', $row['barcode']) : null;
            if (isset($row['barcode']) && $barcode === null) {
                self::invalid("items.{$index}.barcode", 'บาร์โค้ดเปลี่ยนแล้ว กรุณาค้นและเพิ่มรายการใหม่');
            }
            $unitPrice = self::unitPrice($product, $fields['price_group'], $barcode);
            if ($unitPrice === null || $unitPrice > self::MAX_TOTAL) {
                self::invalid("items.{$index}.product_id", $product->sku.' ไม่มีราคาสำหรับกลุ่มที่เลือก');
            }
            $unit = null;
            if ($product->tracking === 'serialized') {
                $unit = self::sellableUnits($product)->find($row['unit_id'] ?? 0);
                if ($unit === null || isset($units[$unit->id]) || (int) $row['quantity'] !== 1 || ($barcode?->multiplier ?? 1) !== 1) {
                    self::invalid("items.{$index}.unit_id", 'เลือก IMEI หรือ Serial ที่พร้อมขายและใช้เครื่องละหนึ่งรายการ');
                }
                $units[$unit->id] = true;
            } elseif ($product->kind === 'phone' || isset($row['unit_id'])) {
                self::invalid("items.{$index}.unit_id", 'โทรศัพท์ต้องตั้งเป็นสินค้ารายเครื่องและระบุ IMEI ในคลังขายก่อน');
            }

            $gross = (int) $row['quantity'] * $unitPrice;
            $discount = QuotationAmounts::hundredths($row['discount'] ?? 0);
            if ($discount > $gross) {
                self::invalid("items.{$index}.discount", 'ส่วนลดต้องไม่เกินมูลค่าของรายการ');
            }
            $subtotal += $gross;
            $discounts += $discount;
            $lines[] = [
                'catalog_product_id' => $product->id, 'catalog_version' => $product->version, 'barcode' => $barcode?->code,
                'catalog_unit_id' => $unit?->id, 'sku' => $product->sku, 'name' => $product->name, 'kind' => $product->kind,
                'unit_name' => $barcode?->unit ?? $product->unit, 'quantity' => (int) $row['quantity'], 'multiplier' => $barcode?->multiplier ?? 1,
                'unit_satang' => $unitPrice, 'discount_satang' => $discount, 'imei' => $unit?->imei, 'serial_number' => $unit?->serial_number,
            ];
        }
        $billDiscount = QuotationAmounts::hundredths($fields['document_discount'] ?? 0);
        if ($billDiscount > $subtotal - $discounts || $subtotal > self::MAX_TOTAL) {
            self::invalid('document_discount', 'ยอดรวมเกินวงเงิน หรือส่วนลดท้ายบิลเกินยอดหลังหักส่วนลดรายบรรทัด');
        }

        return [$lines, [
            'price_group' => $fields['price_group'], 'subtotal_satang' => $subtotal, 'line_discount_satang' => $discounts,
            'document_discount_satang' => $billDiscount, 'total_satang' => $subtotal - $discounts - $billDiscount,
        ]];
    }

    public static function customer(Branch $branch, array $fields): ?Customer
    {
        return match ($fields['customer_mode']) {
            'existing' => $branch->customers()->findOrFail($fields['customer_id']),
            'new' => self::createCustomer($branch, $fields['new_customer']),
            default => null,
        };
    }

    private static function createCustomer(Branch $branch, array $input): Customer
    {
        $details = CustomerData::prepare($input + ['service_channel' => 'phone', 'marketing_preference' => 'unspecified']);
        if ($branch->customers()->where('phone_key', $details['phone_key'])->exists()) {
            self::invalid('new_customer.phone', 'พบเบอร์นี้ในทะเบียน กรุณาค้นหาและเลือกลูกค้าที่มีอยู่');
        }

        return $branch->customers()->create($details + ['submission_id' => (string) Str::uuid()]);
    }

    public static function buyer(?Customer $customer): array
    {
        return $customer === null ? ['name' => 'ลูกค้าทั่วไป'] : [
            'name' => $customer->name, 'phone' => $customer->phone, 'email' => $customer->email,
            'address' => $customer->billing_address,
        ];
    }

    public static function checkPriceGroup(string $group, Branch $branch): void
    {
        if ($group !== 'retail') {
            Gate::authorize('permission', [$branch, BranchPermission::SetPosPriceGroup]);
        }
    }

    public static function salesperson(Branch $branch, User $actor, ?int $id, ?PosSale $sale = null): int
    {
        $current = $sale?->salesperson_id ?? $actor->id;
        if ($id === null || $id === $current) {
            return $current;
        }
        $eligible = $branch->memberships()->where('role', 'pc')->where('user_id', $id)
            ->whereHas('user', fn ($query) => $query->whereNull('suspended_at'))->exists();
        if (! $eligible) {
            self::invalid('salesperson_id', 'เลือกพนักงาน PC ที่ใช้งานอยู่ในสาขา');
        }

        return $id;
    }

    public static function sellableUnits(CatalogProduct $product): Builder
    {
        return $product->units()->getQuery()->where('available', true)
            ->when($product->kind === 'phone', fn ($query) => $query->whereNotNull('imei'))
            ->whereHas('warehouse', fn ($warehouses) => $warehouses->where('kind', 'sale'));
    }

    public static function unitPrice(CatalogProduct $product, string $group, ?CatalogBarcode $barcode): ?int
    {
        $price = $product->{$group.'_satang'};

        return $price === null ? null : $price * ($barcode?->multiplier ?? 1);
    }

    public static function pattern(string $term): string
    {
        return '%'.strtr($term, ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
    }

    public static function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
