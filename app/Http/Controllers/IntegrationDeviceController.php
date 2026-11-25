<?php

namespace App\Http\Controllers;

use App\Enums\BranchPermission;
use App\Models\Branch;
use App\Models\BranchPrinterProfile;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class IntegrationDeviceController extends Controller
{
    public function index(Request $request, Branch $branch): View
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManageIntegrations]);
        $query = $request->validate(['code' => ['nullable', 'string', 'max:80']]);
        $code = strtoupper($query['code'] ?? '');
        $matches = collect();
        if ($code !== '') {
            $matches = $branch->catalogProducts()
                ->where(fn ($products) => $products->where('sku', $code)
                    ->orWhereHas('barcodes', fn ($rows) => $rows->where('code', $code))
                    ->orWhereHas('units', fn ($units) => $units->where('imei', $code)->orWhere('serial_number', $code)))
                ->with([
                    'barcodes' => fn ($rows) => $rows->where('code', $code),
                    'units' => fn ($units) => $units->where(fn ($ids) => $ids->where('imei', $code)->orWhere('serial_number', $code))->with(['warehouse', 'location']),
                ])->orderBy('sku')->limit(15)->get();
        }
        $printers = $branch->printerProfiles()->orderBy('name')->get();

        return view('integrations.devices', compact('branch', 'matches', 'code', 'printers'));
    }

    public function print(Branch $branch, BranchPrinterProfile $printerProfile): View
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManageIntegrations]);
        [$width, $height] = match ($printerProfile->paper) {
            'a4' => [210, 297], 'a5' => [210, 148],
            'thermal_80' => [80, 100], 'thermal_58' => [58, 100],
        };

        return view('integrations.printer-test', compact('branch', 'printerProfile', 'width', 'height'));
    }
}
