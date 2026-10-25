/* LocGestor - vistoria: assinatura na tela e fotos reduzidas no navegador antes do envio */
'use strict';

document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('form-vistoria');
    if (!form) { return; }

    // -----------------------------------------------------------------
    // Assinatura com o dedo (celular/tablet) ou o mouse
    // -----------------------------------------------------------------
    var pad = document.getElementById('assinatura-pad');
    var ctx = pad.getContext('2d');
    var campo = document.getElementById('assinatura');
    var desenhando = false, tracou = false, ultimo = null;

    ctx.lineWidth = 3;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.strokeStyle = '#111827';

    function ponto(ev) {
        var r = pad.getBoundingClientRect();
        // O canvas tem resolução fixa; a tela pode mostrá-lo menor ou maior
        return { x: (ev.clientX - r.left) * pad.width / r.width, y: (ev.clientY - r.top) * pad.height / r.height };
    }
    pad.addEventListener('pointerdown', function (ev) {
        ev.preventDefault();
        pad.setPointerCapture(ev.pointerId);
        desenhando = true;
        ultimo = ponto(ev);
    });
    pad.addEventListener('pointermove', function (ev) {
        if (!desenhando) { return; }
        ev.preventDefault();
        var p = ponto(ev);
        ctx.beginPath();
        ctx.moveTo(ultimo.x, ultimo.y);
        ctx.lineTo(p.x, p.y);
        ctx.stroke();
        ultimo = p;
        tracou = true;
    });
    ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (t) {
        pad.addEventListener(t, function () { desenhando = false; });
    });
    document.getElementById('assinatura-limpar').addEventListener('click', function () {
        ctx.clearRect(0, 0, pad.width, pad.height);
        tracou = false;
        campo.value = '';
    });

    // -----------------------------------------------------------------
    // Fotos: reduz para no máximo 1600px (JPEG ~80%) antes de enviar e mostra miniaturas.
    // Assim cabe no limite de upload de qualquer servidor e envia rápido pelo celular.
    // -----------------------------------------------------------------
    var entrada = document.getElementById('fotos');
    var previa = document.getElementById('fotos-previa');
    var LADO = 1600, MAX = 12;

    function reduzir(arquivo) {
        return new Promise(function (ok) {
            var img = new Image();
            var url = URL.createObjectURL(arquivo);
            img.onload = function () {
                var esc = Math.min(1, LADO / Math.max(img.width, img.height));
                var c = document.createElement('canvas');
                c.width = Math.round(img.width * esc);
                c.height = Math.round(img.height * esc);
                c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
                URL.revokeObjectURL(url);
                c.toBlob(function (blob) {
                    ok(blob ? new File([blob], arquivo.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }) : arquivo);
                }, 'image/jpeg', 0.8);
            };
            img.onerror = function () { URL.revokeObjectURL(url); ok(arquivo); }; // o servidor confere e avisa
            img.src = url;
        });
    }

    entrada.addEventListener('change', function () {
        var lista = Array.prototype.slice.call(entrada.files || []);
        if (lista.length > MAX) {
            alert('Escolha no máximo ' + MAX + ' fotos.');
            entrada.value = '';
            previa.innerHTML = '';
            return;
        }
        previa.textContent = lista.length ? 'Preparando fotos...' : '';
        Promise.all(lista.map(reduzir)).then(function (reduzidas) {
            if (typeof DataTransfer !== 'undefined') {
                try {
                    var dt = new DataTransfer();
                    reduzidas.forEach(function (f) { dt.items.add(f); });
                    entrada.files = dt.files; // envia as versões reduzidas
                } catch (e) { /* navegador antigo: envia as originais, o servidor reduz */ }
            }
            previa.innerHTML = '';
            reduzidas.forEach(function (f) {
                var im = document.createElement('img');
                im.src = URL.createObjectURL(f);
                im.alt = f.name;
                previa.appendChild(im);
            });
        });
    });

    form.addEventListener('submit', function () {
        // Assinatura em PNG (o servidor regrava e confere se não está em branco)
        campo.value = tracou ? pad.toDataURL('image/png') : '';
        var b = document.getElementById('vistoria-salvar');
        b.disabled = true;
        b.textContent = 'Enviando...';
    });
});
