<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\CatalogLocation;
use App\Models\CatalogMovement;
use App\Models\CatalogProduct;
use App\Models\CatalogWarehouse;
use App\Models\PosSale;
use Illuminate\Support\Str;

class PosStock
{
    /** The branch is locked by the caller throughout checkout or reversal. */
    public static function commit(Branch $branch, PosSale $sale, int $actor): void
    {
        $lines = $sale->lines()->get();
        $products = $branch->catalogProducts()->whereIn('id', $lines->pluck('catalog_product_id'))
            ->with('barcodes')->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        foreach ($lines as $line) {
            $product = $products->get($line->catalog_product_id);
            $barcode = $line->barcode === null ? null : $product->barcodes->firstWhere('code', $line->barcode);
            if ($product->version !== $line->catalog_version || ($line->barcode !== null && $barcode === null)
                || PosCart::unitPrice($product, $sale->price_group, $barcode) !== $line->unit_satang) {
                PosCart::invalid('items', $line->sku.' มีข้อมูลหรือราคาเปลี่ยน กรุณาตรวจสอบและบันทึกตะกร้าใหม่ก่อนรับเงิน');
            }
            if ($product->tracking === 'serialized') {
                $unit = PosCart::sellableUnits($product)->with(['warehouse', 'location'])->lockForUpdate()->find($line->catalog_unit_id);
                if ($unit === null || $unit->imei !== $line->imei || $unit->serial_number !== $line->serial_number) {
                    PosCart::invalid('items', $line->sku.' ไม่มี IMEI หรือ Serial นี้ในคลังขายแล้ว');
                }
                self::move($branch, $sale, $product, $unit->warehouse, 1, $actor, $unit->location, [
                    'serial_number' => $unit->serial_number, 'imei' => $unit->imei,
                ]);
            } elseif ($product->tracking === 'quantity') {
                self::quantity($branch, $sale, $product, $line->quantity * $line->multiplier, $actor);
            }
        }
    }

    public static function reverse(Branch $branch, PosSale $sale, int $actor, string $reason): void
    {
        $movements = $sale->movements()->where('kind', 'sale')->orderByDesc('id')->get();
        $products = $branch->catalogProducts()->whereIn('id', $movements->pluck('catalog_product_id'))
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $warehouses = $branch->catalogWarehouses()->whereIn('id', $movements->pluck('catalog_warehouse_id'))->get()->keyBy('id');
        $locations = CatalogLocation::whereIn('id', $movements->pluck('catalog_location_id')->filter())->get()->keyBy('id');
        foreach ($movements as $movement) {
            self::move($branch, $sale, $products->get($movement->catalog_product_id), $warehouses->get($movement->catalog_warehouse_id),
                $movement->quantity, $actor, $locations->get($movement->catalog_location_id), ['note' => $reason], $movement);
        }
    }

    private static function quantity(Branch $branch, PosSale $sale, CatalogProduct $product, int $required, int $actor): void
    {
        $balances = $product->balances()->where('quantity', '>', 0)
            ->whereHas('warehouse', fn ($warehouses) => $warehouses->where('kind', 'sale'))
            ->with(['warehouse', 'locations.location'])->orderBy('catalog_warehouse_id')->get();
        if ($balances->sum('quantity') < $required) {
            PosCart::invalid('items', $product->sku.' มีสินค้าในคลังขายไม่พอ');
        }
        foreach ($balances as $balance) {
            $unallocated = $balance->quantity - $balance->locations->sum('quantity');
            $portions = [[null, $unallocated]];
            foreach ($balance->locations->sortBy('id') as $bin) {
                $portions[] = [$bin->location, $bin->quantity];
            }
            foreach ($portions as [$location, $available]) {
                $take = min($required, $available);
                if ($take > 0) {
                    self::move($branch, $sale, $product, $balance->warehouse, $take, $actor, $location);
                    $required -= $take;
                }
                if ($required === 0) {
                    return;
                }
            }
        }
        PosCart::invalid('items', $product->sku.' มีสินค้าในคลังขายไม่พอ');
    }

    private static function move(Branch $branch, PosSale $sale, CatalogProduct $product, CatalogWarehouse $warehouse, int $quantity, int $actor,
        ?CatalogLocation $location, array $details = [], ?CatalogMovement $original = null): void
    {
        CatalogStock::move($branch, $product, $warehouse, $original ? 'sale_return' : 'sale', $quantity,
            $details + ['submission_id' => (string) Str::uuid(), 'note' => $sale->reference()], $actor, issue: $original, location: $location, posSaleId: $sale->id);
    }
}
