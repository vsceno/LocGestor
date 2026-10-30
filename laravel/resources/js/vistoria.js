/* Vistoria: assinatura na tela (dedo ou mouse) e fotos reduzidas no navegador antes do envio. Mesmo comportamento do sistema antigo. */

export function vistoria(form) {
    const pad = form.querySelector('[data-assinatura-pad]');
    const campo = form.querySelector('[data-assinatura-campo]');
    const entrada = form.querySelector('[data-fotos]');
    const previa = form.querySelector('[data-fotos-previa]');
    const salvar = form.querySelector('[data-salvar]');
    const LADO = 1600, MAX = 12;

    // -----------------------------------------------------------------
    // Assinatura com o dedo (celular/tablet) ou o mouse
    // -----------------------------------------------------------------
    const ctx = pad.getContext('2d');
    let desenhando = false, tracou = false, ultimo = null;
    ctx.lineWidth = 3;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.strokeStyle = '#111827';

    // O canvas tem resolução fixa; a tela pode mostrá-lo menor ou maior
    const ponto = (ev) => {
        const r = pad.getBoundingClientRect();
        return { x: (ev.clientX - r.left) * pad.width / r.width, y: (ev.clientY - r.top) * pad.height / r.height };
    };
    pad.addEventListener('pointerdown', (ev) => {
        ev.preventDefault();
        pad.setPointerCapture(ev.pointerId);
        desenhando = true;
        ultimo = ponto(ev);
    });
    pad.addEventListener('pointermove', (ev) => {
        if (!desenhando) { return; }
        ev.preventDefault();
        const p = ponto(ev);
        ctx.beginPath();
        ctx.moveTo(ultimo.x, ultimo.y);
        ctx.lineTo(p.x, p.y);
        ctx.stroke();
        ultimo = p;
        tracou = true;
    });
    ['pointerup', 'pointercancel', 'pointerleave'].forEach((t) => pad.addEventListener(t, () => { desenhando = false; }));
    form.querySelector('[data-assinatura-limpar]').addEventListener('click', () => {
        ctx.clearRect(0, 0, pad.width, pad.height);
        tracou = false;
        campo.value = '';
    });

    // -----------------------------------------------------------------
    // Fotos: reduz para no máximo 1600 px (JPEG ~80%) antes de enviar e mostra miniaturas
    // -----------------------------------------------------------------
    function reduzir(arquivo) {
        return new Promise((ok) => {
            const img = new Image();
            const url = URL.createObjectURL(arquivo);
            img.onload = () => {
                const esc = Math.min(1, LADO / Math.max(img.width, img.height));
                const c = document.createElement('canvas');
                c.width = Math.round(img.width * esc);
                c.height = Math.round(img.height * esc);
                c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
                URL.revokeObjectURL(url);
                c.toBlob((blob) => {
                    ok(blob ? new File([blob], arquivo.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }) : arquivo);
                }, 'image/jpeg', 0.8);
            };
            img.onerror = () => { URL.revokeObjectURL(url); ok(arquivo); };   // o servidor confere e avisa
            img.src = url;
        });
    }

    entrada.addEventListener('change', () => {
        const lista = [...(entrada.files || [])];
        if (lista.length > MAX) {
            alert(`Escolha no máximo ${MAX} fotos.`);
            entrada.value = '';
            previa.innerHTML = '';
            return;
        }
        previa.textContent = lista.length ? 'Preparando fotos...' : '';
        Promise.all(lista.map(reduzir)).then((reduzidas) => {
            try {
                const dt = new DataTransfer();
                reduzidas.forEach((f) => dt.items.add(f));
                entrada.files = dt.files;   // envia as versões reduzidas
            } catch (e) { /* navegador antigo: envia as originais, o servidor reduz */ }
            previa.innerHTML = '';
            reduzidas.forEach((f) => {
                const im = document.createElement('img');
                im.src = URL.createObjectURL(f);
                im.alt = f.name;
                im.className = 'h-24 w-full rounded-lg object-cover';
                previa.append(im);
            });
        });
    });

    form.addEventListener('submit', () => {
        // Assinatura em PNG: o servidor regrava e confere se não está em branco
        campo.value = tracou ? pad.toDataURL('image/png') : '';
        salvar.disabled = true;
        salvar.textContent = 'Enviando...';
    });
}
