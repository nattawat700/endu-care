<div class="endu-page-head pos-heading">
    <div>
        <h1>ขายหน้าร้าน POS</h1>
        <p>สแกนสินค้า จัดการตะกร้า รับชำระ และออกใบเสร็จ</p>
    </div>
    @if ($tab === 'sell')
        <button type="button" class="button pos-primary" data-pos-find>
            <x-workspace-icon name="search" /> ค้นสินค้า
        </button>
    @else
        <a class="button pos-primary" href="{{ route('pos.index', $branch) }}" data-pos-new>
            <x-workspace-icon name="search" /> ค้นสินค้า
        </a>
    @endif
</div>
<nav class="pos-tabs" aria-label="เมนูขายหน้าร้าน">
    <a href="{{ route('pos.index', $branch) }}" @if ($tab === 'sell') aria-current="page" @endif>ขายสินค้า</a>
    <a href="{{ route('pos.history', $branch) }}" @if ($tab === 'history') aria-current="page" @endif>ประวัติการขาย</a>
    <a href="{{ route('pos.features', $branch) }}" @if ($tab === 'features') aria-current="page" @endif>ฟังก์ชันเฟส 1 <span>11</span></a>
</nav>
