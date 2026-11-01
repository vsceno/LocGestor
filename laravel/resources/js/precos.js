/*
 * Preço por período: cópia de LG.precoPeriodo/precoPlano do sistema antigo (assets/js/app.js), que espelham
 * preco_periodo()/preco_plano() do banco e de app/negocio.php. Aqui só serve para mostrar o total enquanto se
 * digita: o valor que vale é o que o banco calcula. Mude todas juntas.
 */
export function precoPeriodo(dias, diaria, semanal, mensal) {
    const d = Math.max(1, dias), D = diaria || 0, W = semanal || 0, M = mensal || 0;
    const m = M > 0 ? Math.floor(d / 30) : 0, r = M > 0 ? d % 30 : d;
    const s = W > 0 ? Math.floor(r / 7) * W + Math.min((r % 7) * D, W) : r * D;
    return Math.round(Math.min(d * D, m * M + (M > 0 ? Math.min(s, M) : s)) * 100) / 100;
}

export function precoPlano(dias, periodo, precos) {
    const d = Math.max(1, dias), D = precos.diaria || 0;
    const bloco = { semanal: 7, quinzenal: 15, mensal: 30 }[periodo] || 0, preco = precos[periodo] || 0;
    if (!bloco || preco <= 0) { return Math.round(d * D * 100) / 100; }
    return Math.round((Math.floor(d / bloco) * preco + (d % bloco) * D) * 100) / 100;
}

export function precoItem(dias, periodo, precos) {
    if (!periodo || periodo === 'automatico') { return precoPeriodo(dias, precos.diaria, precos.semanal, precos.mensal); }
    return precoPlano(dias, periodo, precos);
}

/** Diferença em dias entre datas aaaa-mm-dd (sem fuso). */
export function dias(de, ate) {
    if (!de || !ate) { return 0; }
    const a = de.split('-'), b = ate.split('-');
    return Math.round((Date.UTC(b[0], b[1] - 1, b[2]) - Date.UTC(a[0], a[1] - 1, a[2])) / 86400000);
}

/** Dias cobrados: conta o dia da saída (27/10 a 05/11 = 10 dias). */
export const diasCobranca = (de, ate) => Math.max(1, dias(de, ate) + 1);

/** Data aaaa-mm-dd somada de N dias (sem fuso). */
export function somarDias(de, n) {
    const a = de.split('-'), d = new Date(Date.UTC(a[0], a[1] - 1, parseInt(a[2], 10) + n));
    return `${d.getUTCFullYear()}-${String(d.getUTCMonth() + 1).padStart(2, '0')}-${String(d.getUTCDate()).padStart(2, '0')}`;
}

/** "1.234,56" -> 1234.56 (aceita "1234.56"). */
export function numero(s) {
    s = String(s || '').replace(/[R$\s]/g, '');
    if (s.includes(',')) { s = s.replace(/\./g, '').replace(',', '.'); }
    const n = parseFloat(s);
    return Number.isNaN(n) ? 0 : n;
}

export const moeda = (n) => n.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
export const decimal = (n) => n.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
