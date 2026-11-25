<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentProof
{
    public const IMAGE_RULES = ['bail', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=6000,max_height=6000'];

    public static function store(UploadedFile $file, string $directory, string $field): array
    {
        $digest = hash_file('sha256', $file->getRealPath());
        $type = $file->getMimeType();
        $path = $digest ? $file->store($directory, 'local') : false;
        if ($path === false) {
            throw ValidationException::withMessages([$field => 'จัดเก็บหลักฐานไม่สำเร็จ กรุณาลองอีกครั้ง']);
        }

        return ['path' => $path, 'filename' => basename($path), 'type' => $type, 'digest' => $digest];
    }

    public static function response(string $directory, ?string $filename, ?string $type = null): StreamedResponse
    {
        abort_if($filename === null, 404);
        $disk = Storage::disk('local');
        $path = $directory.'/'.$filename;
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, headers: [
            'Content-Type' => $type ?? $disk->mimeType($path),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'Content-Security-Policy' => "sandbox; default-src 'none'",
        ]);
    }
}
