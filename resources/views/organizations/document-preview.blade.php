<x-layout title="ตัวอย่างหัวเอกสาร">
    <div class="endu-page-head">
        <div>
            <a class="endu-text-action" href="{{ route('branch-settings.show', $branch) }}">← ตั้งค่าสาขา</a>
            <h1 class="mt-2">ตัวอย่างหัวเอกสาร</h1>
            <p>{{ $branch->name }}</p>
        </div>
    </div>
    <section class="panel max-w-3xl space-y-1 p-6">
        @include('organizations.seller-header')
    </section>
</x-layout>
