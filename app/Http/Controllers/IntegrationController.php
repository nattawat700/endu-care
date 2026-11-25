<?php

namespace App\Http\Controllers;

use App\Enums\BranchPermission;
use App\Models\Branch;
use App\Models\IntegrationConnection;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class IntegrationController extends Controller
{
    public function index(Branch $branch): View
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManageIntegrations]);
        $connections = $branch->integrationConnections()->latest('id')->simplePaginate(20);

        return view('integrations.index', compact('branch', 'connections'));
    }

    public function show(Branch $branch, IntegrationConnection $integrationConnection): View
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManageIntegrations]);
        $connection = $integrationConnection;
        $links = $connection->links()->with('product:id,sku,name')->orderBy('external_sku')->simplePaginate(20, pageName: 'links');
        $events = $connection->events()->latest('id')->simplePaginate(15, pageName: 'events');
        $offers = $connection->offers()->latest('id')->simplePaginate(15, pageName: 'offers');
        $orders = $connection->orders()->latest('id')->simplePaginate(15, pageName: 'orders');
        $shipments = $connection->shipments()->with('order:id,reference')->latest('occurred_at')->simplePaginate(15, pageName: 'shipments');

        return view('integrations.connection', compact('branch', 'connection', 'links', 'events', 'offers', 'orders', 'shipments'));
    }

    public function save(Request $request, Branch $branch, ?IntegrationConnection $integrationConnection = null): RedirectResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManageIntegrations]);
        $ident = ['required', 'string', 'max:80', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._:-]*\z/'];
        $accounting = ($integrationConnection?->kind ?? $request->input('kind')) === 'accounting';
        $account = [$accounting ? 'required' : 'nullable', ...array_slice($ident, 1)];
        $fields = $request->validate([
            'kind' => [$integrationConnection === null ? 'required' : 'prohibited', Rule::in(array_keys(IntegrationConnection::KINDS))],
            'account_ref' => [$integrationConnection === null ? 'required' : 'prohibited', ...array_slice($ident, 1)],
            'name' => ['required', 'string', 'max:100'], 'enabled' => ['required', 'boolean'],
            'webhook_secret' => ['nullable', 'string', 'min:32', 'max:128', 'regex:/\A[A-Za-z0-9_\-]+\z/'],
            'connector_token' => ['nullable', 'string', 'max:500'],
            'inventory_account' => $account, 'receiving_account' => $account, 'repair_account' => $account, 'sales_cost_account' => $account,
        ]);
        $connection = DB::transaction(function () use ($branch, $integrationConnection, $fields): IntegrationConnection {
            Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $current = $integrationConnection === null
                ? $branch->integrationConnections()->make(['public_id' => (string) Str::uuid(), 'kind' => $fields['kind'], 'account_ref' => $fields['account_ref']])
                : $branch->integrationConnections()->lockForUpdate()->findOrFail($integrationConnection->id);
            if (! $current->exists && $branch->integrationConnections()->where('kind', $current->kind)->where('account_ref', $current->account_ref)->exists()) {
                throw ValidationException::withMessages(['account_ref' => 'บัญชีอ้างอิงนี้มีอยู่แล้วในสาขา']);
            }
            $credentials = $current->credentials ?? [];
            if (isset($fields['connector_token']) && in_array($current->kind, ['line', 'payment'], true)) {
                $credentials['token'] = $fields['connector_token'];
            }
            if ($current->kind === 'accounting') {
                foreach (['inventory_account', 'receiving_account', 'repair_account', 'sales_cost_account'] as $key) {
                    $credentials[$key] = $fields[$key];
                }
                foreach (['receiving_account', 'repair_account', 'sales_cost_account'] as $key) {
                    if ($credentials[$key] === $credentials['inventory_account']) {
                        throw ValidationException::withMessages([$key => 'รหัสคู่บัญชีต้องต่างจากรหัสบัญชีสินค้าคงคลัง']);
                    }
                }
            }
            $secret = $fields['webhook_secret'] ?? $current->secret;
            if ($fields['enabled'] && in_array($current->kind, ['endu', 'store', 'shipping'], true) && $secret === null) {
                throw ValidationException::withMessages(['webhook_secret' => 'กำหนดกุญแจ webhook อย่างน้อย 32 ตัวก่อนเปิดใช้งาน']);
            }
            $current->fill(['name' => $fields['name'], 'enabled' => $fields['enabled'], 'secret' => $secret,
                'credentials' => $credentials ?: null])->save();

            return $current;
        }, 3);

        return redirect()->route('integrations.connections.show', [$branch, $connection])->with('status', 'บันทึกการเชื่อมต่อแล้ว');
    }

    public function link(Request $request, Branch $branch, IntegrationConnection $integrationConnection): RedirectResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManageIntegrations]);
        abort_unless(in_array($integrationConnection->kind, ['endu', 'store'], true), 404);
        $fields = $request->validate([
            'external_sku' => ['required', 'string', 'max:80', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._:-]*\z/'],
            'local_sku' => ['required', 'string', 'max:32'],
            'external_model' => ['nullable', 'string', 'max:100', 'required_with:local_model'],
            'local_model' => ['nullable', 'string', 'max:100', 'required_with:external_model'],
        ]);
        DB::transaction(function () use ($branch, $integrationConnection, $fields): void {
            Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $product = $branch->catalogProducts()->where('sku', strtoupper($fields['local_sku']))->first();
            if ($product === null) {
                throw ValidationException::withMessages(['local_sku' => 'ไม่พบ SKU ในสาขา']);
            }
            if (isset($fields['local_model']) && ! $product->compatibilities()->where('device_model', $fields['local_model'])->exists()) {
                throw ValidationException::withMessages(['local_model' => 'รุ่นนี้ยังไม่ได้ตั้งว่าใช้กับสินค้าในทะเบียน']);
            }
            $integrationConnection->links()->updateOrCreate(['external_sku' => strtoupper($fields['external_sku'])], [
                'catalog_product_id' => $product->id, 'external_model' => $fields['external_model'] ?? null,
                'local_model' => $fields['local_model'] ?? null,
            ]);
        }, 3);

        return back()->with('status', 'บันทึกการจับคู่ SKU แล้ว');
    }
}
