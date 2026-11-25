<x-layout title="ฟังก์ชัน POS เฟส 1" :active-branch="$branch" active-module="POS">
    @include('pos.header', ['tab' => 'features'])
    <section class="panel pos-features" aria-label="ฟังก์ชัน POS เฟส 1">
        @foreach ($features as $feature)
            <article>
                <h2><span>{{ $feature['id'] }}</span>{{ $feature['title'] }}</h2>
                <p>{{ $feature['description'] }}</p>
            </article>
        @endforeach
    </section>
</x-layout>
