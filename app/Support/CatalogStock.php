<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\CatalogBalance;
use App\Models\CatalogLocation;
use App\Models\CatalogMovement;
use App\Models\CatalogProduct;
use App\Models\CatalogUnit;
use App\Models\CatalogWarehouse;
use Illuminate\Validation\ValidationException;

class CatalogStock
{
    /** Call inside a transaction with the branch and product rows locked. */
    public static function move(Branch $branch, CatalogProduct $product, CatalogWarehouse $warehouse, string $kind, int $quantity, array $fields, int $actorId, ?int $repairId = null, ?CatalogMovement $issue = null, ?int $orderId = null, ?CatalogLocation $location = null, ?int $posSaleId = null): CatalogMovement
    {
        $outgoing = in_array($kind, ['issue', 'sale'], true);
        if ($outgoing && $warehouse->kind === 'inspection') {
            throw ValidationException::withMessages(['warehouse_id' => 'คลังพักตรวจยังไม่พร้อมขายหรือเบิกใช้']);
        }
        $receiptCost = isset($fields['receipt_cost']) ? QuotationAmounts::hundredths($fields['receipt_cost']) : $product->cost_satang;
        $returned = $issue === null ? 0 : (int) $issue->returns()->sum('quantity');
        if ($issue !== null && $returned + $quantity > $issue->quantity) {
            throw ValidationException::withMessages(['quantity' => 'จำนวนคืนเกินยอดคงค้างในใบเบิก']);
        }
        $returnCost = $issue?->costForQuantity($returned + $quantity);
        if ($returnCost !== null) {
            $returnCost -= $issue->costForQuantity($returned);
        }

        $unit = null;
        if ($product->tracking === 'serialized') {
            $unit = self::serialized($branch, $product, $warehouse, $kind, $fields, $quantity, $issue, $location, $receiptCost);
            $location = $unit->location;
            $cost = match ($kind) {
                'receive' => $receiptCost,
                'return', 'sale_return' => $returnCost,
                default => $unit->cost_satang ?? $product->movements()->where('catalog_unit_id', $unit->id)
                    ->where('kind', 'receive')->value('unit_cost_satang'),
            };
        } else {
            $balance = $product->balances()->firstOrNew(['branch_id' => $branch->id, 'catalog_warehouse_id' => $warehouse->id]);
            $previous = (int) $balance->quantity;
            $remaining = $previous + ($outgoing ? -$quantity : $quantity);
            if ($remaining < 0 || $remaining > 1000000000) {
                throw ValidationException::withMessages(['quantity' => 'จำนวนเกินยอดคงเหลือหรือขีดจำกัดของคลัง']);
            }
            $value = $previous === 0 ? 0 : $balance->stock_value_satang;
            $cost = match ($kind) {
                'receive' => $receiptCost === null ? null : $receiptCost * $quantity,
                'return', 'sale_return' => $returnCost,
                default => $value === null ? null : CatalogMovement::proportionalCost((int) $value, $previous, $quantity),
            };
            $balance->quantity = $remaining;
            $balance->stock_value_satang = match (true) {
                $remaining === 0 => 0,
                $value === null || $cost === null => null,
                default => (int) $value + ($outgoing ? -$cost : $cost),
            };
            $balance->save();
            self::updateLocations($balance, $previous, $kind, $quantity, $location);
        }

        return $branch->catalogMovements()->create([
            'catalog_product_id' => $product->id, 'catalog_warehouse_id' => $warehouse->id, 'repair_job_id' => $repairId,
            'catalog_unit_id' => $unit?->id, 'issue_id' => $issue?->id, 'integration_order_id' => $orderId, 'created_by' => $actorId, 'pos_sale_id' => $posSaleId,
            'submission_id' => $fields['submission_id'], 'kind' => $kind, 'quantity' => $quantity, 'sku' => $issue?->sku ?? $product->sku,
            'product_name' => $issue?->product_name ?? $product->name, 'unit_name' => $issue?->unit_name ?? $product->unit,
            'warehouse_name' => $issue?->warehouse_name ?? $warehouse->name, 'catalog_location_id' => $location?->id,
            'location_label' => $issue?->location_label ?? $location?->label(), 'total_cost_satang' => $cost,
            'unit_cost_satang' => $cost === null ? null : intdiv((int) $cost, $quantity), 'note' => $fields['note'] ?? null,
        ]);
    }

    private static function updateLocations(CatalogBalance $balance, int $previous, string $kind, int $quantity, ?CatalogLocation $location): void
    {
        if ($location !== null) {
            $row = $balance->locations()->firstOrNew(['catalog_location_id' => $location->id]);
            $remaining = (int) $row->quantity + (in_array($kind, ['issue', 'sale'], true) ? -$quantity : $quantity);
            if ($remaining < 0) {
                throw ValidationException::withMessages(['quantity' => 'ยอดคงเหลือที่ตำแหน่งเก็บนี้ไม่พอ']);
            }
            $row->quantity = $remaining;
            $row->save();

            return;
        }
        if (! in_array($kind, ['issue', 'sale'], true)) {
            return;
        }

        $allocated = $balance->locations()->where('quantity', '>', 0)->orderBy('id')->get();
        $fromLocations = $quantity - ($previous - $allocated->sum('quantity'));
        if ($fromLocations <= 0) {
            return;
        }
        if ($kind === 'issue') {
            throw ValidationException::withMessages(['location_id' => 'ยอดไม่ระบุตำแหน่งไม่พอ กรุณาเลือกตำแหน่งที่เก็บสินค้า']);
        }
        foreach ($allocated as $row) {
            $deduct = min($fromLocations, $row->quantity);
            $row->update(['quantity' => $row->quantity - $deduct]);
            $fromLocations -= $deduct;
            if ($fromLocations === 0) {
                break;
            }
        }
    }

    private static function serialized(Branch $branch, CatalogProduct $product, CatalogWarehouse $warehouse, string $kind, array $fields, int $quantity, ?CatalogMovement $issue, ?CatalogLocation $location, ?int $receiptCost): CatalogUnit
    {
        if ($quantity !== 1) {
            throw ValidationException::withMessages(['quantity' => 'สินค้ารายเครื่องต้องบันทึกทีละ 1 เครื่อง']);
        }
        if ($issue !== null) {
            $unit = $issue->stockUnit()->lockForUpdate()->firstOrFail();
            abort_if($unit->available, 409);
            $unit->update(['available' => true]);

            return $unit;
        }

        $serial = isset($fields['serial_number']) ? strtoupper($fields['serial_number']) : null;
        $imei = $fields['imei'] ?? null;
        if ($serial === null && $imei === null) {
            throw ValidationException::withMessages(['serial_number' => 'ระบุ Serial No. หรือ IMEI']);
        }
        if ($kind === 'receive') {
            $duplicate = CatalogUnit::where('branch_id', $branch->id)
                ->where(fn ($query) => $query->when($serial !== null, fn ($ids) => $ids->orWhere('serial_number', $serial))
                    ->when($imei !== null, fn ($ids) => $ids->orWhere('imei', $imei)))->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['serial_number' => 'Serial No. หรือ IMEI นี้มีในทะเบียนคลังแล้ว']);
            }

            return $product->units()->create(['branch_id' => $branch->id, 'catalog_warehouse_id' => $warehouse->id,
                'serial_number' => $serial, 'imei' => $imei, 'catalog_location_id' => $location?->id, 'available' => true, 'cost_satang' => $receiptCost]);
        }

        $unit = $product->units()->where('catalog_warehouse_id', $warehouse->id)->where('available', true)
            ->when($location, fn ($query) => $query->where('catalog_location_id', $location->id))
            ->when($serial !== null, fn ($query) => $query->where('serial_number', $serial))
            ->when($imei !== null, fn ($query) => $query->where('imei', $imei))->lockForUpdate()->first();
        if ($unit === null) {
            throw ValidationException::withMessages(['serial_number' => 'ไม่พบเครื่องนี้ในคลังหรือตำแหน่งที่เลือก หรือเครื่องถูกเบิกแล้ว']);
        }
        $unit->update(['available' => false]);

        return $unit;
    }
}
