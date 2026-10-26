<?php
/*
 * LocGestor - layout das telas (menu lateral) e das páginas de impressão (A4).
 */

declare(strict_types=1);

const MENU = [
    'dashboard'  => ['Painel',         'painel'],
    'locacoes'   => ['Locações',       'locacoes'],
    'reservas'   => ['Reservas',       'reservas'],
    'clientes'   => ['Clientes',       'clientes'],
    'produtos'   => ['Produtos',       'produtos'],
    'manutencoes' => ['Manutenção',    'manutencao'],
    'caixa'      => ['Fluxo de caixa', 'caixa'],
    'relatorios' => ['Relatórios',     'relatorios'],
    'config'     => ['Configurações',  'config'],
];
const MENU_PLATAFORMA = [
    'tenants' => ['Locadoras', 'produtos'],
    'planos'  => ['Planos', 'caixa'],
];

/** Ícones SVG inline (traço), 24x24. */
function icone(string $nome): string
{
    $p = [
        'painel'     => '<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>',
        'locacoes'   => '<path d="M1 4h14v12H1z"/><path d="M15 8h4l3 3v5h-7z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>',
        'reservas'   => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        'clientes'   => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        'produtos'   => '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="M3.3 7 12 12l8.7-5M12 22V12"/>',
        'manutencao' => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
        'caixa'      => '<path d="M12 1v22M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
        'relatorios' => '<path d="M12 20V10M18 20V4M6 20v-4"/>',
        'config'     => '<path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6"/>',
        'sair'       => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
        'menu'       => '<path d="M3 12h18M3 6h18M3 18h18"/>',
        'mensagem'   => '<path d="M21 11.5a8.4 8.4 0 0 1-12.4 7.4L3 21l2.1-5.6A8.4 8.4 0 1 1 21 11.5z"/>',
    ];
    return '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
         . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$nome] ?? '') . '</svg>';
}

/** Endereço de um arquivo de assets com versão (evita cache velho após atualização). */
function asset(string $caminho): string
{
    $arq = RAIZ . '/' . $caminho;
    return $caminho . (is_file($arq) ? '?v=' . filemtime($arq) : '');
}

function flashes_html(): string
{
    $html = '';
    foreach (flashes() as $f) {
        $html .= '<div class="flash flash-' . e($f['tipo']) . '" role="alert">' . e($f['msg']) . '</div>';
    }
    return $html;
}

/** Selo de situação da locação (aberta, atrasada, vence hoje, devolvida, cancelada). */
function selo_status_locacao(array $l): string
{
    if ($l['status'] === 'aberta') {
        if ($l['data_prev_devolucao'] < today()) {
            return '<span class="selo vermelho">atrasada</span>';
        }
        return $l['data_prev_devolucao'] === today()
            ? '<span class="selo amarelo">vence hoje</span>'
            : '<span class="selo azul">aberta</span>';
    }
    return $l['status'] === 'devolvida' ? '<span class="selo verde">devolvida</span>' : '<span class="selo">cancelada</span>';
}

/**
 * Linha da tabela dinâmica de itens (nova locação e reserva). Na reserva ($comDiaria = false)
 * a diária e o subtotal ficam ocultos: a reserva não guarda preço.
 */
function linha_item_html(array $produtos, array $ln, bool $comDiaria = true): void
{
    ?>
    <tr class="linha-item">
        <td><select name="produto_id[]" class="sel-produto" aria-label="Produto">
            <option value="">— escolha —</option>
            <?php foreach ($produtos as $p): ?>
                <option value="<?= e($p['id']) ?>" data-diaria="<?= e($p['valor_diaria']) ?>"
                    data-semanal="<?= e($p['valor_semanal'] ?? 0) ?>" data-mensal="<?= e($p['valor_mensal'] ?? 0) ?>"
                    <?= $p['id'] === $ln['produto_id'] ? 'selected' : '' ?>><?= e($p['codigo'] . ' — ' . $p['descricao']) ?></option>
            <?php endforeach; ?>
        </select></td>
        <td class="num"><input type="number" name="quantidade[]" class="qtd" value="<?= e($ln['quantidade']) ?>" min="1" step="1" style="width:80px" aria-label="Quantidade"></td>
        <?php if ($comDiaria): ?>
            <td class="num"><input type="text" name="diaria[]" class="diaria" value="<?= e($ln['diaria']) ?>" inputmode="decimal" style="width:100px" aria-label="Diária"></td>
        <?php else: ?>
            <td hidden><input type="hidden" class="diaria" value=""></td>
        <?php endif; ?>
        <td class="num disp">—</td>
        <td class="num subtotal"<?= $comDiaria ? '' : ' hidden' ?>>—</td>
        <td><button type="button" class="btn btn-sec btn-peq remover" title="Remover item">✕</button></td>
    </tr>
    <?php
}

/**
 * Seletor "adicionar kit" da tabela de itens (nova locação e reserva). O app.js expande o kit
 * nas linhas dos produtos (somando a quem já está na lista). Sem kits cadastrados, não aparece.
 */
function kit_seletor_html(array $kits): string
{
    if (!$kits) {
        return '';
    }
    $ops = '<option value="">— kit —</option>';
    foreach ($kits as $id => $k) {
        $ops .= '<option value="' . e($id) . '" data-itens="' . e(json_encode($k['itens'])) . '">'
              . e($k['codigo'] . ' — ' . $k['nome']) . '</option>';
    }
    return '<select id="kit-sel" aria-label="Kit">' . $ops . '</select>'
         . '<input type="number" id="kit-qtd" value="1" min="1" max="1000" step="1" style="width:70px" aria-label="Quantidade de kits">'
         . '<button type="button" class="btn btn-sec btn-peq" id="add-kit">+ Adicionar kit</button>';
}

/**
 * Botão que abre o WhatsApp (wa.me) com a mensagem pronta, em nova aba.
 * Sem número de celular/telefone no cadastro, aparece desativado com o motivo.
 */
function botao_whatsapp(?string $url, string $rotulo, bool $pequeno = false): string
{
    $classe = 'btn btn-whats' . ($pequeno ? ' btn-peq' : '');
    if ($url === null) {
        return '<span class="' . $classe . ' desativado" title="Cliente sem celular ou telefone no cadastro">'
             . icone('mensagem') . e($rotulo) . '</span>';
    }
    return '<a class="' . $classe . '" href="' . e($url) . '" target="_blank" rel="noopener noreferrer" title="Abre o WhatsApp com a mensagem pronta">'
         . icone('mensagem') . e($rotulo) . '</a>';
}

/**
 * Bloco "Pague com PIX": QR Code (desenhado por assets/js/pix.js com assets/vendor/qrcode.js)
 * e o código copia e cola. A página precisa carregar esses dois scripts.
 */
function pix_bloco(array $pix): string
{
    return '<div class="pix" data-pix="' . e($pix['payload']) . '">'
         . '<div class="pix-qr" role="img" aria-label="QR Code PIX de ' . e(money($pix['valor'])) . '"></div>'
         . '<div class="pix-info"><strong>Pague com PIX: ' . e(money($pix['valor'])) . '</strong>'
         . '<span class="pix-ajuda">Aponte a câmera do app do banco para o QR Code ou use o PIX Copia e Cola:</span>'
         . '<textarea class="pix-codigo" readonly rows="3" aria-label="Código PIX copia e cola">' . e($pix['payload']) . '</textarea>'
         . '<button type="button" class="btn btn-sec btn-peq pix-copiar">Copiar código</button></div></div>';
}

/**
 * Faixa para o admin da locadora: mensalidade vencendo em até 5 dias ou já vencida
 * (dentro da carência, com a data em que o acesso será suspenso).
 */
function aviso_assinatura(): string
{
    $t = tenant();
    if (AREA !== 'locadora' || !is_admin() || $t === null || empty($t['vencimento'])) {
        return '';
    }
    $dias = days_between(today(), $t['vencimento']);
    $contato = config('suporte_contato', '') !== '' ? ' Contato: ' . config('suporte_contato') . '.' : '';
    // Cobrança do Asaas em aberto (o RLS só deixa ler as da própria locadora)
    $link = (string) val("SELECT link FROM assinatura_cobrancas WHERE status = 'pendente' AND link <> ''
                           ORDER BY vencimento LIMIT 1");
    $pagar = $link !== '' ? ' <a class="btn btn-peq" href="' . e($link) . '" target="_blank" rel="noopener noreferrer">Pagar agora</a>' : '';
    if ($dias < 0) {
        return '<div class="flash flash-erro" role="alert">Mensalidade do LocGestor vencida desde ' . e(dt($t['vencimento']))
             . '. Regularize até ' . e(dt(tenant_limite_pagamento($t))) . ' para evitar a suspensão do acesso.' . e($contato) . $pagar . '</div>';
    }
    if ($dias <= 5 || $link !== '') {
        $quando = $dias === 0 ? 'vence hoje' : ($dias === 1 ? 'vence amanhã' : "vence em $dias dias");
        return '<div class="flash flash-aviso" role="alert">Sua mensalidade do LocGestor (plano ' . e($t['plano']) . ") $quando ("
             . e(dt($t['vencimento'])) . ').' . e($contato) . $pagar . '</div>';
    }
    return '';
}

/** Nome da locadora mostrado no topo do menu. */
function nome_locadora(): string
{
    if (AREA === 'plataforma') {
        return 'Painel da plataforma';
    }
    $emp = empresa();
    return ($emp['nome_fantasia'] ?? '') !== '' ? $emp['nome_fantasia'] : (tenant()['nome'] ?? '');
}

function layout_start(string $titulo, string $menuAtivo = ''): void
{
    $u = user();
    ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($titulo) ?> · <?= e(nome_locadora()) ?></title>
<link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
</head>
<body>
<input type="checkbox" id="menu-toggle" class="menu-toggle" aria-label="Abrir menu">
<aside class="sidebar">
    <div class="marca">
        <span class="logo">LG</span>
        <div class="marca-txt">
            <strong title="<?= e(nome_locadora()) ?>"><?= e(nome_locadora()) ?></strong>
            <small><?= e(config('app_name', 'LocGestor')) ?></small>
        </div>
    </div>
    <nav class="menu">
        <?php foreach (AREA === 'plataforma' ? MENU_PLATAFORMA : MENU as $mod => [$rotulo, $ico]): ?>
            <a href="<?= e(url($mod)) ?>"<?= $mod === $menuAtivo ? ' class="ativo" aria-current="page"' : '' ?>>
                <?= icone($ico) ?><span><?= e($rotulo) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
    <div class="usuario">
        <div class="usuario-info">
            <strong><?= e($u['nome'] ?? '') ?></strong>
            <small><?= e(($u['login'] ?? '') . ' · ' . (($u['perfil'] ?? '') === 'admin' ? 'administrador' : ($u['perfil'] ?? ''))) ?></small>
        </div>
        <form method="post" action="<?= e(url('login', ['a' => 'sair'])) ?>">
            <?= csrf_field() ?>
            <button type="submit" class="btn-sair" title="Sair"><?= icone('sair') ?><span>Sair</span></button>
        </form>
    </div>
</aside>
<label for="menu-toggle" class="menu-fundo" aria-hidden="true"></label>
<div class="principal">
    <header class="topo">
        <label for="menu-toggle" class="btn-menu" title="Menu"><?= icone('menu') ?></label>
        <h1><?= e($titulo) ?></h1>
    </header>
    <main class="conteudo">
        <?= aviso_assinatura() ?>
        <?= flashes_html() ?>
<?php
}

/** $scripts: caminhos de JS extras (relativos à raiz), carregados depois do app.js. */
function layout_end(array $scripts = []): void
{
    ?>
    </main>
</div>
<script src="<?= e(asset('assets/js/app.js')) ?>"></script>
<?php foreach ($scripts as $s): ?>
<script src="<?= e(asset($s)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
<?php
}

/**
 * Página para impressão em A4 (contrato, fatura, relatórios).
 * $voltar: para onde o botão Voltar leva. Essas páginas abrem em aba nova,
 * onde não há histórico, por isso o botão é um link e não history.back().
 */
function print_start(string $titulo, string $voltar = '', string $botoesExtras = ''): void
{
    $voltar = $voltar !== '' ? $voltar : url_inicio();
    ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($titulo) ?></title>
<link rel="stylesheet" href="<?= e(asset('assets/css/print.css')) ?>">
</head>
<body>
<div class="barra-impressao">
    <button type="button" onclick="window.print()">Imprimir</button>
    <a href="<?= e($voltar) ?>">Voltar</a>
    <?= $botoesExtras ?>
</div>
<div class="folha">
<?php
}

/**
 * Cabeçalho das páginas de impressão: dados da locadora logada à esquerda e,
 * à direita, um quadro com o título do documento, o número/período e a data de emissão.
 */
function cabecalho_impressao(string $titulo, string $destaque = '', string $info = ''): void
{
    $emp = empresa();
    $doc = array_filter([
        $emp['cnpj'] !== '' ? 'CNPJ ' . doc_formatar($emp['cnpj']) : '',
        $emp['ie'] !== '' ? 'IE ' . $emp['ie'] : '',
    ]);
    $cidade = array_filter([
        trim($emp['cidade'] . ($emp['uf'] !== '' ? '/' . $emp['uf'] : ''), '/'),
        $emp['cep'] !== '' ? 'CEP ' . $emp['cep'] : '',
    ]);
    $contato = array_filter([$emp['telefone'], $emp['email']]);
    // Cada informação fica inteira na linha (nada de "CEP 60325-" numa linha e "001" na outra)
    $linha = fn(array $partes) => implode(' · ', array_map(fn($p) => '<span class="nowrap">' . e($p) . '</span>', $partes));
    ?>
    <header class="cabecalho">
        <div class="emitente">
            <div class="emitente-nome"><?= e($emp['nome_fantasia'] ?: $emp['razao_social']) ?></div>
            <?php if ($emp['nome_fantasia'] !== '' && $emp['nome_fantasia'] !== $emp['razao_social']): ?>
                <div class="emitente-razao"><?= e($emp['razao_social']) ?></div>
            <?php endif; ?>
            <?php if ($doc): ?><div><?= $linha($doc) ?></div><?php endif; ?>
            <?php if ($emp['endereco'] !== ''): ?><div><?= e($emp['endereco']) ?></div><?php endif; ?>
            <?php if ($cidade): ?><div><?= $linha($cidade) ?></div><?php endif; ?>
            <?php if ($contato): ?><div><?= $linha($contato) ?></div><?php endif; ?>
        </div>
        <div class="doc">
            <div class="doc-titulo"><?= e($titulo) ?></div>
            <?php if ($destaque !== ''): ?><div class="doc-numero"><?= e($destaque) ?></div><?php endif; ?>
            <?php if ($info !== ''): ?><div class="doc-info"><?= e($info) ?></div><?php endif; ?>
            <div class="doc-info">Emitido em <?= e(date('d/m/Y')) ?></div>
        </div>
    </header>
    <?php
}

/** $scripts: JS extras da página de impressão (ex.: QR Code do PIX). */
function print_end(array $scripts = []): void
{
    ?>
</div>
<?php foreach ($scripts as $s): ?>
<script src="<?= e(asset($s)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
<?php
}
