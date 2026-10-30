/* Nova locação: linhas de itens, dias e devolução por item, disponível em tempo real e total. Mesmo comportamento do sistema antigo. */
import { precoItem, diasCobranca, dias, somarDias, numero, moeda, decimal } from './precos.js';

export function novaLocacao(form) {
    const corpo = form.querySelector('[data-itens]');
    const modelo = form.querySelector('[data-modelo]');
    const saida = form.querySelector('[data-saida]');
    const prevGeral = form.querySelector('[data-prev-geral]');
    const reserva = form.querySelector('[data-reserva]').value;
    const token = form.querySelector('input[name="_token"]').value;
    const blocos = { semanal: 7, quinzenal: 15, mensal: 30 };
    const cache = {};
    let espera;

    const linhas = () => [...corpo.querySelectorAll('[data-linha]')];
    const $ = (tr, k) => tr.querySelector(`[data-${k}]`);
    const opcao = (tr) => $(tr, 'produto').selectedOptions[0];
    const devolucao = (tr) => $(tr, 'prev').value || prevGeral.value;
    const valorDoPeriodo = (opt, per) => (opt ? parseFloat(per === 'diaria' ? opt.dataset.diaria : opt.dataset[per]) || 0 : 0);

    // A devolução geral (usada na conferência do estoque) é a mais tarde entre as dos itens
    function atualizarPrevGeral() {
        let maior = '';
        linhas().forEach((tr) => { const v = $(tr, 'prev').value; if (v > maior) { maior = v; } });
        if (maior) { prevGeral.value = maior; }
    }

    function atualizarDias(tr) {
        if ($(tr, 'prev').value && saida.value) { $(tr, 'dias').value = diasCobranca(saida.value, $(tr, 'prev').value); }
    }

    function recalcular() {
        let soma = 0;
        linhas().forEach((tr) => {
            const opt = opcao(tr);
            const qtd = parseInt($(tr, 'qtd').value, 10) || 0;
            const per = $(tr, 'periodo').value;
            const d = diasCobranca(saida.value, devolucao(tr));
            const precos = { diaria: parseFloat(opt?.dataset.diaria) || 0, semanal: parseFloat(opt?.dataset.semanal) || 0,
                             quinzenal: parseFloat(opt?.dataset.quinzenal) || 0, mensal: parseFloat(opt?.dataset.mensal) || 0 };
            precos[per] = numero($(tr, 'valor-item').value);   // o valor digitado é o do período escolhido
            const sub = qtd * precoItem(d, per, precos);
            soma += opt?.value ? sub : 0;
            $(tr, 'subtotal').textContent = opt?.value ? moeda(sub) : '—';
        });
        const campo = (k) => numero(form.querySelector(`[data-valor="${k}"]`)?.value);
        const total = soma + campo('acrescimo') + campo('frete_entrega') - campo('desconto');
        form.querySelector('[data-total]').textContent = moeda(total);
        const d = diasCobranca(saida.value, prevGeral.value);
        form.querySelector('[data-total-detalhe]').textContent = `(${d} dia${d > 1 ? 's' : ''})`;
        avisarEstoque();
    }

    // Soma pedida de cada produto (linhas repetidas somam) contra o disponível no período
    function avisarEstoque() {
        const pedido = {};
        linhas().forEach((tr) => { const p = $(tr, 'produto').value; if (p) { pedido[p] = (pedido[p] || 0) + (parseInt($(tr, 'qtd').value, 10) || 0); } });
        linhas().forEach((tr) => {
            const p = $(tr, 'produto').value, cel = $(tr, 'disp');
            if (!p) { cel.innerHTML = '<span class="text-zinc-400">—</span>'; return; }
            const disp = cache[`${p}|${saida.value}|${devolucao(tr)}`];
            if (disp === undefined) { cel.innerHTML = '<span class="text-zinc-400">…</span>'; return; }
            const falta = pedido[p] > disp;
            cel.innerHTML = '';
            const selo = document.createElement('span');
            selo.className = falta ? 'selo-vermelho' : 'selo-verde';
            selo.textContent = disp;
            cel.append(selo);
            if (falta) {
                const aviso = document.createElement('span');
                aviso.className = 'mt-1 block text-xs text-red-600 dark:text-red-400';
                aviso.textContent = `pedido ${pedido[p]}, disponível ${disp}`;
                cel.append(aviso);
            }
        });
    }

    function consultar() {
        if (!saida.value || !prevGeral.value || dias(saida.value, prevGeral.value) < 0) { return; }
        const pedidos = new Map();
        linhas().forEach((tr) => {
            const p = $(tr, 'produto').value, fim = devolucao(tr), chave = `${p}|${saida.value}|${fim}`;
            if (p && cache[chave] === undefined) { pedidos.set(chave, { p, fim }); }
        });
        pedidos.forEach(({ p, fim }, chave) => {
            const corpoPedido = new URLSearchParams({ produto: p, inicio: saida.value, fim, reserva, _token: token });
            fetch(form.dataset.disponivel, { method: 'POST', body: corpoPedido, credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then((r) => r.json())
                .then((j) => { if (j.ok) { cache[chave] = j.disponivel; avisarEstoque(); } })
                .catch(() => { /* sem rede: o servidor confere ao salvar */ });
        });
        avisarEstoque();
    }
    const agendar = () => { clearTimeout(espera); espera = setTimeout(consultar, 250); };

    function preparar(tr) {
        const valor = $(tr, 'valor-item');
        valor.dataset.auto = valor.value === '' ? '1' : '';
        $(tr, 'produto').addEventListener('change', () => {
            const v = valorDoPeriodo(opcao(tr), $(tr, 'periodo').value);
            if (v > 0 && (valor.value === '' || valor.dataset.auto === '1')) { valor.value = decimal(v); valor.dataset.auto = '1'; }
            recalcular(); agendar();
        });
        $(tr, 'periodo').addEventListener('change', () => {
            const per = $(tr, 'periodo').value;
            const v = valorDoPeriodo(opcao(tr), per);
            if (v > 0) { valor.value = decimal(v); valor.dataset.auto = '1'; }
            // Semanal, quinzenal ou mensal: o item já vem com 7, 15 ou 30 dias, contando o dia da saída
            if (blocos[per] && saida.value) {
                $(tr, 'dias').value = blocos[per];
                $(tr, 'prev').value = somarDias(saida.value, blocos[per] - 1);
                atualizarPrevGeral();
            }
            recalcular(); agendar();
        });
        $(tr, 'dias').addEventListener('change', () => {
            const n = parseInt($(tr, 'dias').value, 10);
            if (saida.value && n >= 1) { $(tr, 'prev').value = somarDias(saida.value, n - 1); atualizarPrevGeral(); recalcular(); agendar(); }
        });
        $(tr, 'prev').addEventListener('change', () => { atualizarDias(tr); atualizarPrevGeral(); recalcular(); agendar(); });
        valor.addEventListener('input', () => { valor.dataset.auto = ''; recalcular(); });
        $(tr, 'qtd').addEventListener('input', recalcular);
        $(tr, 'remover').addEventListener('click', () => {
            if (linhas().length > 1) { tr.remove(); } else { $(tr, 'produto').value = ''; valor.value = ''; $(tr, 'qtd').value = 1; }
            atualizarPrevGeral(); recalcular();
        });
        if (!$(tr, 'prev').value) { $(tr, 'prev').value = prevGeral.value; }
        atualizarDias(tr);
    }

    linhas().forEach(preparar);
    atualizarPrevGeral();
    form.querySelector('[data-adicionar]').addEventListener('click', () => {
        const tr = modelo.content.firstElementChild.cloneNode(true);
        corpo.append(tr);
        preparar(tr);
        $(tr, 'produto').focus();
        recalcular();
    });
    saida.addEventListener('change', () => { linhas().forEach(atualizarDias); recalcular(); agendar(); });
    form.querySelectorAll('[data-valor]').forEach((i) => i.addEventListener('input', recalcular));
    recalcular();
    consultar();
}
