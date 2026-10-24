/* LocGestor - comportamentos comuns a todas as telas */
'use strict';

document.addEventListener('DOMContentLoaded', function () {
    // Formulários e botões com data-confirmar pedem confirmação antes de enviar
    document.addEventListener('submit', function (ev) {
        var msg = ev.target.getAttribute('data-confirmar');
        if (msg && !window.confirm(msg)) {
            ev.preventDefault();
        }
    });

    // Fecha a gaveta do menu (celular) ao escolher um item
    var toggle = document.getElementById('menu-toggle');
    if (toggle) {
        document.querySelectorAll('.menu a').forEach(function (a) {
            a.addEventListener('click', function () { toggle.checked = false; });
        });
    }
});
