<?php

namespace App\Support;

use App\Models\IntegrationConnection;
use App\Models\IntegrationOrder;
use App\Models\IntegrationShipment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class IntegrationEvents
{
    public const REFERENCE = 'regex:/\A[A-Za-z0-9][A-Za-z0-9._:-]*\z/';

    public const TOPICS = ['endu' => 'catalog.product', 'store' => 'order.upsert', 'shipping' => 'shipment.updated'];

    public static function process(IntegrationConnection $connection, array $payload): string
    {
        return match ($connection->kind) {
            'endu' => self::offer($connection, $payload),
            'store' => self::order($connection, $payload),
            'shipping' => self::shipment($connection, $payload),
        };
    }

    private static function offer(IntegrationConnection $connection, array $payload): string
    {
        $fields = Validator::make($payload, [
            'external_sku' => ['required', 'string', 'max:80', self::REFERENCE],
            'name' => ['required', 'string', 'max:140'],
            'device_model' => ['nullable', 'string', 'max:100'],
            'revision' => ['required', 'integer', 'between:1,2147483647'],
        ])->validate();
        $sku = strtoupper($fields['external_sku']);
        $offer = $connection->offers()->firstOrNew(['external_sku' => $sku]);
        if ($offer->exists && $offer->revision >= (int) $fields['revision']) {
            return 'stale_revision';
        }
        $offer->fill(['name' => $fields['name'], 'device_model' => $fields['device_model'] ?? null, 'revision' => $fields['revision']])->save();

        return 'applied';
    }

    private static function order(IntegrationConnection $connection, array $payload): string
    {
        $fields = Validator::make($payload, [
            'reference' => ['required', 'string', 'max:80', self::REFERENCE],
            'revision' => ['required', 'integer', 'between:1,2147483647'],
            'items' => ['required', 'array', 'list', 'min:1', 'max:30'],
            'items.*' => ['required', 'array:external_sku,quantity,imei,serial_number'],
            'items.*.external_sku' => ['required', 'string', 'max:80', self::REFERENCE],
            'items.*.quantity' => ['required', 'integer', 'between:1,999999'],
            'items.*.imei' => ['nullable', 'digits:15'],
            'items.*.serial_number' => ['nullable', 'string', 'max:80'],
        ])->validate();
        $order = $connection->orders()->firstOrNew(['reference' => strtoupper($fields['reference'])]);
        if ($order->exists && $order->status !== 'pending') {
            return 'confirmed_order';
        }
        if ($order->exists && $order->revision >= (int) $fields['revision']) {
            return 'stale_revision';
        }
        $items = array_map(fn ($item) => [
            'external_sku' => strtoupper($item['external_sku']), 'quantity' => (int) $item['quantity'],
            'imei' => $item['imei'] ?? null, 'serial_number' => isset($item['serial_number']) ? strtoupper($item['serial_number']) : null,
        ], $fields['items']);
        $order->fill(['revision' => $fields['revision'], 'items' => $items])->save();

        return 'applied';
    }

    private static function shipment(IntegrationConnection $connection, array $payload): string
    {
        $fields = Validator::make($payload, [
            'tracking_number' => ['required', 'string', 'max:80', self::REFERENCE],
            'status' => ['required', Rule::in(array_keys(IntegrationShipment::STATUSES))],
            'occurred_at' => ['required', 'date_format:Y-m-d\TH:i:s\Z'],
            'store_account' => ['nullable', 'required_with:order_ref', 'string', 'max:80', self::REFERENCE],
            'order_ref' => ['nullable', 'required_with:store_account', 'string', 'max:80', self::REFERENCE],
        ])->validate();
        $time = CarbonImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $fields['occurred_at'], 'UTC');
        if ($time->gt(now()->addMinutes(5))) {
            return 'future_time';
        }
        $shipment = $connection->shipments()->firstOrNew(['tracking_number' => strtoupper($fields['tracking_number'])]);
        $orderId = $shipment->integration_order_id;
        if (isset($fields['order_ref'])) {
            $order = IntegrationOrder::where('reference', strtoupper($fields['order_ref']))->where('status', 'confirmed')
                ->whereHas('connection', fn ($source) => $source->where('branch_id', $connection->branch_id)
                    ->where('kind', 'store')->where('account_ref', $fields['store_account']))->first();
            if ($order === null || ($orderId !== null && (int) $orderId !== $order->id)) {
                return 'order_not_found';
            }
            $orderId = $order->id;
        }
        if ($shipment->exists && ($shipment->occurred_at->gte($time)
            || ($shipment->status === 'delivered' && $fields['status'] !== 'returned')
            || $shipment->status === 'returned')) {
            return 'stale_status';
        }
        $shipment->fill(['status' => $fields['status'], 'occurred_at' => $time, 'integration_order_id' => $orderId])->save();

        return 'applied';
    }
}
