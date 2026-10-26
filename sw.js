/*
 * LocGestor - service worker (instalação como aplicativo).
 *
 * Segurança: guarda em cache SÓ arquivos estáticos (assets/ e a página "sem conexão").
 * Páginas com dados nunca são guardadas: num celular compartilhado, nada de uma locadora
 * ou de um cliente fica disponível depois de sair do sistema. Sem internet, as telas
 * mostram offline.html.
 */
'use strict';

const CACHE = 'locgestor-estaticos-v1';
const OFFLINE = 'offline.html';
const PRE = [OFFLINE, 'assets/icons/icon-192.png'];

self.addEventListener('install', function (ev) {
    ev.waitUntil(caches.open(CACHE).then(function (c) { return c.addAll(PRE); }).then(function () { return self.skipWaiting(); }));
});

self.addEventListener('activate', function (ev) {
    ev.waitUntil(caches.keys().then(function (nomes) {
        return Promise.all(nomes.filter(function (n) { return n !== CACHE; }).map(function (n) { return caches.delete(n); }));
    }).then(function () { return self.clients.claim(); }));
});

self.addEventListener('fetch', function (ev) {
    const req = ev.request;
    if (req.method !== 'GET') { return; }
    const url = new URL(req.url);
    if (url.origin !== self.location.origin) { return; }
    const base = new URL('./', self.registration.scope).pathname;

    // Estáticos versionados (?v=data do arquivo): cache primeiro; a versão nova substitui a antiga
    if (url.pathname.startsWith(base + 'assets/')) {
        ev.respondWith(caches.open(CACHE).then(function (c) {
            return c.match(req).then(function (achou) {
                if (achou) { return achou; }
                return fetch(req).then(function (resp) {
                    if (resp.ok) {
                        c.keys().then(function (chaves) {
                            chaves.forEach(function (k) {
                                const u = new URL(k.url);
                                if (u.pathname === url.pathname && u.search !== url.search) { c.delete(k); }
                            });
                        });
                        c.put(req, resp.clone());
                    }
                    return resp;
                });
            });
        }));
        return;
    }

    // Páginas: sempre da rede (nunca do cache); sem conexão, a página de aviso
    if (req.mode === 'navigate') {
        ev.respondWith(fetch(req).catch(function () { return caches.match(OFFLINE); }));
    }
});
