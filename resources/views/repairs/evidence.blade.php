<section class="panel mt-5 p-5 sm:p-7" id="repair-evidence" aria-labelledby="repair-evidence-title">
    <h2 id="repair-evidence-title" class="text-lg font-semibold">รูปหลักฐานก่อนและหลังซ่อม</h2>
    @unless ($repair->status->isClosed())
        <form method="POST" enctype="multipart/form-data" action="{{ route('repairs.photos.store', [$branch, $repair]) }}"
            class="mt-4 grid gap-3 sm:grid-cols-[auto_1fr_auto] sm:items-end">
            @csrf
            <x-crm-select name="phase" label="ช่วงที่ถ่าย" :options="\App\Models\RepairPhoto::PHASES" />
            <div class="min-w-0">
                <label class="field-label" for="evidence-photo">รูปหลักฐาน</label>
                <input class="field-input" type="file" id="evidence-photo" name="photo" accept="image/jpeg,image/png,image/webp" aria-describedby="evidence-format" required>
                @error('photo') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <button class="button button-primary justify-self-start" type="submit">แนบรูป</button>
        </form>
        <p class="mt-2 text-sm text-slate-600" id="evidence-format">JPG, PNG หรือ WebP ไม่เกิน 2 MB ต่อรูป และแนบได้สูงสุด 12 รูปต่อใบงาน</p>
    @endunless
    @if ($photos->isEmpty())
        <p class="mt-4 text-sm text-slate-600">ยังไม่มีรูปหลักฐาน</p>
    @else
        <ul class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($photos as $photo)
                @php($photoUrl = route('repairs.photos.show', [$branch, $repair, $photo]))
                <li class="min-w-0 overflow-hidden rounded-lg border border-slate-200">
                    <a href="{{ $photoUrl }}" target="_blank" rel="noopener" aria-label="เปิดรูป{{ \App\Models\RepairPhoto::PHASES[$photo->phase] }}ที่แนบเมื่อ {{ $photo->created_at->timezone('Asia/Bangkok')->format('d/m/Y H:i') }}">
                        <img class="h-44 w-full bg-slate-100 object-contain" src="{{ $photoUrl }}" alt="รูป{{ \App\Models\RepairPhoto::PHASES[$photo->phase] }}" loading="lazy">
                    </a>
                    <p class="px-3 pt-2 text-sm font-semibold">{{ \App\Models\RepairPhoto::PHASES[$photo->phase] }}</p>
                    <p class="px-3 pb-2 text-sm text-slate-600">{{ $photo->uploader->name }} · {{ $photo->created_at->timezone('Asia/Bangkok')->format('d/m/Y H:i') }} น.</p>
                </li>
            @endforeach
        </ul>
    @endif
    <div class="mt-3">{{ $photos->fragment('repair-evidence')->links() }}</div>
</section>
