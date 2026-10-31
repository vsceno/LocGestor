<?php
/*
 * LocGestor - layout das telas da plataforma (menu lateral) do sistema antigo. As telas das locadoras estão no
 * sistema novo (pasta laravel/).
 */

declare(strict_types=1);

const MENU_PLATAFORMA = [
    'tenants' => ['Locadoras', 'produtos'],
    'numeros' => ['Números', 'painel'],
    'uso'     => ['Uso', 'painel'],
    'recados' => ['Recados', 'mensagem'],
    'auditoria' => ['Auditoria', 'config'],
    'planos'  => ['Planos', 'caixa'],
    'conta'   => ['Minha senha', 'config'],
];

/** Ícones SVG inline (traço), 24x24. */
function icone(string $nome): string
{
    $p = [
        'painel'   => '<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>',
        'produtos' => '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="M3.3 7 12 12l8.7-5M12 22V12"/>',
        'caixa'    => '<path d="M12 1v22M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
        'config'   => '<path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6"/>',
        'sair'     => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
        'menu'     => '<path d="M3 12h18M3 6h18M3 18h18"/>',
        'mensagem' => '<path d="M21 11.5a8.4 8.4 0 0 1-12.4 7.4L3 21l2.1-5.6A8.4 8.4 0 1 1 21 11.5z"/>',
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

function layout_start(string $titulo, string $menuAtivo = ''): void
{
    $u = user();
    $iniciais = mb_strtoupper(implode('', array_map(fn($p) => mb_substr($p, 0, 1), array_slice(preg_split('/\s+/', trim($u['nome'] ?? '')) ?: [], 0, 2))));
    ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($titulo) ?> · Painel da plataforma</title>
<link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
</head>
<body>
<input type="checkbox" id="menu-toggle" class="menu-toggle" aria-label="Abrir menu">
<aside class="sidebar">
    <div class="marca">
        <span class="logo">LG</span>
        <div class="marca-txt">
            <strong title="Painel da plataforma">Painel da plataforma</strong>
            <small><?= e(config('app_name', 'LocGestor')) ?></small>
        </div>
    </div>
    <nav class="menu">
        <?php foreach (MENU_PLATAFORMA as $mod => [$rotulo, $ico]): ?>
            <a href="<?= e(url($mod)) ?>"<?= $mod === $menuAtivo ? ' class="ativo" aria-current="page"' : '' ?>>
                <?= icone($ico) ?><span><?= e($rotulo) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
    <div class="usuario">
        <span class="avatar" aria-hidden="true"><?= e($iniciais) ?></span>
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
