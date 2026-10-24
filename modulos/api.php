<?php
/*
 * API interna (JSON) usada pelas telas. Exige login como qualquer outra página;
 * os dados já vêm filtrados pelo RLS da locadora logada.
 */

declare(strict_types=1);

function api_json(array $dados, int $codigo = 200): never
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($dados, JSON_UNESCAPED_UNICODE);
    exit;
}

/** index.php?p=api&a=disponivel&produto=<uuid>&inicio=aaaa-mm-dd&fim=aaaa-mm-dd[&reserva=<uuid>] */
function api_disponivel(): void
{
    $produto = get('produto');
    $inicio = get('inicio');
    $fim = get('fim');
    $reserva = get('reserva');
    if (!is_uuid($produto) || !valid_date($inicio) || !valid_date($fim) || $fim < $inicio
        || ($reserva !== '' && !is_uuid($reserva))) {
        api_json(['ok' => false, 'erro' => 'Parâmetros inválidos.'], 400);
    }
    $p = one('SELECT descricao, qtd_total, valor_diaria FROM produtos WHERE id = :id', ['id' => $produto]);
    if ($p === null) {
        api_json(['ok' => false, 'erro' => 'Produto não encontrado.'], 404);
    }
    api_json([
        'ok'         => true,
        'disponivel' => disponivel($produto, $inicio, $fim, $reserva === '' ? null : $reserva),
        'estoque'    => (int) $p['qtd_total'],
        'diaria'     => (float) $p['valor_diaria'],
        'descricao'  => $p['descricao'],
    ]);
}
