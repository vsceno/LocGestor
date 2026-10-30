/* Devolução: dias, valor de cada item e resumo enquanto se digita (o servidor recalcula tudo ao salvar). */
import { precoItem, diasCobranca, dias, numero, moeda, decimal } from './precos.js';

export function devolucao(form) {
    const data = form.querySelector('[data-data]');
    const receber = form.querySelector('[data-receber]');
    const apurado = parseFloat(form.dataset.apurado) || 0;
    const ajuste = parseFloat(form.dataset.ajuste) || 0;
    const pago = parseFloat(form.dataset.pago) || 0;
    let receberEditado = receber.value !== '' && form.querySelector('input[name="_enviado"]') && document.querySelector('[role="alert"]');

    function recalcular() {
        const d = diasCobranca(form.dataset.saida, data.value);
        const atraso = dias(form.dataset.prev, data.value);
        form.querySelector('[data-dias]').textContent = d;
        form.querySelector('[data-atraso]').textContent = atraso > 0 ? `(${atraso} dia(s) de atraso)` : '';
        let itens = 0, perdas = 0, ficam = 0;
        form.querySelectorAll('[data-linha]').forEach((tr) => {
            const inp = tr.querySelector('[data-qtd]'), max = parseInt(inp.max, 10);
            const q = Math.min(parseInt(inp.value, 10) || 0, max);
            const p = Math.min(parseInt(tr.querySelector('[data-perda]').value, 10) || 0, max - q);
            const v = q * precoItem(d, tr.dataset.periodo, { diaria: parseFloat(tr.dataset.diaria), semanal: parseFloat(tr.dataset.semanal),
                                                           quinzenal: parseFloat(tr.dataset.quinzenal), mensal: parseFloat(tr.dataset.mensal) });
            itens += v;
            perdas += p * (parseFloat(tr.dataset.reposicao) || 0);   // a reposição entra na avaria
            ficam += max - q - p;
            tr.querySelector('[data-valor]').textContent = moeda(v);
        });
        const desta = itens + perdas + numero(form.querySelector('[data-multa]').value) + numero(form.querySelector('[data-avaria]').value);
        const total = apurado + desta + ajuste;
        const saldo = total - pago;
        form.querySelector('[data-r-desta]').textContent = moeda(desta);
        form.querySelector('[data-r-total]').textContent = moeda(total);
        form.querySelector('[data-r-saldo]').textContent = moeda(saldo);
        form.querySelector('[data-r-aviso]').textContent = ficam > 0 ? `${ficam} item(ns) continuarão na rua e serão cobrados na próxima devolução.` : '';
        if (!receberEditado) { receber.value = saldo > 0 ? decimal(saldo) : ''; }
    }

    receber.addEventListener('input', () => { receberEditado = true; });
    form.querySelectorAll('input').forEach((el) => {
        if (el !== receber) { el.addEventListener('input', recalcular); el.addEventListener('change', recalcular); }
    });
    recalcular();
}
