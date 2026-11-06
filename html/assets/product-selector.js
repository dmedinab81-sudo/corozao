(() => {
    'use strict';
    document.querySelectorAll('[data-product-selector]').forEach((root) => {
        const input = root.querySelector('[data-product-query]');
        const list = root.querySelector('[data-product-results]');
        const op = root.dataset.operation;
        const warehouseInput = document.querySelector('[data-product-warehouse]');
        if (!input || !list || !op || (!root.dataset.warehouse && !warehouseInput)) return;
        let timer;
        let controller;
        let active = -1;
        let results = [];
        const showMessage = (message) => {
            list.replaceChildren();
            const row = document.createElement('div');
            row.className = 'list-group-item text-body-secondary';
            row.textContent = message;
            list.append(row);
            list.hidden = false;
            active = -1;
        };
        const render = () => {
            list.replaceChildren();
            results.forEach((item, index) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'list-group-item list-group-item-action';
                button.setAttribute('role', 'option');
                button.setAttribute('aria-selected', String(index === active));
                const heading = document.createElement('strong');
                heading.textContent = [item.producto, item.contenido, item.marca].filter(Boolean).join(' · ');
                const sub = document.createElement('span');
                sub.className = 'd-block small text-body-secondary';
                sub.textContent = [item.codigos, item.categoria, item.presentacion,
                    `Contenido base: ${Number(item.unidades_base).toString()} ${item.unidad_base || ''}`].filter(Boolean).join(' · ');
                button.append(heading, sub);
                if (item.precio_venta !== undefined) {
                    const price = document.createElement('span');
                    price.className = 'd-block small';
                    price.textContent = `$${Number(item.precio_venta).toFixed(2)} · IVA ${item.tarifa_iva}%`;
                    button.append(price);
                }
                button.addEventListener('click', () => {
                    root.dispatchEvent(new CustomEvent('product:selected', { bubbles: true, detail: item }));
                    input.value = '';
                    list.hidden = true;
                    input.focus();
                });
                list.append(button);
            });
            list.hidden = results.length === 0;
        };
        input.addEventListener('input', () => {
            clearTimeout(timer);
            if (controller) controller.abort();
            const query = input.value.trim();
            if (query.length < 2) { list.hidden = true; return; }
            timer = setTimeout(async () => {
                controller = new AbortController();
                showMessage('Buscando…');
                try {
                    const warehouse = warehouseInput ? warehouseInput.value : root.dataset.warehouse;
                    if (!warehouse) { showMessage('Selecciona primero la bodega.'); return; }
                    const url = new URL('/buscar_productos.php', window.location.origin);
                    url.search = new URLSearchParams({ operacion: op, bodega_id: warehouse, q: query });
                    const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: controller.signal });
                    const data = await response.json();
                    if (!response.ok) throw new Error(data.error || 'No se pudo buscar.');
                    results = data.resultados || [];
                    if (!results.length) showMessage('No hay coincidencias.'); else render();
                } catch (error) {
                    if (error.name !== 'AbortError') showMessage(error.message || 'No se pudo buscar.');
                }
            }, 250);
        });
        input.addEventListener('keydown', (event) => {
            if (list.hidden) return;
            if (event.key === 'ArrowDown') { event.preventDefault(); active = Math.min(active + 1, results.length - 1); render(); }
            else if (event.key === 'ArrowUp') { event.preventDefault(); active = Math.max(active - 1, 0); render(); }
            else if (event.key === 'Enter' && active >= 0 && results[active]) {
                event.preventDefault();
                root.dispatchEvent(new CustomEvent('product:selected', { bubbles: true, detail: results[active] }));
                input.value = ''; list.hidden = true; input.focus();
            } else if (event.key === 'Escape') list.hidden = true;
        });
        document.addEventListener('click', (event) => { if (!root.contains(event.target)) list.hidden = true; });
    });
})();
