<?php

namespace App\Http\Controllers;

use App\Enums\BranchPermission;
use App\Models\Branch;
use App\Models\RepairJob;
use App\Models\RepairPhoto;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class RepairPhotoController extends Controller
{
    public function store(Request $request, Branch $branch, RepairJob $repair): RedirectResponse
    {
        $this->authorizeEvidence($branch, $repair);
        abort_if($repair->status->isClosed(), 409);
        $fields = $request->validate([
            'phase' => ['required', Rule::in(array_keys(RepairPhoto::PHASES))],
            'photo' => ['bail', 'required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:max_width=6000,max_height=6000'],
        ], [
            'photo.required' => 'กรุณาเลือกไฟล์รูปหลักฐาน',
            'photo.image' => 'รองรับรูป JPG, PNG และ WebP',
            'photo.mimes' => 'รองรับรูป JPG, PNG และ WebP',
            'photo.max' => 'รูปต้องมีขนาดไม่เกิน 2 MB',
            'photo.uploaded' => 'อัปโหลดรูปไม่สำเร็จ กรุณาตรวจว่าขนาดไฟล์ไม่เกิน 2 MB',
            'photo.dimensions' => 'รูปต้องมีขนาดไม่เกิน 6,000 พิกเซลต่อด้าน',
        ]);

        $file = $fields['photo'];
        $path = $file->store(RepairPhoto::directory($repair), 'local');
        if ($path === false) {
            throw ValidationException::withMessages(['photo' => 'จัดเก็บรูปไม่สำเร็จ กรุณาลองอีกครั้ง']);
        }

        try {
            DB::transaction(function () use ($request, $branch, $repair, $fields, $file, $path): void {
                $job = $branch->repairs()->whereKey($repair->id)->lockForUpdate()->firstOrFail();
                $this->authorizeEvidence($branch, $job);
                abort_if($job->status->isClosed(), 409);
                if ($job->photos()->count() >= 12) {
                    throw ValidationException::withMessages(['photo' => 'แนบได้สูงสุด 12 รูปต่อใบงาน']);
                }

                $job->photos()->create([
                    'phase' => $fields['phase'],
                    'filename' => basename($path),
                    'content_type' => $file->getMimeType(),
                    'uploaded_by' => $request->user()->id,
                ]);
            }, 3);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);

            throw $exception;
        }

        return redirect()->to(route('repairs.show', [$branch, $repair]).'#repair-evidence')->with('status', 'บันทึกรูปหลักฐานแล้ว');
    }

    public function show(Branch $branch, RepairJob $repair, RepairPhoto $photo): StreamedResponse
    {
        $this->authorizeEvidence($branch, $repair);
        $disk = Storage::disk('local');
        $path = RepairPhoto::directory($repair).'/'.$photo->filename;
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, headers: [
            'Content-Type' => $photo->content_type,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    private function authorizeEvidence(Branch $branch, RepairJob $repair): void
    {
        $repair->setRelation('branch', $branch);
        Gate::authorize('view', $repair);
        $role = $branch->roleFor(auth()->user());
        abort_unless($role?->allows(BranchPermission::RepairEvidence), 404);
    }
}
