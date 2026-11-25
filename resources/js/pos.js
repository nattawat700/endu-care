const money = new Intl.NumberFormat('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const currency = new Intl.NumberFormat('th-TH', { style: 'currency', currency: 'THB', minimumFractionDigits: 2, maximumFractionDigits: 2, trailingZeroDisplay: 'stripIfInteger' });

const satang = (value) => {
    const cleaned = String(value ?? '').trim();
    if (cleaned === '') return 0;
    if (!/^(0|[1-9][0-9]{0,6})(\.[0-9]{1,2})?$/.test(cleaned)) return null;
    const [baht, fraction = ''] = cleaned.split('.');
    return Number(baht) * 100 + Number(fraction.padEnd(2, '0'));
};

const enterTo = (input, button) => {
    input.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.isComposing) {
            event.preventDefault();
            button.click();
        }
    });
};

const json = async (url, options) => {
    const response = await fetch(url, { ...options, headers: { Accept: 'application/json', ...options?.headers } });
    if (!response.ok) throw new Error('POS search failed');
    return response.json();
};

const withTerm = (path, q) => {
    const url = new URL(path);
    url.searchParams.set('q', q);
    return url;
};

const cart = document.querySelector('[data-pos-cart]');
if (cart) {
    const get = (name) => cart.querySelector('[data-pos-' + name + ']');
    const search = get('search');
    const results = get('results');
    const find = document.querySelector('[data-pos-find]');
    const status = get('search-status');
    const lines = get('lines');
    const save = get('save');
    const hold = get('hold');
    const details = get('details');
    const more = get('more');
    const productTemplate = get('product-template');
    const catalog = JSON.parse(get('products').textContent);
    let visibleProducts = catalog.items;
    const template = get('line-template');
    const priceGroup = cart.elements.price_group;
    const unitPanel = get('unit-panel');
    const unitSearch = get('unit-search');
    const unitFind = get('unit-find');
    const unitSelect = get('unit-select');
    const unitAdd = get('unit-add');
    const unitStatus = get('unit-status');
    const lineItems = new WeakMap();
    let selectedProduct;
    let searchRevision = 0;
    let unitRevision = 0;
    let customerRevision = 0;
    let debounce;
    let searchRequest;

    const field = (row, name) => row.querySelector('[data-pos-field="' + name + '"]');
    const rows = () => [...lines.children];

    const refresh = () => {
        let amount = 0;
        let count = 0;
        let subtotal = 0;
        let valid = true;
        for (const [index, row] of rows().entries()) {
            const item = lineItems.get(row);
            const price = item.prices[priceGroup.value];
            const quantity = Number(field(row, 'quantity').value);
            const discount = satang(field(row, 'discount').value);
            const lineTotal = price * quantity - discount;
            if (Number.isInteger(quantity) && quantity > 0) count += quantity;
            const validLine = price !== undefined && Number.isInteger(quantity) && quantity > 0 && quantity <= 10000
                && discount !== null && lineTotal >= 0;
            row.querySelector('[data-pos-description]').textContent = [item.unit_label, item.unit_name,
                price === undefined ? 'ไม่มีราคาของกลุ่มนี้' : money.format(price / 100) + ' บาท/หน่วย'].filter(Boolean).join(' · ');
            row.querySelector('[data-pos-line-total]').value = validLine ? money.format(lineTotal / 100) : '—';
            for (const input of row.querySelectorAll('[data-pos-field]')) {
                input.name = 'items[' + index + '][' + input.dataset.posField + ']';
            }
            const minus = row.querySelector('[data-pos-step="-1"]');
            const plus = row.querySelector('[data-pos-step="1"]');
            minus.disabled = item.tracking === 'serialized' || quantity <= 1;
            plus.disabled = item.tracking === 'serialized' || quantity >= 10000;
            valid = valid && validLine;
            subtotal += validLine ? price * quantity : 0;
            amount += validLine ? lineTotal : 0;
        }
        const billDiscount = satang(cart.elements.document_discount.value);
        valid = valid && billDiscount !== null && billDiscount <= amount && subtotal <= 999999999;
        const hasLines = lines.children.length > 0;
        const canSave = cart.dataset.canSave === '1';
        get('count').textContent = count.toLocaleString('th-TH') + ' ชิ้น';
        get('empty').hidden = hasLines;
        if (canSave) details.hidden = !hasLines && !details.open;
        get('total').value = !hasLines ? currency.format(0) : valid ? currency.format((amount - billDiscount) / 100) : '—';
        hold.disabled = save.disabled = !valid || !hasLines || !canSave;
    };

    const place = (item) => {
        const row = template.content.firstElementChild.cloneNode(true);
        lineItems.set(row, item);
        row.querySelector('[data-pos-label]').textContent = item.label;
        row.querySelector('[data-pos-remove]').setAttribute('aria-label', 'ลบ ' + item.label);
        row.querySelector('[data-pos-field="discount"]').closest('details').open = satang(item.discount) > 0;
        for (const name of ['product_id', 'barcode', 'unit_id', 'quantity', 'discount']) {
            field(row, name).value = item[name] ?? (name === 'quantity' ? 1 : '');
        }
        field(row, 'quantity').readOnly = item.tracking === 'serialized';
        lines.append(row);
    };

    const add = (item, unit) => {
        if (unit && rows().some(row => field(row, 'unit_id').value === String(unit.id))) {
            unitStatus.textContent = 'เลือกเครื่องนี้ในตะกร้าแล้ว';
            return;
        }
        const same = !unit && rows().find(row => {
            const entry = lineItems.get(row);
            return entry.product_id === item.product_id && entry.barcode === item.barcode;
        });
        if (same) {
            const quantity = field(same, 'quantity');
            if (Number(quantity.value) >= 10000) {
                status.textContent = 'จำนวนของรายการนี้ครบ 10,000 แล้ว';
                return;
            }
            quantity.value = Number(quantity.value) + 1;
        } else if (lines.children.length >= 40) {
            status.textContent = 'ตะกร้านี้มีครบ 40 รายการแล้ว';
            return;
        } else {
            place({ ...item, unit_id: unit?.id, unit_label: unit?.label });
        }
        unitRevision++;
        unitPanel.hidden = true;
        clearTimeout(debounce);
        searchRevision++;
        searchRequest?.abort();
        search.value = '';
        status.textContent = '';
        renderProducts(catalog.items, true);
        refresh();
        search.focus();
    };

    const loadUnits = async () => {
        const id = ++unitRevision;
        unitAdd.disabled = true;
        unitSelect.replaceChildren();
        unitStatus.textContent = 'กำลังค้นหาเครื่อง';
        try {
            const url = cart.dataset.units.replace(/\/0\/units$/, '/' + selectedProduct.product_id + '/units');
            const units = await json(withTerm(url, unitSearch.value.trim()));
            if (id !== unitRevision) return;
            unitSelect.replaceChildren(...units.map(unit => new Option(unit.label, unit.id)));
            unitAdd.disabled = !units.length;
            unitStatus.textContent = units.length ? '' : 'ไม่พบเครื่องที่พร้อมขายในสาขานี้';
        } catch {
            if (id === unitRevision) unitStatus.textContent = 'ค้นหาเครื่องไม่สำเร็จ กรุณาลองอีกครั้ง';
        }
    };

    const choose = (item) => {
        if (item.phone && item.tracking !== 'serialized') {
            status.textContent = 'โทรศัพท์ต้องลงทะเบียนเป็นสินค้ารายเครื่องพร้อม IMEI ก่อนขาย';
            return;
        }
        if (item.tracking === 'serialized') {
            selectedProduct = item;
            get('unit-product').textContent = item.label;
            unitSearch.value = '';
            unitPanel.hidden = false;
            unitSearch.focus();
            loadUnits();
        } else {
            add(item);
        }
    };

    const stockLabel = (product) => {
        if (product.phone && product.tracking !== 'serialized') return 'ยังไม่ได้ตั้งค่า IMEI';
        if (product.available === null) return '';
        return 'คงเหลือ ' + product.available.toLocaleString('th-TH');
    };

    const canAdd = (product) => {
        const needsImei = product.phone && product.tracking !== 'serialized';
        const outOfStock = product.available !== null && product.available < product.multiplier;
        return !needsImei && !outOfStock && product.prices[priceGroup.value] !== undefined;
    };

    const renderProducts = (products, browsing = !search.value.trim()) => {
        visibleProducts = products;
        const empty = get('no-products');
        empty.hidden = products.length > 0;
        empty.textContent = browsing ? 'ยังไม่มีสินค้าในสาขานี้' : 'ไม่พบสินค้าที่ค้นหา';
        more.hidden = !browsing || catalog.next === null;
        results.replaceChildren(...products.map(product => {
            const entry = productTemplate.content.firstElementChild.cloneNode(true);
            const button = entry.querySelector('button');
            const icon = { phone: 'phone', accessory: 'plug', labor: 'repair', service: 'repair' }[product.kind] ?? 'box';
            for (const glyph of button.querySelectorAll('[data-pos-icon]')) glyph.hidden = glyph.dataset.posIcon !== icon;
            const price = product.prices[priceGroup.value];
            button.querySelector('[data-pos-product-name]').textContent = product.name;
            button.querySelector('[data-pos-product-sku]').textContent = product.sku;
            button.querySelector('[data-pos-product-price]').textContent = price === undefined ? 'ไม่มีราคากลุ่มนี้' : currency.format(price / 100);
            button.querySelector('[data-pos-product-stock]').textContent = stockLabel(product);
            button.disabled = !canAdd(product);
            button.addEventListener('click', () => choose(product));
            return entry;
        }));
    };

    const lookup = async (scan) => {
        clearTimeout(debounce);
        searchRequest?.abort();
        const id = ++searchRevision;
        unitRevision++;
        unitPanel.hidden = true;
        const query = search.value.trim();
        status.textContent = '';
        if (!query) {
            renderProducts(catalog.items, true);
            return;
        }

        searchRequest = new AbortController();
        try {
            const products = await json(withTerm(cart.dataset.products, query), { signal: searchRequest.signal });
            if (id !== searchRevision) return;
            renderProducts(products, false);
            const exact = products.filter(item => item.exact);
            const match = exact.find(item => item.barcode !== null) ?? (exact.length === 1 ? exact[0] : null);
            if (scan && match) {
                if (canAdd(match)) choose(match);
                else status.textContent = 'สินค้านี้ยังขายไม่ได้ กรุณาตรวจสอบราคาและจำนวนในคลังขาย';
            }
        } catch {
            if (id === searchRevision) status.textContent = 'ค้นหาไม่สำเร็จ กรุณาลองอีกครั้ง';
        }
    };

    more.addEventListener('click', async () => {
        more.disabled = true;
        try {
            const url = new URL(cart.dataset.products);
            url.searchParams.set('after', catalog.next);
            const result = await json(url);
            catalog.items.push(...result.items);
            catalog.next = result.next;
            if (!search.value.trim()) renderProducts(catalog.items, true);
        } catch {
            status.textContent = 'โหลดสินค้าเพิ่มไม่สำเร็จ กรุณาลองอีกครั้ง';
        } finally {
            more.disabled = false;
        }
    });

    find.addEventListener('click', () => {
        search.focus();
        search.select();
    });
    search.addEventListener('input', () => {
        clearTimeout(debounce);
        searchRevision++;
        searchRequest?.abort();
        if (search.value.trim()) debounce = setTimeout(() => lookup(false), 220);
        else lookup(false);
    });
    search.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.isComposing) {
            event.preventDefault();
            lookup(true);
        }
    });
    unitFind.addEventListener('click', loadUnits);
    enterTo(unitSearch, unitFind);
    unitAdd.addEventListener('click', () => {
        const choice = unitSelect.selectedOptions[0];
        if (choice) add(selectedProduct, { id: Number(choice.value), label: choice.textContent });
    });
    get('unit-cancel').addEventListener('click', () => {
        unitRevision++;
        unitPanel.hidden = true;
        search.focus();
    });
    lines.addEventListener('click', (event) => {
        const button = event.target.closest('[data-pos-remove]');
        if (button) {
            button.closest('[data-pos-line]').remove();
            refresh();
            search.focus();
            return;
        }
        const step = event.target.closest('[data-pos-step]');
        if (step) {
            const quantity = field(step.closest('[data-pos-line]'), 'quantity');
            quantity.value = Math.min(10000, Math.max(1, (Number(quantity.value) || 1) + Number(step.dataset.posStep)));
            refresh();
        }
    });
    lines.addEventListener('input', refresh);
    priceGroup.addEventListener('change', () => {
        renderProducts(visibleProducts);
        refresh();
    });
    details.addEventListener('invalid', () => { details.open = true; }, true);
    cart.elements.document_discount.addEventListener('input', refresh);

    const customerMode = cart.elements.customer_mode;
    customerMode.addEventListener('change', () => {
        for (const panel of cart.querySelectorAll('[data-pos-customer-mode]')) {
            const active = panel.dataset.posCustomerMode === customerMode.value;
            panel.hidden = !active;
            panel.disabled = !active;
        }
    });
    const customerSearch = get('customer-search');
    const customerFind = get('customer-find');
    const customerStatus = get('customer-status');
    const choice = cart.elements.customer_id;
    enterTo(customerSearch, customerFind);
    customerFind.addEventListener('click', async () => {
        const term = customerSearch.value.trim();
        const id = ++customerRevision;
        if (term.length < 2) {
            customerStatus.textContent = 'กรอกชื่ออย่างน้อย 2 ตัวอักษร หรือกรอกเบอร์โทรเต็ม';
            return;
        }
        customerStatus.textContent = 'กำลังค้นหาลูกค้า';
        try {
            const people = await json(cart.dataset.customers, {
                method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': cart.elements._token.value },
                body: JSON.stringify({ q: term }),
            });
            if (id !== customerRevision) return;
            choice.replaceChildren(new Option('เลือกลูกค้า', ''), ...people.map(person => new Option(person.label, person.id)));
            if (people.length === 1) choice.value = people[0].id;
            customerStatus.textContent = people.length ? '' : 'ไม่พบลูกค้า';
            if (people.length > 1) choice.focus();
        } catch {
            if (id === customerRevision) customerStatus.textContent = 'ค้นหาลูกค้าไม่สำเร็จ กรุณาลองอีกครั้ง';
        }
    });

    const initial = JSON.parse(get('initial').textContent);
    initial.forEach(place);
    renderProducts(catalog.items);
    refresh();
    if (window.matchMedia('(pointer: fine) and (min-width: 850px)').matches && !document.querySelector('[role="alert"]')) {
        search.focus({ preventScroll: true });
    }
}

const checkout = document.querySelector('[data-pos-payment]');
if (checkout) {
    const tendered = checkout.elements.tendered;
    const change = checkout.querySelector('[data-pos-change]');
    const refresh = () => {
        const entered = satang(tendered.value);
        const net = Number(checkout.dataset.net);
        change.value = entered !== null && entered >= net ? money.format((entered - net) / 100) : '—';
    };
    tendered.addEventListener('input', refresh);
    refresh();
    if (checkout.hasAttribute('data-pos-focus-payment')) tendered.select();
}

const nextSale = document.querySelector('[data-pos-new]');
if (cart || checkout || nextSale) {
    document.addEventListener('keydown', (event) => {
        if (event.defaultPrevented || event.repeat || event.isComposing) return;
        if (event.key === 'F4' && cart) {
            event.preventDefault();
            cart.querySelector('[data-pos-search]').focus();
        } else if (event.key === 'F8' && checkout) {
            event.preventDefault();
            checkout.elements.tendered.select();
        } else if (event.key === 'F8' && cart) {
            event.preventDefault();
            cart.querySelector('[data-pos-save]:enabled')?.focus();
        } else if (event.altKey && event.key.toLowerCase() === 'n' && nextSale) {
            event.preventDefault();
            window.location.assign(nextSale.href);
        }
    });
}
