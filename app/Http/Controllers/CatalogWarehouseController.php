<?php

namespace App\Http\Controllers;

use App\Enums\BranchPermission;
use App\Models\Branch;
use App\Models\CatalogLocation;
use App\Models\CatalogWarehouse;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CatalogWarehouseController extends Controller
{
    public function index(Branch $branch): View
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManageBranchSettings]);
        $warehouses = $branch->catalogWarehouses()->with(['locations' => fn ($query) => $query->orderBy('code')])
            ->orderBy('name')->simplePaginate(15);

        return view('organizations.warehouses', compact('branch', 'warehouses'));
    }

    public function warehouse(Request $request, Branch $branch, ?CatalogWarehouse $catalogWarehouse = null): RedirectResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManageBranchSettings]);
        DB::transaction(function () use ($request, $branch, $catalogWarehouse): void {
            Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $name = Rule::unique('catalog_warehouses')->where('branch_id', $branch->id);
            $current = $catalogWarehouse === null ? $branch->catalogWarehouses()->make() : $catalogWarehouse;
            if ($current->exists) {
                $name->ignore($current);
            }
            $fields = $request->validate([
                'name' => ['required', 'string', 'max:80', $name],
                'kind' => ['required', Rule::in(array_keys(CatalogWarehouse::KINDS))],
            ]);
            $current->fill($fields)->save();
        }, 3);

        return back()->with('status', 'บันทึกคลังของสาขาแล้ว');
    }

    public function location(Request $request, Branch $branch, CatalogWarehouse $catalogWarehouse, ?CatalogLocation $location = null): RedirectResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManageBranchSettings]);
        if (is_string($request->input('code'))) {
            $request->merge(['code' => strtoupper($request->input('code'))]);
        }
        DB::transaction(function () use ($request, $branch, $catalogWarehouse, $location): void {
            Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $code = Rule::unique('catalog_locations')->where('catalog_warehouse_id', $catalogWarehouse->id);
            $current = $location ?? $catalogWarehouse->locations()->make();
            if ($current->exists) {
                $code->ignore($current);
            }
            $fields = $request->validate([
                'code' => ['required', 'regex:/\A[A-Z0-9][A-Z0-9._-]{0,31}\z/', $code],
                'name' => ['required', 'string', 'max:80'],
            ]);
            $current->fill($fields)->save();
        }, 3);

        return back()->with('status', 'บันทึกตำแหน่งเก็บแล้ว');
    }
}
