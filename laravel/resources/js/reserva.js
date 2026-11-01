/* Reserva: linhas de itens, kits e disponível no período enquanto se monta (o servidor confere ao salvar). */
import { diasCobranca, dias } from './precos.js';

export function reserva(form) {
    const corpo = form.querySelector('[data-corpo]');   // não usar [data-itens]: é o atributo das opções de kit
    const modelo = form.querySelector('[data-modelo]');
    const inicio = form.querySelector('[data-inicio]');
    const fim = form.querySelector('[data-fim]');
    const token = form.querySelector('input[name="_token"]').value;
    const cache = {};
    let espera;
    const linhas = () => [...corpo.querySelectorAll('[data-linha]')];

    function avisar() {
        const pedido = {};
        linhas().forEach((tr) => { const p = tr.querySelector('[data-produto]').value; if (p) { pedido[p] = (pedido[p] || 0) + (parseInt(tr.querySelector('[data-qtd]').value, 10) || 0); } });
        linhas().forEach((tr) => {
            const p = tr.querySelector('[data-produto]').value, cel = tr.querySelector('[data-disp]');
            if (!p) { cel.innerHTML = '<span class="text-zinc-400">—</span>'; return; }
            const disp = cache[`${p}|${inicio.value}|${fim.value}`];
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
        const info = form.querySelector('[data-info-dias]');
        const negativo = dias(inicio.value, fim.value) < 0;
        info.textContent = negativo ? 'A devolução não pode ser antes da retirada.' : `${diasCobranca(inicio.value, fim.value)} dia(s)`;
        info.classList.toggle('text-red-600', negativo);
    }

    function consultar() {
        if (!inicio.value || !fim.value || dias(inicio.value, fim.value) < 0) { avisar(); return; }
        new Set(linhas().map((tr) => tr.querySelector('[data-produto]').value).filter(Boolean)).forEach((p) => {
            const chave = `${p}|${inicio.value}|${fim.value}`;
            if (cache[chave] !== undefined) { return; }
            // Na edição, a própria reserva não conta contra o estoque
            const corpoPedido = new URLSearchParams({ produto: p, inicio: inicio.value, fim: fim.value, reserva: form.dataset.reservaId, _token: token });
            fetch(form.dataset.disponivel, { method: 'POST', body: corpoPedido, credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then((r) => r.json()).then((j) => { if (j.ok) { cache[chave] = j.disponivel; avisar(); } }).catch(() => {});
        });
        avisar();
    }
    const agendar = () => { clearTimeout(espera); espera = setTimeout(consultar, 250); };

    function preparar(tr) {
        tr.querySelector('[data-produto]').addEventListener('change', agendar);
        tr.querySelector('[data-qtd]').addEventListener('input', avisar);
        tr.querySelector('[data-remover]').addEventListener('click', () => {
            if (linhas().length > 1) { tr.remove(); } else { tr.querySelector('[data-produto]').value = ''; tr.querySelector('[data-qtd]').value = 1; }
            avisar();
        });
    }
    function novaLinha() {
        const tr = modelo.content.firstElementChild.cloneNode(true);
        corpo.append(tr);
        preparar(tr);
        return tr;
    }

    linhas().forEach(preparar);
    form.querySelector('[data-adicionar]').addEventListener('click', () => { novaLinha().querySelector('[data-produto]').focus(); });
    [inicio, fim].forEach((el) => el.addEventListener('change', agendar));

    // Kit: cada produto soma na linha que já o tem, ou ocupa uma linha vazia/nova (igual ao sistema antigo)
    const kit = form.querySelector('[data-kit]');
    form.querySelector('[data-kit-adicionar]')?.addEventListener('click', () => {
        const opt = kit.selectedOptions[0];
        if (!opt?.value) { kit.focus(); return; }
        const vezes = Math.max(1, parseInt(form.querySelector('[data-kit-qtd]').value, 10) || 1);
        JSON.parse(opt.dataset.itens).forEach((c) => {
            let tr = linhas().find((x) => x.querySelector('[data-produto]').value === c.p);
            if (tr) {
                const q = tr.querySelector('[data-qtd]');
                q.value = (parseInt(q.value, 10) || 0) + c.q * vezes;
                return;
            }
            tr = linhas().find((x) => x.querySelector('[data-produto]').value === '') || novaLinha();
            tr.querySelector('[data-produto]').value = c.p;
            tr.querySelector('[data-qtd]').value = c.q * vezes;
        });
        kit.value = '';
        form.querySelector('[data-kit-qtd]').value = 1;
        agendar();
    });
    consultar();
}
