import './pos';

const repairKind = document.querySelector('[data-repair-kind]');
if (repairKind) {
    const form = repairKind.form;
    const priceGroup = form.querySelector('[data-quick-price]');
    const price = priceGroup.querySelector('input');
    const requiredForQuick = form.querySelectorAll('[data-required-for-quick]');
    const updateIntake = () => {
        const quick = repairKind.value === 'quick';
        priceGroup.hidden = !quick;
        price.disabled = !quick;
        price.required = quick;
        requiredForQuick.forEach(field => { field.required = quick; });
    };

    repairKind.addEventListener('change', updateIntake);
    updateIntake();
}

const printDocument = async () => {
    await document.fonts.ready;
    const documentImages = document.querySelectorAll('.repair-quotation img, .endu-fix-receipt img');
    await Promise.all(Array.from(documentImages, (image) => image.decode().catch(() => {})));
    window.print();
};

document.querySelector('[data-print-receipt]')?.addEventListener('click', printDocument);

const a4PrintPage = document.querySelector('[data-a4-print-page]');
if (a4PrintPage) {
    const paper = a4PrintPage.querySelector('.repair-quotation');
    window.addEventListener('beforeprint', () => {
        paper.dataset.quotationPrintLayout = 'measure';
        const contentHeight = paper.getBoundingClientRect().height;
        delete paper.dataset.quotationPrintLayout;

        const singlePageHeight = 225 * 96 / 25.4;
        a4PrintPage.dataset.a4PrintMode = contentHeight <= singlePageHeight ? 'single' : 'flow';
    });
    window.addEventListener('afterprint', () => delete a4PrintPage.dataset.a4PrintMode);
}

const pattern = document.querySelector('[data-unlock-pattern]');
if (pattern) {
    const input = pattern.querySelector('[name="device_unlock_pattern"]');
    const count = pattern.querySelector('[data-pattern-count]');
    const points = [...pattern.querySelectorAll('[data-pattern-point]')];

    const renderPattern = () => {
        points.forEach((point) => {
            const order = input.value.indexOf(point.dataset.patternPoint) + 1;
            point.textContent = order || '●';
            point.setAttribute('aria-pressed', Boolean(order));
        });
        count.textContent = input.value ? `เลือกแล้ว ${input.value.length} จุด` : '';
    };

    points.forEach((point) => {
        point.addEventListener('click', () => {
            if (!input.value.includes(point.dataset.patternPoint)) {
                input.value += point.dataset.patternPoint;
                renderPattern();
            }
        });
    });

    pattern.querySelector('[data-pattern-reset]').addEventListener('click', () => {
        input.value = '';
        renderPattern();
    });
    window.addEventListener('pageshow', renderPattern);
}

const drawer = document.querySelector('[data-workspace-drawer]');
const menuToggle = document.querySelector('[data-workspace-open]');
if (drawer && menuToggle) {
    menuToggle.addEventListener('click', () => {
        drawer.showModal();
        menuToggle.setAttribute('aria-expanded', 'true');
    });
    drawer.querySelector('[data-workspace-close]').addEventListener('click', () => drawer.close());
    drawer.addEventListener('click', (event) => {
        if (event.target === drawer || event.target.closest('a')) {
            drawer.close();
        }
    });
    drawer.addEventListener('close', () => menuToggle.setAttribute('aria-expanded', 'false'));
}

const repairSearch = document.querySelector('#header-repair-search');
if (repairSearch) {
    window.addEventListener('keydown', (event) => {
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            repairSearch.focus();
        }
    });
}

const priceForm = document.querySelector('[data-price-form]');
if (priceForm) {
    const items = priceForm.querySelector('[data-price-items]');
    const template = priceForm.querySelector('[data-price-template]');
    const addButton = priceForm.querySelector('[data-add-price-item]');
    const preview = priceForm.querySelector('[data-price-preview]');
    const difference = priceForm.querySelector('[data-price-difference]');
    const moneyOptions = { minimumFractionDigits: 2, maximumFractionDigits: 2 };
    const money = new Intl.NumberFormat('th-TH', moneyOptions);
    const signedMoney = new Intl.NumberFormat('th-TH', { ...moneyOptions, signDisplay: 'exceptZero' });

    const adjustments = priceForm.querySelector('[data-quote-amounts]');

    const hundredths = (value) => {
        const match = /^(0|[1-9][0-9]{0,6})(?:\.([0-9]{1,2}))?$/.exec(value);
        return match ? BigInt(match[1]) * 100n + BigInt((match[2] ?? '').padEnd(2, '0')) : 0n;
    };

    const updateTotal = () => {
        let subtotal = 0n;
        let hasInvalidAmount = false;
        for (const item of items.children) {
            const quantity = Number(item.querySelector('[data-price-field="quantity"]').value);
            if (!Number.isInteger(quantity) || quantity < 1 || quantity > 1000) {
                continue;
            }

            const unit = hundredths(item.querySelector('[data-price-field="unit_price"]').value);
            const discount = hundredths(item.querySelector('[data-price-field="discount"]')?.value ?? '');
            const gross = BigInt(quantity) * unit;
            hasInvalidAmount ||= discount > gross;
            subtotal += gross - discount;
        }

        let total = subtotal;
        if (adjustments) {
            const field = (name) => adjustments.querySelector(`[name="${name}"]`);
            const mode = field('vat_mode').value;
            const rateInput = field('vat_rate');
            rateInput.disabled = mode === 'none';
            rateInput.required = mode !== 'none';
            const discount = hundredths(field('document_discount').value);
            const withholding = hundredths(field('withholding').value);
            const rate = mode === 'none' ? 0n : hundredths(rateInput.value);
            const base = subtotal - discount + hundredths(field('shipping').value) + hundredths(field('other_charge').value);
            const divisor = mode === 'inclusive' ? 10000n + rate : 10000n;
            const vat = (base * rate + divisor / 2n) / divisor;
            total = mode === 'exclusive' ? base + vat : base;

            hasInvalidAmount ||= discount > subtotal || rate > 10000n || withholding > total;
            adjustments.querySelector('[data-quote-price-error]').hidden = !hasInvalidAmount;
            const previews = {net: total - vat, vat, payable: total - withholding};
            for (const [name, amount] of Object.entries(previews)) {
                adjustments.querySelector(`[data-quote-preview="${name}"]`).value = hasInvalidAmount ? '—' : money.format(Number(amount) / 100);
            }
        }

        preview.value = hasInvalidAmount ? '—' : money.format(Number(total) / 100);
        if (difference) {
            difference.value = hasInvalidAmount ? '—' : signedMoney.format((Number(total) - Number(difference.dataset.priceDifference)) / 100);
        }
    };

    const updateItems = () => {
        for (const [index, item] of Array.from(items.children).entries()) {
            item.querySelector('[data-price-number]').textContent = `รายการ ${index + 1}`;
            item.querySelector('[data-remove-price-item]').disabled = items.children.length === 1;
            for (const field of item.querySelectorAll('[data-price-field]')) {
                field.name = `items[${index}][${field.dataset.priceField}]`;
            }
        }
        addButton.disabled = items.children.length >= 40;
        updateTotal();
    };

    addButton.addEventListener('click', () => {
        items.append(template.content.cloneNode(true));
        updateItems();
        items.lastElementChild.querySelector('select').focus();
    });
    items.addEventListener('click', (event) => {
        const remove = event.target.closest('[data-remove-price-item]');
        if (remove && items.children.length > 1) {
            remove.closest('[data-price-item]').remove();
            updateItems();
            addButton.focus();
        }
    });
    const catalog = priceForm.querySelector('[data-quotation-catalog]');
    if (catalog) {
        const search = catalog.querySelector('[data-catalog-search]');
        const model = catalog.querySelector('[data-catalog-model]');
        const find = catalog.querySelector('[data-catalog-find]');
        const results = catalog.querySelector('[data-catalog-results]');
        const choices = catalog.querySelector('[data-catalog-product]');
        const groups = catalog.querySelector('[data-catalog-group]');
        const status = catalog.querySelector('[data-catalog-status]');
        const add = catalog.querySelector('[data-catalog-add]');
        const labels = {retail: 'ปลีก', dealer: 'ช่าง', partner: 'พาร์ตเนอร์'};
        let products = [];
        const renderGroups = () => {
            const product = products[choices.value];
            groups.replaceChildren(...Object.keys(product.prices).map(group => new Option(labels[group], group)));
            add.disabled = groups.options.length === 0;
        };
        choices.addEventListener('change', renderGroups);
        [search, model].forEach(input => input.addEventListener('keydown', event => {
            if (event.key === 'Enter') {
                event.preventDefault();
                find.click();
            }
        }));
        find.addEventListener('click', async () => {
            if (!search.value.trim()) {
                status.textContent = 'กรอกชื่อ SKU หรือบาร์โค้ด';
                search.focus();
                return;
            }
            find.disabled = true;
            results.hidden = true;
            status.textContent = 'กำลังค้นหา';
            try {
                const url = new URL(catalog.dataset.quotationCatalog);
                url.search = new URLSearchParams({q: search.value.trim(), model: model.value.trim()});
                const response = await fetch(url, {headers: {Accept: 'application/json'}});
                if (!response.ok) throw new Error('Catalog lookup failed');
                products = await response.json();
                choices.replaceChildren(...products.map((product, index) => new Option(product.description, index)));
                if (products.length > 0) {
                    renderGroups();
                    results.hidden = false;
                }
                status.textContent = products.length ? `พบ ${products.length} รายการ` : 'ไม่พบสินค้า';
            } catch {
                status.textContent = 'ค้นสินค้าไม่สำเร็จ กรุณาลองอีกครั้ง';
            } finally {
                find.disabled = false;
            }
        });
        add.addEventListener('click', () => {
            const product = products[choices.value];
            let item = items.lastElementChild;
            if (item.querySelector('[data-price-field="description"]').value.trim()) {
                if (items.children.length >= 40) {
                    status.textContent = 'ใบเสนอราคามีครบ 40 รายการแล้ว';
                    return;
                }
                items.append(template.content.cloneNode(true));
                item = items.lastElementChild;
            }
            for (const [field, value] of Object.entries({
                type: product.type, description: product.description, unit: product.unit, quantity: 1,
                unit_price: product.prices[groups.value], discount: '',
            })) {
                item.querySelector(`[data-price-field="${field}"]`).value = value;
            }
            updateItems();
            item.querySelector('[data-price-field="quantity"]').focus();
            status.textContent = 'เพิ่มสินค้าลงใบเสนอราคาแล้ว';
        });
    }
    priceForm.addEventListener('input', updateTotal);
    updateItems();
}

const integrationForm = document.querySelector('[data-integration-form]');
const integrationKind = integrationForm?.querySelector('select[name=kind]');
if (integrationKind) {
    const updateConnectionFields = () => {
        for (const group of integrationForm.querySelectorAll('[data-connector-fields]')) {
            const active = group.dataset.connectorFields.split(' ').includes(integrationKind.value);
            group.hidden = !active;
            for (const input of group.querySelectorAll('input')) input.disabled = !active;
        }
    };
    integrationKind.addEventListener('change', updateConnectionFields);
    updateConnectionFields();
}

document.querySelector('[data-integration-scan]')?.select();

const stockLocationForm = document.querySelector('[data-stock-location-form]');
const stockLocations = stockLocationForm?.querySelector('select[name=location_id]');
if (stockLocations) {
    const stockWarehouse = stockLocationForm.querySelector('select[name=warehouse_id]');
    const updateStockLocations = () => {
        for (const group of stockLocations.querySelectorAll('optgroup')) {
            const belongs = group.dataset.stockWarehouse === stockWarehouse.value;
            group.hidden = !belongs;
            group.disabled = !belongs;
        }
        if (stockLocations.selectedOptions[0]?.parentElement.disabled) {
            stockLocations.value = '';
        }
    };
    stockWarehouse.addEventListener('change', updateStockLocations);
    updateStockLocations();
}

const paymentForm = document.querySelector('[data-payment-form]');
if (paymentForm) {
    const satang = (input) => {
        const value = input?.value.trim() ?? '';
        if (!/^\d+(?:\.\d{0,2})?$/.test(value)) return 0;
        const [baht, fraction = ''] = value.split('.');
        return Number(baht) * 100 + Number(fraction.padEnd(2, '0'));
    };
    const format = new Intl.NumberFormat('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const updatePayment = () => {
        const cash = satang(paymentForm.elements['lines[cash]']);
        const nonCash = ['transfer', 'qr', 'other'].reduce((total, channel) => total + satang(paymentForm.elements['lines[' + channel + ']']), 0);
        for (const section of paymentForm.querySelectorAll('[data-payment-proof]')) {
            const active = satang(paymentForm.elements['lines[' + section.dataset.paymentProof + ']']) > 0;
            section.hidden = !active;
            section.disabled = !active;
            for (const input of section.querySelectorAll('input')) input.required = active;
        }
        const receipt = paymentForm.elements.document_type;
        receipt.querySelector('[value=cash_receipt]').disabled = nonCash > 0;
        if (nonCash > 0 && receipt.value === 'cash_receipt') receipt.value = 'receipt';
        const tendered = satang(paymentForm.elements.tendered);
        paymentForm.querySelector('[data-payment-total]').textContent = format.format((cash + nonCash) / 100);
        paymentForm.querySelector('[data-payment-change]').textContent = format.format(Math.max(0, tendered - cash) / 100);
    };
    paymentForm.addEventListener('input', updatePayment);
    updatePayment();
}
