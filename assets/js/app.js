/* LocGestor - comportamentos comuns a todas as telas */
'use strict';

var LG = {
    digitos: function (s) { return String(s || '').replace(/\D/g, ''); },

    // "1.234,56" -> 1234.56 (também aceita "1234.56")
    numero: function (s) {
        s = String(s || '').replace(/[R$\s]/g, '');
        if (s.indexOf(',') >= 0) { s = s.replace(/\./g, '').replace(',', '.'); }
        var n = parseFloat(s);
        return isNaN(n) ? 0 : n;
    },
    moeda: function (n) { return n.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }); },
    decimal: function (n) { return n.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },

    // Preço de 1 unidade por N dias: mesma regra de preco_periodo() (banco e app/negocio.php)
    precoPeriodo: function (dias, diaria, semanal, mensal) {
        var d = Math.max(1, dias), D = diaria || 0, W = semanal || 0, M = mensal || 0;
        var m = M > 0 ? Math.floor(d / 30) : 0, r = M > 0 ? d % 30 : d;
        var s = W > 0 ? Math.floor(r / 7) * W + Math.min((r % 7) * D, W) : r * D;
        return Math.round(Math.min(d * D, m * M + (M > 0 ? Math.min(s, M) : s)) * 100) / 100;
    },

    // Diferença em dias entre duas datas aaaa-mm-dd (sem fuso horário)
    dias: function (de, ate) {
        if (!de || !ate) { return 0; }
        var a = de.split('-'), b = ate.split('-');
        return Math.round((Date.UTC(b[0], b[1] - 1, b[2]) - Date.UTC(a[0], a[1] - 1, a[2])) / 86400000);
    },

    // -----------------------------------------------------------------
    // Nova locação / reserva (form[data-itens]): itens dinâmicos, disponibilidade
    // em tempo real e total previsto. Acréscimo, desconto e total são opcionais.
    // -----------------------------------------------------------------
    novaLocacao: function (form) {
        var vazio = { value: '', textContent: '', addEventListener: function () {} };
        var campo = function (sel) { return form.querySelector(sel) || vazio; };
        var corpo = form.querySelector('#tabela-itens tbody');
        var modelo = document.getElementById('modelo-linha');
        var saida = form.querySelector('#data_saida'), prev = form.querySelector('#data_prev');
        var api = form.getAttribute('data-api');
        var reserva = form.querySelector('#reserva_id').value;
        var cache = {}, espera = null;

        function diarias() { return Math.max(1, LG.dias(saida.value, prev.value)); }

        function recalcular() {
            var d = diarias(), soma = 0;
            corpo.querySelectorAll('tr.linha-item').forEach(function (tr) {
                var qtd = parseInt(tr.querySelector('.qtd').value, 10) || 0;
                var opt = tr.querySelector('.sel-produto').selectedOptions[0] || { dataset: {} };
                var sub = qtd * LG.precoPeriodo(d, LG.numero(tr.querySelector('.diaria').value),
                                                parseFloat(opt.dataset.semanal), parseFloat(opt.dataset.mensal));
                soma += sub;
                tr.querySelector('.subtotal').textContent = tr.querySelector('.sel-produto').value ? LG.moeda(sub) : '—';
            });
            var total = soma + LG.numero(campo('#acrescimo').value) - LG.numero(campo('#desconto').value);
            campo('#total-previsto').textContent = LG.moeda(total);
            campo('#total-detalhe').textContent = '(' + d + ' dia' + (d > 1 ? 's' : '') + ')';
            var info = form.querySelector('#info-dias');
            info.textContent = LG.dias(saida.value, prev.value) < 0 ? 'A devolução não pode ser antes da saída.' : d + ' dia(s)';
            info.className = 'ajuda' + (LG.dias(saida.value, prev.value) < 0 ? ' alerta' : '');
            avisarEstoque();
        }

        // Compara a soma pedida de cada produto (linhas repetidas somam) com o disponível
        function avisarEstoque() {
            var pedido = {};
            corpo.querySelectorAll('tr.linha-item').forEach(function (tr) {
                var p = tr.querySelector('.sel-produto').value;
                if (p) { pedido[p] = (pedido[p] || 0) + (parseInt(tr.querySelector('.qtd').value, 10) || 0); }
            });
            corpo.querySelectorAll('tr.linha-item').forEach(function (tr) {
                var p = tr.querySelector('.sel-produto').value, cel = tr.querySelector('.disp');
                var chave = p + '|' + saida.value + '|' + prev.value;
                if (!p) { cel.textContent = '—'; cel.className = 'num disp'; return; }
                if (cache[chave] === undefined) { cel.textContent = '...'; return; }
                var disp = cache[chave];
                var falta = pedido[p] > disp;
                cel.innerHTML = '';
                var s = document.createElement('span');
                s.className = 'selo ' + (falta ? 'vermelho' : 'verde');
                s.textContent = disp;
                cel.appendChild(s);
                if (falta) {
                    var aviso = document.createElement('div');
                    aviso.className = 'alerta';
                    aviso.style.fontSize = '.78rem';
                    aviso.textContent = 'pedido ' + pedido[p] + ', disponível ' + disp;
                    cel.appendChild(aviso);
                }
            });
        }

        function consultar() {
            if (!saida.value || !prev.value || LG.dias(saida.value, prev.value) < 0) { return; }
            var pendentes = [];
            corpo.querySelectorAll('.sel-produto').forEach(function (sel) {
                var chave = sel.value + '|' + saida.value + '|' + prev.value;
                if (sel.value && cache[chave] === undefined && pendentes.indexOf(sel.value) < 0) { pendentes.push(sel.value); }
            });
            pendentes.forEach(function (p) {
                var chave = p + '|' + saida.value + '|' + prev.value;
                var u = api + '&produto=' + encodeURIComponent(p) + '&inicio=' + saida.value + '&fim=' + prev.value
                        + (reserva ? '&reserva=' + encodeURIComponent(reserva) : '');
                fetch(u, { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (j) { if (j.ok) { cache[chave] = j.disponivel; avisarEstoque(); } })
                    .catch(function () { /* sem rede: o servidor confere ao salvar */ });
            });
            avisarEstoque();
        }

        function agendarConsulta() { clearTimeout(espera); espera = setTimeout(consultar, 250); }

        function prepararLinha(tr) {
            var sel = tr.querySelector('.sel-produto'), diaria = tr.querySelector('.diaria');
            diaria.dataset.auto = diaria.value === '' ? '1' : '';
            sel.addEventListener('change', function () {
                var opt = sel.options[sel.selectedIndex];
                // Preenche a diária do cadastro, a menos que o usuário tenha digitado outra
                if (opt && opt.dataset.diaria && (diaria.value === '' || diaria.dataset.auto === '1')) {
                    diaria.value = LG.decimal(parseFloat(opt.dataset.diaria));
                    diaria.dataset.auto = '1';
                }
                recalcular(); agendarConsulta();
            });
            diaria.addEventListener('input', function () { diaria.dataset.auto = ''; recalcular(); });
            tr.querySelector('.qtd').addEventListener('input', recalcular);
            tr.querySelector('.remover').addEventListener('click', function () {
                if (corpo.querySelectorAll('tr.linha-item').length > 1) { tr.remove(); } else {
                    sel.value = ''; diaria.value = ''; tr.querySelector('.qtd').value = 1;
                }
                recalcular();
            });
        }

        corpo.querySelectorAll('tr.linha-item').forEach(prepararLinha);
        form.querySelector('#add-item').addEventListener('click', function () {
            var tr = modelo.content.firstElementChild.cloneNode(true);
            corpo.appendChild(tr);
            prepararLinha(tr);
            tr.querySelector('.sel-produto').focus();
            recalcular();
        });
        // Kit: cada produto do kit soma na linha que já tem o produto, ou ocupa uma linha vazia/nova
        var kitSel = form.querySelector('#kit-sel');
        if (kitSel) {
            form.querySelector('#add-kit').addEventListener('click', function () {
                var opt = kitSel.selectedOptions[0];
                if (!opt || !opt.value) { kitSel.focus(); return; }
                var vezes = Math.max(1, parseInt(form.querySelector('#kit-qtd').value, 10) || 1);
                JSON.parse(opt.dataset.itens).forEach(function (c) {
                    var linhas = Array.prototype.slice.call(corpo.querySelectorAll('tr.linha-item'));
                    var tr = linhas.filter(function (x) { return x.querySelector('.sel-produto').value === c.p; })[0];
                    if (tr) {
                        var q = tr.querySelector('.qtd');
                        q.value = (parseInt(q.value, 10) || 0) + c.q * vezes;
                        return;
                    }
                    tr = linhas.filter(function (x) { return x.querySelector('.sel-produto').value === ''; })[0];
                    if (!tr) { tr = modelo.content.firstElementChild.cloneNode(true); corpo.appendChild(tr); prepararLinha(tr); }
                    tr.querySelector('.qtd').value = c.q * vezes;
                    var sel = tr.querySelector('.sel-produto');
                    sel.value = c.p;
                    sel.dispatchEvent(new Event('change'));
                });
                kitSel.value = '';
                form.querySelector('#kit-qtd').value = 1;
                recalcular(); agendarConsulta();
            });
        }
        [saida, prev].forEach(function (el) { el.addEventListener('change', function () { recalcular(); agendarConsulta(); }); });
        ['#acrescimo', '#desconto'].forEach(function (s) { campo(s).addEventListener('input', recalcular); });
        recalcular();
        consultar();
    },

    // -----------------------------------------------------------------
    // Devolução: dias e atraso pela data, valores e resumo ao vivo
    // -----------------------------------------------------------------
    devolucao: function (form) {
        var data = form.querySelector('#data_dev');
        var receber = form.querySelector('#receber');
        var apurado = parseFloat(form.dataset.apurado) || 0;
        var ajuste = (parseFloat(form.dataset.acrescimo) || 0) - (parseFloat(form.dataset.desconto) || 0);
        var pago = parseFloat(form.dataset.pago) || 0;
        var receberEditado = false;

        function recalcular() {
            var dias = Math.max(1, LG.dias(form.dataset.saida, data.value));
            var atraso = LG.dias(form.dataset.prev, data.value);
            form.querySelector('#dev-dias').textContent = dias;
            form.querySelector('#dev-atraso').textContent = atraso > 0 ? '(' + atraso + ' dia(s) de atraso)' : '';
            var itens = 0, ficam = 0;
            form.querySelectorAll('tr.linha-dev').forEach(function (tr) {
                var inp = tr.querySelector('.qtd-dev');
                var q = Math.min(parseInt(inp.value, 10) || 0, parseInt(inp.max, 10));
                var v = q * LG.precoPeriodo(dias, parseFloat(tr.dataset.diaria), parseFloat(tr.dataset.semanal), parseFloat(tr.dataset.mensal));
                itens += v;
                ficam += parseInt(inp.max, 10) - q;
                tr.querySelector('.valor-dev').textContent = LG.moeda(v);
            });
            var desta = itens + LG.numero(form.querySelector('#multa').value) + LG.numero(form.querySelector('#avaria').value);
            var total = apurado + desta + ajuste;
            var saldo = total - pago;
            form.querySelector('#r-desta').textContent = LG.moeda(desta);
            form.querySelector('#r-total').textContent = LG.moeda(total);
            form.querySelector('#r-saldo').textContent = LG.moeda(saldo);
            form.querySelector('#r-pendente-aviso').textContent = ficam > 0
                ? ficam + ' item(ns) continuarão na rua e serão cobrados na próxima devolução.' : '';
            if (!receberEditado) { receber.value = saldo > 0 ? LG.decimal(saldo) : ''; }
        }

        receber.addEventListener('input', function () { receberEditado = true; });
        form.querySelectorAll('input').forEach(function (el) {
            if (el !== receber) { el.addEventListener('input', recalcular); el.addEventListener('change', recalcular); }
        });
        recalcular();
    },

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

    document.querySelectorAll('form[data-itens]').forEach(LG.novaLocacao);
    if (document.getElementById('form-devolucao')) { LG.devolucao(document.getElementById('form-devolucao')); }

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
