/*
 * LocGestor - desenha os gráficos com o Chart.js (assets/vendor/chart.umd.js, local).
 * Cada <canvas data-grafico='{"tipo","rotulos","series":[{"nome","cor","dados"}],"moeda","horizontal"}'>
 */
'use strict';

document.addEventListener('DOMContentLoaded', function () {
    if (typeof Chart === 'undefined') { return; }

    Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
    Chart.defaults.color = '#5f6676';

    var moeda = function (v) { return Number(v).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }); };
    var moedaCurta = function (v) {
        v = Number(v);
        if (Math.abs(v) >= 1000) { return 'R$ ' + (v / 1000).toLocaleString('pt-BR', { maximumFractionDigits: 1 }) + ' mil'; }
        return moeda(v);
    };

    document.querySelectorAll('canvas[data-grafico]').forEach(function (cv) {
        var cfg = JSON.parse(cv.getAttribute('data-grafico'));
        var linha = cfg.tipo === 'line';
        var eixoValor = cfg.horizontal ? 'x' : 'y';
        var escalaValor = {
            beginAtZero: true,
            grid: { color: '#eef0f4' },
            ticks: { callback: function (v) { return cfg.moeda ? moedaCurta(v) : Number(v).toLocaleString('pt-BR'); } }
        };
        if (!cfg.moeda) { escalaValor.ticks.precision = 0; }
        var escalas = {};
        escalas[eixoValor] = escalaValor;
        escalas[cfg.horizontal ? 'y' : 'x'] = { grid: { display: false } };

        new Chart(cv, {
            type: cfg.tipo,
            data: {
                labels: cfg.rotulos,
                datasets: cfg.series.map(function (s) {
                    return {
                        label: s.nome,
                        data: s.dados,
                        backgroundColor: linha ? s.cor + '22' : s.cor,
                        borderColor: s.cor,
                        borderWidth: linha ? 2 : 0,
                        borderRadius: linha ? 0 : 3,
                        fill: linha,
                        tension: 0.3,
                        pointRadius: linha ? 3 : 0,
                        maxBarThickness: 28
                    };
                })
            },
            options: {
                indexAxis: cfg.horizontal ? 'y' : 'x',
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: cfg.series.length > 1, position: 'bottom' },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                var v = cfg.horizontal ? ctx.parsed.x : ctx.parsed.y;
                                return ' ' + ctx.dataset.label + ': ' + (cfg.moeda ? moeda(v) : Number(v).toLocaleString('pt-BR'));
                            }
                        }
                    }
                },
                scales: escalas
            }
        });
    });
});
