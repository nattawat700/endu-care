<?php

namespace App\Http\Controllers;

use App\Enums\BranchPermission;
use App\Models\Branch;
use App\Models\Organization;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class DocumentProfileController extends Controller
{
    public function organization(Request $request, Organization $organization): RedirectResponse
    {
        Gate::authorize('manage', $organization);
        abort_if($organization->suspended_at !== null, 409);
        $this->saveProfile($request, $organization, $organization);

        return back()->with('status', 'บันทึกหัวเอกสารกิจการแล้ว');
    }

    public function branch(Request $request, Branch $branch): RedirectResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManageBranchSettings]);
        $this->saveProfile($request, $branch->organization, $branch);

        return back()->with('status', 'บันทึกหัวเอกสารสาขาแล้ว');
    }

    public function preview(Branch $branch): View
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManageBranchSettings]);

        return view('organizations.document-preview', ['branch' => $branch, 'profile' => $branch->documentSeller()]);
    }

    public function logo(Request $request, Branch $branch, string $logo): StreamedResponse
    {
        abort_unless($branch->roleFor($request->user()) !== null, 404);
        $disk = Storage::disk('local');
        $path = 'org-documents/'.$branch->organization_id.'/'.$logo;
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, headers: ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    private function saveProfile(Request $request, Organization $organization, Branch|Organization $target): void
    {
        $fields = $request->validate([
            'name' => ['nullable', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:750'],
            'tax_id' => ['nullable', 'digits:13'],
            'registration_id' => ['nullable', 'digits:13'],
            'email' => ['nullable', 'email', 'max:254'],
            'office' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'logo' => ['bail', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:max_width=6000,max_height=6000'],
            'remove_logo' => ['sometimes', 'boolean'],
        ], [
            'logo.uploaded' => 'อัปโหลดโลโก้ไม่สำเร็จ กรุณาตรวจสอบว่าไฟล์มีขนาดไม่เกิน 2 MB',
            'logo.image' => 'กรุณาเลือกไฟล์โลโก้ PNG, JPG หรือ WebP',
            'logo.mimes' => 'กรุณาเลือกไฟล์โลโก้ PNG, JPG หรือ WebP',
            'logo.max' => 'ไฟล์โลโก้ต้องมีขนาดไม่เกิน 2 MB',
            'logo.dimensions' => 'โลโก้ต้องกว้างและสูงไม่เกิน 6,000 พิกเซลต่อด้าน',
        ]);
        $profile = collect($fields)->only(['name', 'address', 'tax_id', 'registration_id', 'office', 'phone', 'email'])->filter()->all();
        $logo = $request->file('logo');
        $newFilename = null;
        if ($logo !== null) {
            $newFilename = Str::uuid().'.'.$logo->extension();
            if ($logo->storeAs('org-documents/'.$organization->id, $newFilename, 'local') === false) {
                throw ValidationException::withMessages(['logo' => 'จัดเก็บไฟล์โลโก้ไม่สำเร็จ']);
            }
            $profile['logo'] = $newFilename;
        }

        try {
            DB::transaction(function () use ($request, $target, $profile): void {
                $current = $target->newQuery()->whereKey($target->id)->lockForUpdate()->firstOrFail();
                if (! isset($profile['logo']) && ! $request->boolean('remove_logo') && isset($current->document_profile['logo'])) {
                    $profile['logo'] = $current->document_profile['logo'];
                }

                $current->update(['document_profile' => $profile === [] ? null : $profile]);
            }, 3);
        } catch (Throwable $exception) {
            if ($newFilename !== null) {
                Storage::disk('local')->delete('org-documents/'.$organization->id.'/'.$newFilename);
            }

            throw $exception;
        }
    }
}
