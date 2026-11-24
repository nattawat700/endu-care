@php
    $step = max(1, (int) ceil($series->max('total') / 4));
    $points = $series->map(fn ($point, $index) => [
        'x' => 38 + $index * 96,
        'y' => 124 - ($point['total'] * 110 / ($step * 4)),
        ...$point,
    ]);
    $line = $points->map(fn ($point) => $point['x'].','.round($point['y'], 2))->implode(' ');
@endphp

<section class="panel endu-trend-panel">
    <div class="endu-panel-head endu-panel-head--split">
        <div>
            <h2>แนวโน้มการรับงานซ่อม</h2>
            <p>จำนวนใบงานที่รับเข้าของสาขา</p>
        </div>
        <span class="endu-period">7 วันล่าสุด</span>
    </div>
    <div class="endu-trend-stat"><strong>{{ number_format($series->sum('total')) }}</strong> ใบงานในช่วงนี้</div>
    <svg viewBox="0 0 628 149" class="endu-trend" role="img" aria-label="จำนวนใบงานรายวัน {{ $series->map(fn ($point) => $point['label'].' '.$point['total'].' งาน')->implode(', ') }}">
        <defs>
            <linearGradient id="intake-chart-fill" x2="0" y2="1">
                <stop offset="0" stop-color="#3a71e0" stop-opacity=".25" />
                <stop offset="1" stop-color="#3a71e0" stop-opacity=".025" />
            </linearGradient>
        </defs>
        @foreach (range(0, 4) as $tick)
            @php($y = 124 - $tick * 27.5)
            <path d="M38 {{ $y }}h576" stroke="#e0e9f7" fill="none" />
            <text x="31" y="{{ $y + 3 }}" text-anchor="end">{{ $tick * $step }}</text>
        @endforeach
        <polygon points="38,124 {{ $line }} 614,124" fill="url(#intake-chart-fill)" />
        <polyline points="{{ $line }}" fill="none" stroke="#245dcc" stroke-width="2.5" stroke-linejoin="round" />
        @foreach ($points as $point)
            <circle cx="{{ $point['x'] }}" cy="{{ $point['y'] }}" r="3" stroke="#245dcc" stroke-width="2" fill="white">
                <title>{{ $point['label'] }} · {{ $point['total'] }} งาน</title>
            </circle>
            <text x="{{ $point['x'] }}" y="144" text-anchor="middle">{{ $point['label'] }}</text>
        @endforeach
    </svg>
    <p class="endu-trend-legend"><span aria-hidden="true"></span> ใบงานที่รับเข้า</p>
</section>
