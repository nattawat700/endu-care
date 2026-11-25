<?php

namespace App\Http\Controllers;

use App\Enums\BranchPermission;
use App\Models\Branch;
use App\Models\RepairJob;
use App\Models\RepairQuotation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class QuotationSignatureController extends Controller
{
    public function __invoke(Branch $branch, RepairJob $repair, RepairQuotation $quotation): StreamedResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::ReadQuotation]);
        Gate::authorize('permission', [$branch, BranchPermission::CustomerData]);
        abort_unless($quotation->signature_filename !== null && $quotation->wasReleased(), 404);

        $disk = Storage::disk('local');
        $path = $quotation->signatureDirectory($branch).'/'.$quotation->signature_filename;
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, headers: ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
