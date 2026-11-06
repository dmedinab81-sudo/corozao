(() => {
    const moneyCents = value => {
        const text = String(value ?? '').trim();
        if (!/^\d{1,12}(?:\.\d{1,2})?$/.test(text)) return 0;
        const [whole, fraction = ''] = text.split('.');
        return Number(whole) * 100 + Number((fraction + '00').slice(0, 2));
    };
    const money = cents => `$${(cents / 100).toFixed(2)}`;

    document.querySelectorAll('[data-cobro-form]').forEach(form => {
        let lineTotal = Number(form.dataset.totalCentavos || 0);
        const direct = form.id === 'form-venta-directa';
        const cashGroup = form.querySelector('[data-cash-tender]');
        const received = form.querySelector('[name="efectivo_recibido"]');
        const changeLabel = form.querySelector('[data-cash-change]');
        const status = form.querySelector('[data-cobro-status]');
        const dueLabel = form.querySelector('[data-amount-due]');
        const submit = form.querySelector('[data-cobro-submit]');
        const discount = form.querySelector('[name="descuento_valor"]');

        const refresh = () => {
            const applied = [...form.querySelectorAll('input[name^="pagos["]')]
                .reduce((sum, input) => sum + moneyCents(input.value), 0);
            const cash = moneyCents(form.querySelector('[name="pagos[EFECTIVO]"]')?.value);
            const discountCents = moneyCents(discount?.value);
            const gross = Math.max(0, lineTotal - discountCents);
            const due = gross - applied;
            if (dueLabel) dueLabel.textContent = money(Math.max(0, due));

            const usingCash = cash > 0;
            if (cashGroup) cashGroup.hidden = !usingCash;
            if (received) {
                received.required = usingCash;
                received.min = (cash / 100).toFixed(2);
                if (!usingCash && Number(received.value || 0) !== 0) received.value = '0.00';
            }
            const receivedCents = moneyCents(received?.value);
            const cashEnough = !usingCash || receivedCents >= cash;
            if (changeLabel) {
                if (!usingCash) changeLabel.textContent = '$0.00';
                else if (!cashEnough) changeLabel.textContent = 'Efectivo insuficiente';
                else changeLabel.textContent = money(receivedCents - cash);
            }
            const linesPresent = !direct || form.querySelectorAll('input[name$="[presentacion_id]"]').length > 0;
            const complete = due === 0 && cashEnough && applied === gross && linesPresent;
            if (status) {
                status.classList.toggle('text-danger', !complete);
                status.classList.toggle('text-success', complete);
                status.textContent = !cashEnough
                    ? 'El efectivo recibido no cubre el importe aplicado en efectivo.'
                    : due > 0 ? `Pago incompleto: faltan ${money(due)}.`
                    : due < 0 ? `El pago excede el total por ${money(-due)}.`
                    : 'Pago completo.';
            }
            if (submit) submit.disabled = !complete;
        };

        form.addEventListener('input', refresh);
        if (direct) {
            form.addEventListener('corozao:total', event => {
                lineTotal = Number(event.detail?.centavos || 0);
                refresh();
            });
        }
        refresh();
    });
})();
