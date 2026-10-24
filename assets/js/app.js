/* LocGestor - comportamentos comuns a todas as telas */
'use strict';

var LG = {
    digitos: function (s) { return String(s || '').replace(/\D/g, ''); },

    // Máscaras aplicadas enquanto o usuário digita
    mascaras: {
        doc: function (v) {
            var d = LG.digitos(v).slice(0, 14);
            if (d.length <= 11) {
                return d.replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d{1,2})$/, '$1-$2');
            }
            return d.replace(/^(\d{2})(\d)/, '$1.$2').replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3')
                    .replace(/\.(\d{3})(\d)/, '.$1/$2').replace(/(\d{4})(\d{1,2})$/, '$1-$2');
        },
        telefone: function (v) {
            var d = LG.digitos(v).slice(0, 11);
            if (d.length <= 10) {
                return d.replace(/^(\d{2})(\d)/, '($1) $2').replace(/(\d{4})(\d{1,4})$/, '$1-$2');
            }
            return d.replace(/^(\d{2})(\d)/, '($1) $2').replace(/(\d{5})(\d{1,4})$/, '$1-$2');
        },
        cep: function (v) {
            return LG.digitos(v).slice(0, 8).replace(/^(\d{5})(\d)/, '$1-$2');
        }
    },

    // Preenche o endereço pelo CEP (ViaCEP) sem apagar o que o usuário já digitou
    buscarCep: function (campo) {
        var cep = LG.digitos(campo.value);
        var status = document.getElementById('cep-status');
        if (cep.length !== 8) { return; }
        var form = campo.form;
        if (status) { status.textContent = 'Buscando CEP...'; }
        fetch('https://viacep.com.br/ws/' + cep + '/json/')
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j.erro) {
                    if (status) { status.textContent = 'CEP não encontrado. Preencha o endereço manualmente.'; }
                    return;
                }
                var mapa = { endereco: j.logradouro, bairro: j.bairro, cidade: j.localidade, uf: j.uf };
                Object.keys(mapa).forEach(function (nome) {
                    var el = form.elements[nome];
                    if (el && mapa[nome]) { el.value = mapa[nome]; }
                });
                if (status) { status.textContent = 'Endereço preenchido pelo CEP. Confira o número.'; }
                if (form.elements.numero) { form.elements.numero.focus(); }
            })
            .catch(function () {
                if (status) { status.textContent = 'Não foi possível consultar o CEP agora.'; }
            });
    }
};

document.addEventListener('DOMContentLoaded', function () {
    // Formulários com data-confirmar pedem confirmação antes de enviar
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

    // Máscaras: <input data-mascara="doc|telefone|cep">
    document.querySelectorAll('[data-mascara]').forEach(function (el) {
        var fn = LG.mascaras[el.getAttribute('data-mascara')];
        if (!fn) { return; }
        el.value = fn(el.value);
        el.addEventListener('input', function () { el.value = fn(el.value); });
    });

    // CEP com busca no ViaCEP ao sair do campo
    document.querySelectorAll('[data-viacep]').forEach(function (el) {
        el.addEventListener('blur', function () { LG.buscarCep(el); });
    });

    // Cadastro de cliente: rótulos mudam conforme PF/PJ
    var tipo = document.getElementById('tipo');
    if (tipo) {
        var atualizar = function () {
            var pj = tipo.value === 'PJ';
            var doc = document.querySelector('[name="cpf_cnpj"]');
            var rg = document.querySelector('[name="rg_ie"]');
            if (doc) { doc.parentNode.firstChild.textContent = pj ? 'CNPJ' : 'CPF'; }
            if (rg) { rg.parentNode.firstChild.textContent = pj ? 'Inscrição estadual' : 'RG'; }
        };
        tipo.addEventListener('change', atualizar);
    }
});
