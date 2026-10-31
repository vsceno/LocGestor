/* LocGestor - desenha o QR Code do PIX e copia o "copia e cola" (usa assets/vendor/qrcode.js) */
'use strict';

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-pix]').forEach(function (bloco) {
        var codigo = bloco.getAttribute('data-pix');
        var alvo = bloco.querySelector('.pix-qr');
        if (typeof qrcode === 'function' && alvo) {
            var qr = qrcode(0, 'M');   // tamanho automático, correção de erro média
            qr.addData(codigo);
            qr.make();
            alvo.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 2, scalable: true });
        }

        var botao = bloco.querySelector('.pix-copiar');
        var campo = bloco.querySelector('.pix-codigo');
        if (!botao || !campo) { return; }
        botao.addEventListener('click', function () {
            var avisar = function () {
                botao.textContent = 'Copiado!';
                setTimeout(function () { botao.textContent = 'Copiar código'; }, 2000);
            };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(codigo).then(avisar);
            } else {
                // http sem certificado: o navegador não libera a área de transferência moderna
                campo.select();
                document.execCommand('copy');
                avisar();
            }
        });
    });
});
