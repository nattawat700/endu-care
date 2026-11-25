<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\CatalogUnit;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class IntegrationImportPlan
{
    public const OPENING = ['sku', 'warehouse', 'quantity', 'serial_number', 'imei'];

    /** @return array{report: array, operations: array} */
    public static function inspect(Branch $branch, string $kind, array $rows): array
    {
        $report = [];
        $operations = [];
        $keys = [];
        foreach ($rows as $number => $raw) {
            $row = array_map(fn ($value) => trim($value) === '' ? null : trim($value), $raw);
            $reference = $kind === 'customers'
                ? '••••'.substr(preg_replace('/[^0-9]/', '', $row['phone'] ?? ''), -4)
                : Str::limit((string) ($row['sku'] ?? ''), 32, '');
            try {
                $operation = match ($kind) {
                    'products' => self::product($branch, $row),
                    'customers' => self::customer($branch, $row),
                    'opening' => self::opening($branch, $row),
                };
                foreach ($operation['keys'] as $key) {
                    $keys[$key][] = $number;
                }
                $operations[$number] = $operation;
                $report[$number] = ['reference' => $reference, 'status' => $operation['status'], 'reason' => $operation['reason'], 'record' => $operation['id'] ?? null];
            } catch (ValidationException $error) {
                $report[$number] = ['reference' => $reference, 'status' => 'error', 'reason' => implode(' · ', array_merge(...array_values($error->errors())))];
            }
        }
        foreach ($keys as $numbers) {
            if (count($numbers) > 1) {
                foreach ($numbers as $number) {
                    unset($operations[$number]);
                    $report[$number]['status'] = 'error';
                    $report[$number]['reason'] = 'รหัสอ้างอิงซ้ำในไฟล์ที่แถว '.implode(', ', $numbers);
                }
            }
        }

        return compact('report', 'operations');
    }

    public static function apply(Branch $branch, string $kind, array $plan, int $actorId): array
    {
        $report = $plan['report'];
        foreach ($plan['operations'] as $number => $op) {
            if ($op['status'] === 'skip') {
                continue;
            }
            if ($kind === 'products') {
                $current = $op['id'] ? $branch->catalogProducts()->findOrFail($op['id']) : null;
                $item = CatalogProductData::save($branch, $op['data'], $current, $op['version']);
            } elseif ($kind === 'customers') {
                $item = $op['id'] ? $branch->customers()->findOrFail($op['id']) : $branch->customers()->make(['submission_id' => (string) Str::uuid()]);
                $item->fill($op['data'])->save();
            } else {
                $product = $branch->catalogProducts()->lockForUpdate()->findOrFail($op['product']);
                $warehouse = $branch->catalogWarehouses()->findOrFail($op['warehouse']);
                $item = CatalogStock::move($branch, $product, $warehouse, 'receive', $op['quantity'],
                    $op['data'] + ['submission_id' => (string) Str::uuid(), 'note' => 'นำเข้าสต๊อกตั้งต้น'], $actorId);
            }
            $report[$number]['record'] = $item->id;
        }

        return $report;
    }

    private static function product(Branch $branch, array $row): array
    {
        $data = CatalogProductData::prepare($row, canSeeGroups: true, canSeeCost: true);
        $current = $branch->catalogProducts()->where('sku', $data['product']['sku'])->first();
        CatalogProductData::checkConflicts($branch, $data, $current);
        $keys = [$data['product']['sku'], ...array_column($data['barcodes'] ?? [], 'code')];
        $same = false;
        if ($current !== null) {
            $same = $current->fill($data['product'])->isClean();
            if ($data['models'] !== null) {
                $same = $same && $current->compatibilities()->orderBy('id')->pluck('device_model')->all() === $data['models'];
            }
            if ($data['barcodes'] !== null) {
                $previous = $current->barcodes()->orderBy('id')->get(['code', 'unit', 'multiplier'])->map(fn ($barcode) => [
                    'code' => $barcode->code, 'unit' => $barcode->unit, 'multiplier' => (int) $barcode->multiplier,
                ])->all();
                $same = $same && $previous === $data['barcodes'];
            }
        }

        return ['data' => $data, 'id' => $current?->id, 'version' => $current?->version, 'keys' => $keys] + self::status($current !== null, $same);
    }

    private static function customer(Branch $branch, array $row): array
    {
        $data = CustomerData::prepare($row + ['service_channel' => 'phone', 'marketing_preference' => 'unspecified']);
        $matches = $branch->customers()->where('phone_key', $data['phone_key'])->limit(2)->get();
        if ($matches->count() > 1) {
            throw ValidationException::withMessages(['phone' => 'เบอร์โทรตรงกับลูกค้าหลายราย กรุณาแก้ทะเบียนก่อนนำเข้า']);
        }
        $current = $matches->first();
        if ($current) {
            foreach (['service_channel', 'marketing_preference'] as $key) {
                if (! array_key_exists($key, $row)) {
                    unset($data[$key]);
                }
            }
        } elseif ($branch->customers()->where('name_key', $data['name_key'])->exists()) {
            throw ValidationException::withMessages(['name' => 'พบชื่อเดิมแต่คนละเบอร์ กรุณาตรวจข้อมูลลูกค้าก่อนนำเข้า']);
        }
        $baseline = $current ? hash('sha256', json_encode($current->getAttributes(), JSON_THROW_ON_ERROR)) : null;

        return ['data' => $data, 'id' => $current?->id, 'baseline' => $baseline, 'keys' => [$data['phone_key']]]
            + self::status($current !== null, $current?->fill($data)->isClean() ?? false);
    }

    private static function opening(Branch $branch, array $row): array
    {
        $fields = Validator::make($row, [
            'sku' => ['required', 'string', 'max:32'], 'warehouse' => ['required', 'string', 'max:80'],
            'quantity' => ['required', 'integer', 'between:1,999999'], 'serial_number' => ['nullable', 'string', 'max:80'],
            'imei' => ['nullable', 'digits:15'],
        ])->validate();
        $product = $branch->catalogProducts()->where('sku', strtoupper($fields['sku']))->first();
        $warehouse = $branch->catalogWarehouses()->where('name', $fields['warehouse'])->first();
        if ($product === null || $product->tracking === 'none' || $warehouse === null) {
            throw ValidationException::withMessages(['sku' => 'ไม่พบ SKU ที่ติดตามสต๊อกหรือคลังในสาขานี้']);
        }
        $qty = (int) $fields['quantity'];
        $serial = isset($fields['serial_number']) ? strtoupper($fields['serial_number']) : null;
        $imei = $fields['imei'] ?? null;
        $base = ['product' => $product->id, 'warehouse' => $warehouse->id, 'quantity' => $qty, 'data' => ['serial_number' => $serial, 'imei' => $imei]];
        if ($product->tracking === 'serialized') {
            if ($qty !== 1 || ($serial === null && $imei === null)) {
                throw ValidationException::withMessages(['quantity' => 'สินค้ารายเครื่องต้องมี Serial หรือ IMEI และจำนวน 1']);
            }
            $keys = array_values(array_filter([$serial === null ? null : 'serial:'.$serial, $imei === null ? null : 'imei:'.$imei]));
            $matches = CatalogUnit::where('branch_id', $branch->id)
                ->where(fn ($query) => $query->when($serial !== null, fn ($ids) => $ids->orWhere('serial_number', $serial))
                    ->when($imei !== null, fn ($ids) => $ids->orWhere('imei', $imei)))->limit(2)->get();
            if ($matches->isNotEmpty()) {
                $match = $matches->first();
                if ($matches->count() === 1 && (int) $match->catalog_product_id === $product->id && (int) $match->catalog_warehouse_id === $warehouse->id
                    && $match->available && $match->serial_number === $serial && $match->imei === $imei) {
                    return $base + ['keys' => $keys, 'status' => 'skip', 'reason' => 'เครื่องนี้อยู่ในคลังแล้ว'];
                }
                throw ValidationException::withMessages(['serial_number' => 'Serial หรือ IMEI ตรงกับเครื่องอื่นหรือเครื่องที่เคลื่อนไหวแล้ว']);
            }
        } else {
            if ($serial !== null || $imei !== null) {
                throw ValidationException::withMessages(['serial_number' => 'สินค้าแบบจำนวนไม่ต้องระบุ Serial หรือ IMEI']);
            }
            $keys = [$product->id.':'.$warehouse->id];
            if ($product->movements()->where('catalog_warehouse_id', $warehouse->id)->exists()) {
                $current = $product->balances()->where('catalog_warehouse_id', $warehouse->id)->first();
                if ((int) ($current?->quantity ?? 0) === $qty) {
                    return $base + ['keys' => $keys, 'status' => 'skip', 'reason' => 'ยอดตรงกับยอดคงเหลือเดิม'];
                }
                throw ValidationException::withMessages(['quantity' => 'รายการนี้มีประวัติคลังแล้ว กรุณาใช้รายการรับเข้าตามปกติ']);
            }
        }

        return $base + ['keys' => $keys, 'status' => 'create', 'reason' => 'เตรียมบันทึกยอดตั้งต้น'];
    }

    private static function status(bool $exists, bool $same): array
    {
        return $same ? ['status' => 'skip', 'reason' => 'ข้อมูลตรงกับรายการเดิม']
            : ($exists ? ['status' => 'update', 'reason' => 'ตรงกับรหัสหรือเบอร์โทรในสาขา'] : ['status' => 'create', 'reason' => 'ยังไม่มีรายการนี้ในสาขา']);
    }
}
