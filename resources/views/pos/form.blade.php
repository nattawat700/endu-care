@php
    use App\Models\RepairQuotation;
    use App\Support\PosCart;

    $group = old('price_group', $posSale?->price_group ?? 'retail');
    $customerMode = old('customer_mode', $posSale?->customer_id ? 'existing' : 'walkin');
    $customerId = old('customer_id', $posSale?->customer_id);
    $salespersonId = old('salesperson_id', $posSale?->salesperson_id);
@endphp

<x-layout title="ขายหน้าร้าน POS" :active-branch="$branch" active-module="POS">
    @include('pos.header', ['tab' => 'sell'])
    @include('organizations.errors')
    @if ($posSale)
        <p class="pos-cart-notice"><a class="endu-text-action" href="{{ route('pos.show', [$branch, $posSale]) }}">{{ $posSale->reference() }}</a>
            · แก้ไขตะกร้า ระบบใช้ราคาปัจจุบันเมื่อบันทึก กรุณาตรวจยอดก่อนรับเงิน</p>
    @endif
    <form method="POST" action="{{ $posSale ? route('pos.update', [$branch, $posSale]) : route('pos.store', $branch) }}"
        class="pos-grid" data-pos-cart data-can-save="{{ $counters->isNotEmpty() ? '1' : '0' }}"
        data-products="{{ route('pos.products', $branch) }}"
        data-units="{{ route('pos.units', [$branch, 0]) }}"
        data-customers="{{ route('pos.customers', $branch) }}">
        @csrf
        @if ($posSale)
            @method('PATCH')
            <input type="hidden" name="version" value="{{ $posSale->version }}">
        @else
            <input type="hidden" name="submission_id" value="{{ old('submission_id', $submissionId) }}">
        @endif

        <section class="panel pos-panel">
            <header class="pos-panel-head">
                <div>
                    <h2>เลือกสินค้า</h2>
                    <p>ค้นหรือสแกน SKU แล้วกด Enter เพื่อเพิ่ม</p>
                </div>
            </header>
            <div class="pos-search">
                <label for="pos-search">
                    <x-workspace-icon name="search" />
                    <span class="sr-only">สแกนบาร์โค้ดหรือค้นสินค้า</span>
                    <input type="search" id="pos-search" data-pos-search placeholder="ค้นหาหรือสแกนสินค้า"
                        maxlength="140" autocomplete="off" enterkeyhint="search">
                </label>
                <p class="pos-message" role="status" data-pos-search-status></p>
            </div>
                <fieldset data-pos-unit-panel hidden class="pos-units space-y-2 rounded-lg border border-slate-200 p-3">
                    <legend class="px-1 text-sm font-semibold">เลือก IMEI / Serial</legend>
                    <p class="text-sm font-semibold" data-pos-unit-product></p>
                    <div class="flex gap-2">
                        <input type="search" class="field-input min-w-0" maxlength="80" aria-label="ค้น IMEI หรือ Serial" placeholder="สแกนหรือพิมพ์ IMEI / Serial" data-pos-unit-search>
                        <button type="button" class="button button-secondary" data-pos-unit-find>ค้นเครื่อง</button>
                    </div>
                    <div class="flex gap-2">
                        <select class="field-input min-w-0" aria-label="เครื่องในคลังขาย" data-pos-unit-select></select>
                        <button type="button" class="button button-primary" data-pos-unit-add>เพิ่มเครื่อง</button>
                        <button type="button" class="button button-secondary" data-pos-unit-cancel>ปิด</button>
                    </div>
                    <p class="text-sm text-slate-700" aria-live="polite" data-pos-unit-status></p>
                </fieldset>

            <ul class="pos-products" data-pos-results></ul>
            <p class="pos-empty" data-pos-no-products hidden></p>
            <button type="button" class="button button-secondary pos-more" data-pos-more hidden>ดูสินค้าเพิ่ม</button>
            <noscript><p class="pos-empty">เปิด JavaScript เพื่อสแกนสินค้าและจัดตะกร้า</p></noscript>
            <template data-pos-product-template>
                <li>
                    <button type="button" class="pos-product">
                        <span class="pos-product-icon">
                            <span data-pos-icon="box"><x-workspace-icon name="box" /></span>
                            <span data-pos-icon="plug" hidden><x-workspace-icon name="plug" /></span>
                            <span data-pos-icon="phone" hidden><x-workspace-icon name="phone" /></span>
                            <span data-pos-icon="repair" hidden><x-workspace-icon name="repair" /></span>
                        </span>
                        <strong data-pos-product-name></strong>
                        <small data-pos-product-sku></small>
                        <span class="pos-product-price"><b data-pos-product-price></b><small data-pos-product-stock></small></span>
                    </button>
                </li>
            </template>
        </section>

        <section class="panel pos-panel">
            <header class="pos-panel-head">
                <div><h2>ตะกร้าขาย</h2><p data-pos-count>0 ชิ้น</p></div>
                <button class="button pos-hold" type="submit" name="intent" value="hold" data-pos-hold disabled>
                    <x-workspace-icon name="clock" /> พักบิล
                </button>
            </header>
            <div class="pos-cart-body">
                <p class="pos-empty pos-cart-empty" data-pos-empty>เลือกสินค้าเพื่อเริ่มขาย</p>
                <div data-pos-lines></div>
                <template data-pos-line-template>
                    <div class="pos-line" data-pos-line>
                        <div class="pos-line-item">
                            <strong data-pos-label></strong>
                            <small data-pos-description></small>
                            <input type="hidden" data-pos-field="product_id">
                            <input type="hidden" data-pos-field="barcode">
                            <input type="hidden" data-pos-field="unit_id">
                        </div>
                        <div class="pos-line-actions">
                            <div class="pos-stepper">
                                <button type="button" aria-label="ลดจำนวน" data-pos-step="-1">−</button>
                                <input type="number" aria-label="จำนวน" min="1" max="10000" required data-pos-field="quantity">
                                <button type="button" aria-label="เพิ่มจำนวน" data-pos-step="1">+</button>
                            </div>
                            <output class="pos-line-total" data-pos-line-total></output>
                            <button type="button" class="pos-remove" aria-label="ลบรายการ" data-pos-remove><x-workspace-icon name="close" /></button>
                        </div>
                        <details class="pos-line-discount">
                            <summary>ส่วนลดรายการ</summary>
                            <label><span class="sr-only">ส่วนลดรายการ (บาท)</span>
                                <input class="field-input" inputmode="decimal" maxlength="10" placeholder="0.00" data-pos-field="discount">
                            </label>
                        </details>
                    </div>
                </template>
            </div>
            @if ($counters->isEmpty())
                <p class="pos-cart-notice">สาขายังไม่มีเคาน์เตอร์ขาย เพิ่มเคาน์เตอร์ในการตั้งค่าสาขาเพื่อเปิดบิล</p>
            @endif
            <details class="pos-order-details" data-pos-details
                @if ($errors->any() || $counters->count() > 1) open @endif
                @if ($counters->isEmpty() || ($lines === [] && $counters->count() <= 1 && ! $errors->any())) hidden @endif>
                <summary>รายละเอียดบิลและส่วนลด</summary>
                <div class="pos-order-fields">
                    <label class="block"><span class="field-label">เคาน์เตอร์</span>
                        <select name="counter_id" class="field-input" required>
                            @if ($counters->count() > 1)<option value="">เลือกเคาน์เตอร์</option>@endif
                            @foreach ($counters as $counter)
                                <option value="{{ $counter->id }}" @selected((string) old('counter_id', $posSale?->branch_counter_id ?? $shift?->branch_counter_id) === (string) $counter->id)>{{ $counter->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block"><span class="field-label">กลุ่มราคา</span>
                        <select name="price_group" class="field-input">
                            @foreach ($canSetGroup ? PosCart::GROUPS : ['retail' => PosCart::GROUPS['retail']] as $key => $name)
                                <option value="{{ $key }}" @selected($group === $key)>{{ $name }}</option>
                            @endforeach
                        </select>
                    </label>
                    @if ($canSetCredit && $staff->isNotEmpty())
                        <label class="block"><span class="field-label">พนักงานรับเครดิตการขาย</span>
                            <select name="salesperson_id" class="field-input">
                                <option value="">{{ $posSale ? 'พนักงานเดิม' : 'ผู้เปิดบิล' }}</option>
                                @foreach ($staff as $person)<option value="{{ $person->id }}" @selected((string) $salespersonId === (string) $person->id)>{{ $person->name }}</option>@endforeach
                            </select>
                        </label>
                    @endif
                    <label class="block"><span class="field-label">ลูกค้า</span>
                        <select name="customer_mode" class="field-input">
                            <option value="walkin" @selected($customerMode === 'walkin')>ลูกค้าทั่วไป</option>
                            <option value="existing" @selected($customerMode === 'existing')>เลือกลูกค้าจากทะเบียน</option>
                            <option value="new" @selected($customerMode === 'new')>สร้างลูกค้าใหม่</option>
                        </select>
                    </label>
                    <fieldset data-pos-customer-mode="existing" @if ($customerMode !== 'existing') hidden disabled @endif class="space-y-2">
                        <div class="flex gap-2">
                            <input type="search" maxlength="150" class="field-input min-w-0" aria-label="ค้นตามชื่อหรือหมายเลขโทรศัพท์เต็ม" placeholder="ชื่อหรือเบอร์เต็ม" data-pos-customer-search>
                            <button type="button" class="button button-secondary" data-pos-customer-find>ค้นหา</button>
                        </div>
                        <select name="customer_id" class="field-input" aria-label="ผลการค้นหาลูกค้า" required>
                            <option value="">เลือกลูกค้า</option>
                            @if (is_scalar($customerId) && (int) $customerId > 0)
                                <option value="{{ $customerId }}" selected>{{ $posSale?->customer_id === (int) $customerId ? $posSale->customer->name : 'ลูกค้าที่เลือก #'.(int) $customerId }}</option>
                            @endif
                        </select>
                        <p class="text-sm text-slate-700" aria-live="polite" data-pos-customer-status></p>
                    </fieldset>
                    <fieldset data-pos-customer-mode="new" @if ($customerMode !== 'new') hidden disabled @endif class="space-y-2">
                        <label class="block"><span class="field-label">ชื่อลูกค้า</span><input class="field-input" name="new_customer[name]" maxlength="150" value="{{ old('new_customer.name') }}" required></label>
                        <label class="block"><span class="field-label">เบอร์โทร</span><input class="field-input" name="new_customer[phone]" type="tel" maxlength="30" value="{{ old('new_customer.phone') }}" required></label>
                        <label class="block"><span class="field-label">อีเมล</span><input class="field-input" name="new_customer[email]" type="email" maxlength="254" value="{{ old('new_customer.email') }}"></label>
                        <label class="block"><span class="field-label">ที่อยู่ออกเอกสาร</span><textarea class="field-input" name="new_customer[billing_address]" maxlength="500" rows="2">{{ old('new_customer.billing_address') }}</textarea></label>
                    </fieldset>
                    <label class="block"><span class="field-label">ส่วนลดท้ายบิล (บาท)</span>
                        <input name="document_discount" class="field-input" maxlength="10" inputmode="decimal" value="{{ old('document_discount', $posSale ? RepairQuotation::formatBaht($posSale->document_discount_satang, '') : '') }}">
                    </label>
                    @if ($canSetCredit && ! $shift)
                        <a class="endu-text-action text-sm" href="{{ route('cash-shifts.index', $branch) }}">เปิดกะรับเงิน</a>
                    @endif
                </div>
            </details>
            <div class="pos-summary">
                <div class="pos-total"><span>ยอดสุทธิ</span><output data-pos-total>฿0</output></div>
                <button class="button pos-primary" type="submit" name="intent" value="checkout" data-pos-save disabled>
                    <x-workspace-icon name="wallet" /> {{ $canSetCredit ? 'รับชำระเงิน' : 'ส่งให้แคชเชียร์' }}
                </button>
            </div>
        </section>
        <script type="application/json" data-pos-initial>@json($lines, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)</script>
        <script type="application/json" data-pos-products>@json($products, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)</script>
    </form>
</x-layout>
