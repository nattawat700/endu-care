document.querySelector('[data-print-receipt]')?.addEventListener('click', async () => {
    await document.fonts.ready;
    const documentImages = document.querySelectorAll('.repair-quotation img, .endu-fix-receipt img');
    await Promise.all(Array.from(documentImages, (image) => image.decode().catch(() => {})));
    window.print();
});

const printableQuotation = document.querySelector('.repair-quotation:not(.quotation-unreleased)');
if (printableQuotation) {
    window.addEventListener('beforeprint', () => {
        printableQuotation.dataset.quotationPrintLayout = 'measure';

        // Measure at the A4 print width before the browser paginates the table.
        const pageHeight = parseFloat(getComputedStyle(printableQuotation).getPropertyValue('--quotation-print-height')) * 96 / 25.4;
        const closingHeight = printableQuotation.querySelector('.quotation-closing').getBoundingClientRect().height;
        const documentHeight = printableQuotation.getBoundingClientRect().height;
        let layout = 'single';
        if (documentHeight > pageHeight) {
            layout = closingHeight <= pageHeight ? 'multiple' : 'separate-signatures';
        }
        printableQuotation.dataset.quotationPrintLayout = layout;
    });

    window.addEventListener('afterprint', () => {
        delete printableQuotation.dataset.quotationPrintLayout;
    });
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
    priceForm.addEventListener('input', updateTotal);
    updateItems();
}
