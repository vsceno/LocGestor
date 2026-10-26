/*
 * LocGestor - registra o service worker (sw.js, na raiz do sistema), que deixa o sistema
 * ser instalado como aplicativo. Só funciona em HTTPS (ou em localhost).
 */
'use strict';

if ('serviceWorker' in navigator && window.isSecureContext) {
    window.addEventListener('load', function () {
        navigator.serviceWorker.register('sw.js').catch(function () { /* sem PWA: o sistema funciona igual */ });
    });
}
