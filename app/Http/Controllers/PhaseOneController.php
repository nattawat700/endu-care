<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class PhaseOneController extends Controller
{
    public function __invoke(Request $request): View
    {
        $catalog = collect(File::json(resource_path('data/phase-one.json')));
        $module = $request->query('module');
        $branchId = $request->query('branch');
        $activeBranch = null;
        if ($branchId !== null) {
            abort_unless(is_string($branchId) && ctype_digit($branchId), 404);
            $activeBranch = $request->user()->activeBranchMemberships()->where('branch_id', $branchId)
                ->with('branch')->firstOrFail()->branch;
        }
        $query = $request->query('q');
        $query = is_string($query) ? Str::limit(trim($query), 120, '') : '';

        $groups = $catalog
            ->when(is_string($module) && $catalog->has($module), fn ($groups) => $groups->only($module))
            ->when($query !== '', fn ($groups) => $groups
                ->map(fn ($features) => array_filter($features, fn ($feature) => Str::contains(
                    implode(' ', [$feature['id'], $feature['module'], $feature['title'], $feature['description']]),
                    $query,
                    ignoreCase: true,
                )))
                ->filter());

        return view('phase-one', [
            'groups' => $groups,
            'modules' => $catalog->map(fn ($features) => $features[0]['module']),
            'module' => $module,
            'query' => $query,
            'featureCount' => $catalog->sum(fn ($features) => count($features)),
            'activeBranch' => $activeBranch,
        ]);
    }
}
