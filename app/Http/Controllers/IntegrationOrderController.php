<?php

namespace App\Http\Controllers;

use App\Enums\BranchPermission;
use App\Models\Branch;
use App\Models\IntegrationConnection;
use App\Models\IntegrationOrder;
use App\Models\IntegrationShipment;
use App\Support\CatalogStock;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class IntegrationOrderController extends Controller
{
    public function show(Branch $branch, IntegrationConnection $integrationConnection, IntegrationOrder $order): View
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManageIntegrations]);
        $connection = $integrationConnection;
        $mappings = $connection->links()->whereIn('external_sku', array_column($order->items, 'external_sku'))
            ->with('product:id,sku,name,tracking,version')->get()->keyBy('external_sku');
        $warehouses = $branch->catalogWarehouses()->where('kind', 'sale')->orderBy('name')->get();
        $movements = $branch->catalogMovements()->where('integration_order_id', $order->id)->orderBy('id')->get();
        $shipments = IntegrationShipment::where('integration_order_id', $order->id)->latest('occurred_at')->simplePaginate(15);
        $mappingVersion = $this->mappingVersion($mappings);

        return view('integrations.order', compact('branch', 'connection', 'order', 'mappings', 'warehouses', 'movements', 'shipments', 'mappingVersion'));
    }

    public function confirm(Request $request, Branch $branch, IntegrationConnection $integrationConnection, IntegrationOrder $order): RedirectResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManageIntegrations]);
        Gate::authorize('permission', [$branch, BranchPermission::ManageCatalogStock]);
        $fields = $request->validate([
            'warehouse_id' => ['required', 'integer', 'min:1'],
            'revision' => ['required', 'integer', 'min:1'],
            'mapping_version' => ['required', 'regex:/\A[a-f0-9]{64}\z/'],
        ]);
        DB::transaction(function () use ($request, $branch, $integrationConnection, $order, $fields): void {
            Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $connection = $branch->integrationConnections()->lockForUpdate()->findOrFail($integrationConnection->id);
            $order = $connection->orders()->lockForUpdate()->findOrFail($order->id);
            if ($order->status === 'confirmed') {
                return;
            }
            if (! $connection->enabled || $connection->kind !== 'store' || $order->revision !== (int) $fields['revision']) {
                throw ValidationException::withMessages(['revision' => 'การเชื่อมต่อหรือข้อมูลออเดอร์เปลี่ยน กรุณาเปิดข้อมูลล่าสุด']);
            }
            $warehouse = $branch->catalogWarehouses()->findOrFail($fields['warehouse_id']);
            $links = $connection->links()->whereIn('external_sku', array_column($order->items, 'external_sku'))
                ->with('product:id,version')->get()->keyBy('external_sku');
            if ($this->mappingVersion($links) !== $fields['mapping_version']) {
                throw ValidationException::withMessages(['revision' => 'การจับคู่หรือทะเบียนสินค้าเปลี่ยน กรุณาตรวจรายการล่าสุด']);
            }
            foreach ($order->items as $line) {
                $link = $links->get($line['external_sku']);
                $product = $link === null ? null : $branch->catalogProducts()->whereKey($link->catalog_product_id)->lockForUpdate()->first();
                if ($product === null) {
                    throw ValidationException::withMessages(['order' => 'ยังไม่ได้จับคู่ SKU '.$line['external_sku']]);
                }
                if ($product->tracking !== 'none') {
                    CatalogStock::move($branch, $product, $warehouse, 'sale', $line['quantity'], $line + [
                        'submission_id' => (string) Str::uuid(),
                        'note' => 'ออเดอร์ออนไลน์ '.$order->reference,
                    ], $request->user()->id, orderId: $order->id);
                }
            }
            $order->update(['status' => 'confirmed', 'confirmed_by' => $request->user()->id, 'confirmed_at' => now()]);
        }, 3);

        return redirect()->route('integrations.orders.show', [$branch, $integrationConnection, $order])->with('status', 'ยืนยันออเดอร์และบันทึกสต๊อกแล้ว');
    }

    private function mappingVersion(Collection $links): string
    {
        $pairs = $links->sortKeys()->values()->map(fn ($link) => [$link->external_sku, $link->catalog_product_id, $link->product?->version]);

        return hash('sha256', $pairs->toJson());
    }
}
