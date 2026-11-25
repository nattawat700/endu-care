<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\RepairJob;
use App\Models\RepairQuotation;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class DocumentPdf
{
    public function render(string $view, array $data, string $reference): Response
    {
        [$node, $browser] = $this->executables();
        [$css, $script] = $this->assets();
        $html = view($view, $data + ['pdfAssets' => ['css' => $css, 'script' => $script]])->render();
        $html = $this->embedImages($html, $data);

        $directory = storage_path('app/private/print-'.Str::uuid());
        abort_unless(File::makeDirectory($directory, 0700, true), 503, 'ระบบสร้างพื้นที่ชั่วคราวสำหรับพิมพ์เอกสารไม่ได้');

        try {
            $htmlPath = $directory.DIRECTORY_SEPARATOR.'document.html';
            $pdfPath = $directory.DIRECTORY_SEPARATOR.'document.pdf';
            abort_if(File::put($htmlPath, $html) === false, 503, 'ระบบเตรียมไฟล์พิมพ์ไม่ได้');

            $process = new Process([
                $node, resource_path('js/render-document-pdf.mjs'), $browser,
                $directory.DIRECTORY_SEPARATOR.'browser', $htmlPath, $pdfPath,
            ], base_path(), null, null, 18);
            // The process uses a private profile and a local HTML file without a user session.
            try {
                $process->run();
            } catch (ExceptionInterface) {
                abort(503, 'ระบบเปิด Chrome หรือ Edge เพื่อสร้าง PDF ไม่สำเร็จ');
            }

            $pdf = $process->isSuccessful() && is_file($pdfPath) ? File::get($pdfPath) : '';
            abort_unless(str_starts_with($pdf, '%PDF-'), 503, 'ระบบสร้าง PDF ไม่สำเร็จ กรุณาตรวจการตั้งค่า Chrome หรือ Edge ของระบบ');
        } finally {
            File::deleteDirectory($directory);
        }

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$reference.'.pdf"',
            'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function executables(): array
    {
        $finder = new ExecutableFinder;
        $node = config('printing.node_path') ?: $finder->find('node');
        $browser = config('printing.browser_path') ?: null;
        if (PHP_OS_FAMILY === 'Windows') {
            $directories = array_filter([getenv('LOCALAPPDATA'), getenv('ProgramFiles'), getenv('ProgramFiles(x86)'), getenv('ProgramW6432')]);
            foreach ($directories as $directory) {
                if ($node === null && is_file($candidate = $directory.'/nodejs/node.exe')) {
                    $node = $candidate;
                }
                if ($browser !== null) {
                    continue;
                }
                foreach (['Google/Chrome/Application/chrome.exe', 'Microsoft/Edge/Application/msedge.exe'] as $path) {
                    if (is_file($candidate = $directory.'/'.$path)) {
                        $browser = $candidate;
                        break;
                    }
                }
            }
        }
        $browser ??= $finder->find('google-chrome') ?? $finder->find('chromium') ?? $finder->find('microsoft-edge');

        abort_unless($node && $browser && is_file($node) && is_file($browser), 503,
            'ระบบพิมพ์ต้องใช้ Node.js และ Chrome หรือ Edge บนเครื่องที่รัน ENDU CARE');

        return [$node, $browser];
    }

    private function assets(): array
    {
        $manifestPath = public_path('build/manifest.json');
        abort_unless(is_file($manifestPath), 503, 'กรุณารัน npm run build เพื่อเตรียมหน้าพิมพ์');
        $manifest = File::json($manifestPath);
        $cssFile = $manifest['resources/css/app.css']['file'] ?? null;
        $scriptFile = $manifest['resources/js/app.js']['file'] ?? null;
        abort_unless($cssFile && $scriptFile && is_file(public_path('build/'.$cssFile))
            && is_file(public_path('build/'.$scriptFile)), 503, 'กรุณารัน npm run build เพื่อเตรียมหน้าพิมพ์');

        $css = File::get(public_path('build/'.$cssFile));
        // Fonts are embedded so the browser can lay out Thai text without an HTTP request.
        $css = preg_replace_callback('~url\\(["\']?(?:/build/assets/|\\./)?([A-Za-z0-9_.-]+\\.woff2)["\']?\\)~',
            static function (array $match): string {
                $font = public_path('build/assets/'.$match[1]);

                return is_file($font) ? 'url(data:font/woff2;base64,'.base64_encode(File::get($font)).')' : $match[0];
            }, $css);

        return [$css, File::get(public_path('build/'.$scriptFile))];
    }

    private function embedImages(string $html, array $data): string
    {
        /** @var Branch $branch */
        $branch = $data['branch'];
        /** @var RepairJob $repair */
        $repair = $data['repair'] ?? null;
        /** @var RepairQuotation|null $quotation */
        $quotation = $data['quotation'] ?? null;
        $snapshot = match (true) {
            isset($data['sellerProfile']) => $data['sellerProfile'],
            isset($data['payment']) => $data['payment']->document['seller'],
            $quotation !== null => $quotation->seller_profile,
            default => $repair?->seller_profile,
        };
        $disk = Storage::disk('local');
        $images = [];
        foreach ([$snapshot, ...($data['additionalSellerProfiles'] ?? [])] as $seller) {
            $profile = $branch->documentSeller($seller);
            if (isset($profile['logo'])) {
                $images[route('branch-settings.document.logo', [$branch, $profile['logo']])] ??=
                    $disk->get('org-documents/'.$branch->organization_id.'/'.$profile['logo']);
            } elseif (isset($profile['default_logo'])) {
                $images[asset($profile['default_logo'])] ??= File::get(public_path($profile['default_logo']));
            }
        }
        if ($quotation?->signature_filename) {
            $images[route('repairs.quotations.signature', [$branch, $repair, $quotation])] =
                $disk->get($quotation->signatureDirectory($branch).'/'.$quotation->signature_filename);
        }
        foreach ($images as $url => $bytes) {
            $mime = $bytes ? (@getimagesizefromstring($bytes)['mime'] ?? '') : '';
            if (in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
                $html = str_replace('src="'.e($url).'"', 'src="data:'.$mime.';base64,'.base64_encode($bytes).'"', $html);
            }
        }

        return $html;
    }
}
