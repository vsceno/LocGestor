/*
 * LocGestor (Laravel) - interações das telas, sem bibliotecas.
 * LGPD: tudo que é buscado (pode ser CPF ou telefone) vai por POST; nada de termo de busca no endereço.
 */

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
const guardar = (chave, valor) => { try { localStorage.setItem(chave, valor); } catch (e) { /* navegador sem armazenamento */ } };

/* ---------- Tema claro/escuro ---------- */
document.querySelector('[data-tema]')?.addEventListener('click', () => {
    const escuro = document.documentElement.classList.toggle('dark');
    guardar('lg-tema', escuro ? 'escuro' : 'claro');
});

/* ---------- Menu: recolher (computador) e gaveta (celular) ---------- */
const menu = document.querySelector('[data-menu]');
const fundo = document.querySelector('[data-menu-fundo]');
const gaveta = (abrir) => {
    menu?.classList.toggle('-translate-x-full', !abrir);
    fundo?.classList.toggle('hidden', !abrir);
};
document.querySelector('[data-abrir-menu]')?.addEventListener('click', () => gaveta(true));
fundo?.addEventListener('click', () => gaveta(false));
document.querySelector('[data-recolher-menu]')?.addEventListener('click', () => {
    const recolhido = document.documentElement.classList.toggle('menu-recolhido');
    guardar('lg-menu', recolhido ? 'recolhido' : 'aberto');
});

/* ---------- Avisos flutuantes ---------- */
const fecharAviso = (el) => {
    el.style.transition = 'opacity .25s, transform .25s';
    el.style.opacity = '0';
    el.style.transform = 'translateX(12px)';
    setTimeout(() => el.remove(), 260);
};
document.querySelectorAll('[data-aviso]').forEach((el) => {
    el.querySelector('[data-fechar-aviso]')?.addEventListener('click', () => fecharAviso(el));
    if (el.dataset.aviso !== 'erro') { setTimeout(() => fecharAviso(el), 5000); }
});

/* ---------- Linha de tabela clicável ---------- */
document.addEventListener('click', (ev) => {
    const tr = ev.target.closest('tr[data-href]');
    if (!tr || ev.target.closest('a, button, input, select, label')) { return; }
    if (String(window.getSelection?.() ?? '').length > 0) { return; }   // estava selecionando texto
    if (ev.ctrlKey || ev.metaKey) { window.open(tr.dataset.href, '_blank'); } else { window.location.href = tr.dataset.href; }
});

/* ---------- Busca enquanto digita (troca só a parte da tela indicada) ---------- */
document.querySelectorAll('form[data-busca-viva]').forEach((form) => {
    const destino = document.querySelector(form.dataset.buscaViva);
    const campo = form.querySelector('input[type="search"]');
    if (!destino || !campo) { return; }
    let espera;
    let pedido = 0;
    campo.addEventListener('input', () => {
        clearTimeout(espera);
        espera = setTimeout(async () => {
            const meu = ++pedido;
            destino.classList.add('opacity-50');
            try {
                const r = await fetch(form.action, {
                    method: 'POST', body: new FormData(form), credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' },
                });
                if (!r.ok) { throw new Error(`HTTP ${r.status}`); }
                const html = await r.text();
                if (meu === pedido) { destino.innerHTML = html; }   // só a resposta do último pedido conta
            } catch (e) {
                form.submit();   // sem resposta: envia do jeito normal
            } finally {
                if (meu === pedido) { destino.classList.remove('opacity-50'); }
            }
        }, 250);
    });
    campo.addEventListener('keydown', (ev) => { if (ev.key === 'Enter') { ev.preventDefault(); } });
});

/* ---------- Paleta de comandos (Ctrl+K ou /) ---------- */
const paleta = document.querySelector('[data-comandos]');
if (paleta) {
    const campo = paleta.querySelector('[data-comandos-campo]');
    const lista = paleta.querySelector('[data-comandos-lista]');
    const acoes = JSON.parse(paleta.querySelector('[data-comandos-acoes]').textContent);
    const icones = {};
    paleta.querySelector('[data-icones]').content.querySelectorAll('[data-ico]').forEach((s) => { icones[s.dataset.ico] = s.innerHTML; });
    let itens = [];
    let atual = 0;
    let espera;
    let pedido = 0;
    const semAcento = (s) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

    const desenhar = (grupos) => {
        lista.replaceChildren();
        itens = [];
        grupos.forEach(({ titulo, itens: doGrupo }) => {
            if (!doGrupo.length) { return; }
            const h = document.createElement('p');
            h.className = 'px-3 pt-3 pb-1 text-[11px] font-semibold tracking-wider text-zinc-400 uppercase';
            h.textContent = titulo;
            lista.append(h);
            doGrupo.forEach((it) => {
                const a = document.createElement('a');
                a.href = it.url;
                a.setAttribute('role', 'option');
                a.className = 'flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm text-zinc-700 dark:text-zinc-200';
                const ico = document.createElement('span');
                ico.className = 'grid size-8 shrink-0 place-items-center rounded-lg bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400';
                ico.innerHTML = icones[it.ico] ?? '';   // vem do próprio HTML da página, não do servidor
                const txt = document.createElement('span');
                txt.className = 'min-w-0 flex-1';
                const r = document.createElement('span');
                r.className = 'block truncate font-medium';
                r.textContent = it.rotulo;
                txt.append(r);
                if (it.detalhe) {
                    const d = document.createElement('span');
                    d.className = 'block truncate text-xs text-zinc-500';
                    d.textContent = it.detalhe;
                    txt.append(d);
                }
                a.append(ico, txt);
                a.addEventListener('mousemove', () => marcar(itens.indexOf(a)));
                lista.append(a);
                itens.push(a);
            });
        });
        if (!itens.length) {
            const v = document.createElement('p');
            v.className = 'px-3 py-10 text-center text-sm text-zinc-500';
            v.textContent = campo.value.trim().length >= 2 ? 'Nada encontrado.' : 'Digite para buscar.';
            lista.append(v);
        }
        marcar(0);
    };

    const marcar = (i) => {
        if (!itens.length) { return; }
        atual = (i + itens.length) % itens.length;
        itens.forEach((el, n) => {
            el.classList.toggle('bg-marca-50', n === atual);
            el.classList.toggle('dark:bg-zinc-800', n === atual);
            el.setAttribute('aria-selected', n === atual ? 'true' : 'false');
        });
        itens[atual].scrollIntoView({ block: 'nearest' });
    };

    const acoesFiltradas = () => {
        const t = semAcento(campo.value.trim());
        return acoes.filter((a) => !t || semAcento(a.rotulo).includes(t)).slice(0, t ? 5 : 8);
    };

    const atualizar = () => {
        clearTimeout(espera);
        const q = campo.value.trim();
        const locais = [{ titulo: 'Ações', itens: acoesFiltradas() }];
        desenhar(locais);
        if (q.length < 2) { return; }
        espera = setTimeout(async () => {
            const meu = ++pedido;
            try {
                const r = await fetch(paleta.dataset.urlBusca, {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ q }),
                });
                if (!r.ok || meu !== pedido) { return; }
                const dados = await r.json();
                desenhar([...dados.grupos, ...locais]);
            } catch (e) { /* sem rede: ficam só as ações */ }
        }, 200);
    };

    const abrir = () => {
        if (paleta.open) { return; }
        campo.value = '';
        atualizar();
        paleta.showModal();
        campo.focus();
    };

    document.querySelectorAll('[data-abrir-comandos]').forEach((b) => b.addEventListener('click', abrir));
    document.addEventListener('keydown', (ev) => {
        const emCampo = ev.target.closest?.('input, textarea, select, [contenteditable="true"]');
        if ((ev.key === 'k' || ev.key === 'K') && (ev.ctrlKey || ev.metaKey)) { ev.preventDefault(); abrir(); }
        else if (ev.key === '/' && !emCampo && !paleta.open) { ev.preventDefault(); abrir(); }
    });
    campo.addEventListener('input', atualizar);
    campo.addEventListener('keydown', (ev) => {
        if (ev.key === 'ArrowDown') { ev.preventDefault(); marcar(atual + 1); }
        else if (ev.key === 'ArrowUp') { ev.preventDefault(); marcar(atual - 1); }
        else if (ev.key === 'Enter' && itens[atual]) { ev.preventDefault(); window.location.href = itens[atual].href; }
    });
    paleta.addEventListener('click', (ev) => { if (ev.target === paleta) { paleta.close(); } });   // clique fora fecha
}

/* ---------- Confirmação antes de enviar (excluir, bloquear...) ---------- */
document.addEventListener('submit', (ev) => {
    const msg = ev.target.dataset?.confirmar;
    if (msg && !window.confirm(msg)) { ev.preventDefault(); }
});

/* ---------- Máscaras enquanto digita: data-mascara="doc|telefone|cep" (mesmas do sistema antigo) ---------- */
const digitos = (v) => String(v).replace(/\D+/g, '');
const mascaras = {
    doc: (v) => {
        const d = digitos(v).slice(0, 14);
        if (d.length <= 11) {
            return d.replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d{1,2})$/, '$1-$2');
        }
        return d.replace(/^(\d{2})(\d)/, '$1.$2').replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3')
            .replace(/\.(\d{3})(\d)/, '.$1/$2').replace(/(\d{4})(\d{1,2})$/, '$1-$2');
    },
    telefone: (v) => {
        const d = digitos(v).slice(0, 11);
        return d.length <= 10
            ? d.replace(/^(\d{2})(\d)/, '($1) $2').replace(/(\d{4})(\d{1,4})$/, '$1-$2')
            : d.replace(/^(\d{2})(\d)/, '($1) $2').replace(/(\d{5})(\d{1,4})$/, '$1-$2');
    },
    cep: (v) => digitos(v).slice(0, 8).replace(/^(\d{5})(\d)/, '$1-$2'),
};
document.querySelectorAll('[data-mascara]').forEach((el) => {
    const fn = mascaras[el.dataset.mascara];
    if (!fn) { return; }
    el.value = fn(el.value);
    el.addEventListener('input', () => { el.value = fn(el.value); });
});

/* ---------- Endereço pelo CEP (ViaCEP); não apaga o que já foi digitado com o CEP inválido ---------- */
document.querySelectorAll('[data-viacep]').forEach((campo) => {
    const status = campo.form?.closest('form')?.querySelector('[data-cep-status]') ?? document.querySelector('[data-cep-status]');
    let ultimo = digitos(campo.value);
    const buscar = async () => {
        const cep = digitos(campo.value);
        if (cep.length !== 8 || cep === ultimo) { return; }
        ultimo = cep;
        if (status) { status.textContent = 'Buscando o CEP…'; }
        try {
            const r = await fetch(`https://viacep.com.br/ws/${cep}/json/`);
            const j = await r.json();
            if (j.erro) { if (status) { status.textContent = 'CEP não encontrado: preencha o endereço.'; } return; }
            const f = campo.form;
            const mapa = { endereco: j.logradouro, bairro: j.bairro, cidade: j.localidade, uf: j.uf };
            Object.entries(mapa).forEach(([nome, valor]) => { if (f.elements[nome] && valor) { f.elements[nome].value = valor; } });
            if (status) { status.textContent = 'Endereço preenchido pelo CEP. Confira o número.'; }
            f.elements.numero?.focus();
        } catch (e) {
            if (status) { status.textContent = 'Sem conexão para consultar o CEP: preencha o endereço.'; }
        }
    };
    campo.addEventListener('input', () => { if (digitos(campo.value).length === 8) { buscar(); } });
    campo.addEventListener('blur', buscar);
});

/* ---------- Cliente: rótulos conforme pessoa física ou jurídica ---------- */
document.querySelectorAll('[data-form-cliente]').forEach((form) => {
    const atualizar = () => {
        const pj = form.querySelector('[data-tipo]:checked')?.value === 'PJ';
        const nome = form.querySelector('[data-rotulo-nome]');
        if (nome) { nome.firstChild.textContent = pj ? 'Razão social ' : 'Nome completo '; }
        const doc = form.querySelector('[data-rotulo-doc]');
        if (doc) { doc.textContent = pj ? 'CNPJ' : 'CPF'; }
        const ie = form.querySelector('[data-rotulo-ie]');
        if (ie) { ie.textContent = pj ? 'Inscrição estadual' : 'RG'; }
    };
    form.querySelectorAll('[data-tipo]').forEach((r) => r.addEventListener('change', atualizar));
});
