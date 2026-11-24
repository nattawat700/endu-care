@if ($errors->any())
    <ul class="mb-4 list-disc space-y-1 rounded-lg border border-red-200 bg-red-50 p-4 pl-8 text-red-800" role="alert">
        @foreach ($errors->all() as $error)
            <li>{{ $error }}</li>
        @endforeach
    </ul>
@endif
