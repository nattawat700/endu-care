<x-layout title="ฟังก์ชันเฟส 1" :active-branch="$activeBranch" :active-module="is_string($module) && $modules->has($module) ? $module : 'catalog'">
    <div class="endu-page-head">
        <div>
            <h1>{{ is_string($module) ? $modules->get($module, 'ฟังก์ชันเฟส 1') : 'ฟังก์ชันเฟส 1' }}</h1>
            <p>{{ number_format($featureCount) }} ฟังก์ชันใน {{ $modules->count() }} หมวดตามขอบเขตโครงการ</p>
        </div>
        @if ($activeBranch && $module === 'REP')
            <a class="button button-secondary" href="{{ route('repairs.index', $activeBranch) }}">เปิดคิวงานซ่อม</a>
        @endif
    </div>

    <form class="panel endu-catalog-filters" method="GET" action="{{ route('phase-one') }}" role="search" aria-label="ค้นหาฟังก์ชัน">
        @if ($activeBranch)
            <input type="hidden" name="branch" value="{{ $activeBranch->id }}">
        @endif
        <div>
            <label class="field-label" for="feature-search">ค้นหาด้วยชื่อหรือรหัส</label>
            <input class="field-input" id="feature-search" name="q" type="search" value="{{ $query }}" maxlength="120" placeholder="เช่น REP-01 หรือ เปิดใบงาน">
        </div>
        <div>
            <label class="field-label" for="feature-module">หมวดงาน</label>
            <select class="field-input" name="module" id="feature-module">
                <option value="">ทุกหมวด</option>
                @foreach ($modules as $key => $label)
                    <option value="{{ $key }}" @selected($module === $key)>{{ $key }} · {{ $label }}</option>
                @endforeach
            </select>
        </div>
        <button class="button button-primary" type="submit">ค้นหา</button>
    </form>

    <div class="endu-catalog-groups">
        @forelse ($groups as $key => $features)
            <details class="panel endu-catalog-group" @if ($query !== '' || $module === $key) open @endif>
                <summary>
                    <span class="endu-module-code">{{ $key }}</span>
                    <strong>{{ $modules->get($key) }}</strong>
                    <span class="endu-feature-count">{{ count($features) }} ฟังก์ชัน</span>
                </summary>
                <ul>
                    @foreach ($features as $feature)
                        <li>
                            <span class="endu-feature-id">{{ $feature['id'] }}</span>
                            <div>
                                <h2>{{ $feature['title'] }}</h2>
                                <p>{{ $feature['description'] }}</p>
                                <small>{{ $feature['role'] }}</small>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </details>
        @empty
            <p class="panel p-6">ไม่พบฟังก์ชันที่ตรงกับการค้นหา</p>
        @endforelse
    </div>
</x-layout>
