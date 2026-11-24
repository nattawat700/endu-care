<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AuditController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', AuditEvent::class);
        $filters = $request->validate([
            'branch' => ['nullable', 'integer', 'min:1'],
            'actor' => ['nullable', 'string', 'max:100'],
            'reference' => ['nullable', 'string', 'max:100'],
            'subject' => ['nullable', Rule::in(array_keys(AuditEvent::SUBJECTS))],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
        ]);
        $user = $request->user();
        $branches = $user->activeBranchMemberships()->whereIn('role', AuditEvent::branchRoles())
            ->with('branch.organization')->orderBy('branch_id')->get()->pluck('branch');
        if (isset($filters['branch'])) {
            abort_unless($branches->contains('id', (int) $filters['branch']), 404);
        }

        $events = AuditEvent::visibleTo($user)
            ->with(['branch:id,name', 'organization:id,name'])
            ->when($filters['branch'] ?? null, fn ($query, $branch) => $query->where('branch_id', $branch))
            ->when($filters['actor'] ?? null, fn ($query, $actor) => $query->whereLike('actor_name', '%'.$actor.'%'))
            ->when($filters['reference'] ?? null, fn ($query, $reference) => $query->where('subject_reference', Str::upper($reference)))
            ->when($filters['subject'] ?? null, fn ($query, $subject) => $query->where('subject_type', $subject))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->where('occurred_at', '>=', CarbonImmutable::parse($from, 'Asia/Bangkok')->utc()))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->where('occurred_at', '<', CarbonImmutable::parse($to, 'Asia/Bangkok')->addDay()->utc()))
            ->orderByDesc('id')->cursorPaginate(20)->appends($filters);

        return response()->view('security.audit', compact('events', 'branches', 'filters'))->header('Cache-Control', 'private, no-store');
    }
}
