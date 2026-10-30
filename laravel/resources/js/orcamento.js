/* Orçamento: linhas de itens, valor do período, disponível (só como aviso: orçamento não reserva) e total. */
import { precoItem, diasCobranca, dias, numero, moeda, decimal } from './precos.js';

export function orcamento(form) {
    const corpo = form.querySelector('[data-corpo]');
    const modelo = form.querySelector('[data-modelo]');
    const saida = form.querySelector('[data-saida]');
    const prev = form.querySelector('[data-prev]');
    const token = form.querySelector('input[name="_token"]').value;
    const cache = {};
    let espera;
    const linhas = () => [...corpo.querySelectorAll('[data-linha]')];
    const valorDoPeriodo = (opt, per) => (opt ? parseFloat(per === 'diaria' ? opt.dataset.diaria : opt.dataset[per]) || 0 : 0);

    function recalcular() {
        const d = diasCobranca(saida.value, prev.value);
        let soma = 0;
        const pedido = {};
        linhas().forEach((tr) => {
            const opt = tr.querySelector('[data-produto]').selectedOptions[0];
            const qtd = parseInt(tr.querySelector('[data-qtd]').value, 10) || 0;
            const per = tr.querySelector('[data-periodo]').value;
            const precos = { diaria: parseFloat(opt?.dataset.diaria) || 0, semanal: parseFloat(opt?.dataset.semanal) || 0,
                             quinzenal: parseFloat(opt?.dataset.quinzenal) || 0, mensal: parseFloat(opt?.dataset.mensal) || 0 };
            precos[per] = numero(tr.querySelector('[data-valor-item]').value);
            const sub = qtd * precoItem(d, per, precos);
            if (opt?.value) { soma += sub; pedido[opt.value] = (pedido[opt.value] || 0) + qtd; }
            tr.querySelector('[data-subtotal]').textContent = opt?.value ? moeda(sub) : '—';
        });
        const campo = (k) => numero(form.querySelector(`[data-valor="${k}"]`).value);
        form.querySelector('[data-total]').textContent = moeda(soma + campo('acrescimo') + campo('frete_entrega') + campo('frete_recolhimento') - campo('desconto'));
        const negativo = dias(saida.value, prev.value) < 0;
        const info = form.querySelector('[data-info-dias]');
        info.textContent = negativo ? 'A devolução não pode ser antes da saída.' : `${d} dia(s)`;
        info.classList.toggle('text-red-600', negativo);
        linhas().forEach((tr) => {
            const p = tr.querySelector('[data-produto]').value, cel = tr.querySelector('[data-disp]');
            const disp = cache[`${p}|${saida.value}|${prev.value}`];
            if (!p || disp === undefined) { cel.innerHTML = `<span class="text-zinc-400">${p ? '…' : '—'}</span>`; return; }
            cel.innerHTML = `<span class="${pedido[p] > disp ? 'selo-vermelho' : 'selo-verde'}">${disp}</span>`;
        });
    }

    function consultar() {
        if (!saida.value || !prev.value || dias(saida.value, prev.value) < 0) { recalcular(); return; }
        new Set(linhas().map((tr) => tr.querySelector('[data-produto]').value).filter(Boolean)).forEach((p) => {
            const chave = `${p}|${saida.value}|${prev.value}`;
            if (cache[chave] !== undefined) { return; }
            fetch(form.dataset.disponivel, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json' },
                                             body: new URLSearchParams({ produto: p, inicio: saida.value, fim: prev.value, _token: token }) })
                .then((r) => r.json()).then((j) => { if (j.ok) { cache[chave] = j.disponivel; recalcular(); } }).catch(() => {});
        });
        recalcular();
    }
    const agendar = () => { clearTimeout(espera); espera = setTimeout(consultar, 250); };

    function preparar(tr) {
        const valor = tr.querySelector('[data-valor-item]');
        valor.dataset.auto = valor.value === '' ? '1' : '';
        const preencher = (forcar) => {
            const v = valorDoPeriodo(tr.querySelector('[data-produto]').selectedOptions[0], tr.querySelector('[data-periodo]').value);
            if (v > 0 && (forcar || valor.value === '' || valor.dataset.auto === '1')) { valor.value = decimal(v); valor.dataset.auto = '1'; }
        };
        tr.querySelector('[data-produto]').addEventListener('change', () => { preencher(false); agendar(); });
        tr.querySelector('[data-periodo]').addEventListener('change', () => { preencher(true); recalcular(); });
        valor.addEventListener('input', () => { valor.dataset.auto = ''; recalcular(); });
        tr.querySelector('[data-qtd]').addEventListener('input', recalcular);
        tr.querySelector('[data-remover]').addEventListener('click', () => {
            if (linhas().length > 1) { tr.remove(); } else { tr.querySelector('[data-produto]').value = ''; valor.value = ''; }
            recalcular();
        });
    }
    linhas().forEach(preparar);
    form.querySelector('[data-adicionar]').addEventListener('click', () => {
        const tr = modelo.content.firstElementChild.cloneNode(true);
        corpo.append(tr);
        preparar(tr);
        tr.querySelector('[data-produto]').focus();
    });
    [saida, prev].forEach((el) => el.addEventListener('change', agendar));
    form.querySelectorAll('[data-valor]').forEach((i) => i.addEventListener('input', recalcular));
    consultar();
}
