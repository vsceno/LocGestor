<?php
/*
 * Busca global (campo no topo de todas as telas): clientes, locações, reservas, produtos e unidades.
 *
 * LGPD: o termo pode ser um CPF/CNPJ ou telefone, então vai por POST e fica na sessão;
 * a URL da página de resultados nunca carrega o que foi buscado.
 */

declare(strict_types=1);

const BUSCA_LIMITE = 10;

function busca_buscar(): void
{
    $_SESSION['busca'] = mb_substr(trim(post('q')), 0, 100);
    redirect(url('busca'));
}

/** Resultados por grupo: [titulo => [[rotulo, detalhe, url], ...]]. */
function busca_resultados(string $q): array
{
    $dig = so_digitos($q);
    $txt = like($q);
    $numero = preg_match('/^#?\d{1,9}$/', $q) ? (int) ltrim($q, '#') : null;
    $soNumero = str_starts_with($q, '#');   // "#123": só locação e reserva com esse número
    $r = [];

    $cond = ['lower(c.nome) LIKE lower(:t1)'];
    $p = ['t1' => $txt];
    if (strlen($dig) >= 3) {
        $cond[] = 'c.cpf_cnpj LIKE :d1';
        $cond[] = "regexp_replace(c.celular || ' ' || c.telefone, '\\D', '', 'g') LIKE :d2";
        $p += ['d1' => '%' . $dig . '%', 'd2' => '%' . $dig . '%'];
    }
    $r['Clientes'] = $soNumero ? [] : array_map(fn($c) => [$c['nome'], trim(doc_formatar($c['cpf_cnpj']) . ' · ' . $c['cidade'], ' ·')
                                            . ($c['ativo'] ? '' : ' · inativo'), url('clientes', ['a' => 'ver', 'id' => $c['id']])],
        all('SELECT c.id, c.nome, c.cpf_cnpj, c.cidade, c.ativo FROM clientes c WHERE ' . implode(' OR ', $cond)
            . ' ORDER BY c.ativo DESC, lower(c.nome) LIMIT ' . BUSCA_LIMITE, $p));

    $r['Locações'] = array_map(fn($l) => ['Locação nº ' . $l['numero'], $l['nome'] . ' · ' . dt($l['data_saida']) . ' · ' . $l['status'],
                                          url('locacoes', ['a' => 'ver', 'n' => $l['numero']])],
        all('SELECT l.numero, l.data_saida, l.status, c.nome FROM locacoes l JOIN clientes c ON c.id = l.cliente_id
              WHERE l.numero = :n OR (NOT CAST(:so AS boolean) AND lower(l.local_uso) LIKE lower(:t))
              ORDER BY (l.numero = :n2) DESC, l.numero DESC LIMIT ' . BUSCA_LIMITE,
            ['n' => $numero ?? -1, 'n2' => $numero ?? -1, 'so' => $soNumero ? 'true' : 'false', 't' => $txt]));

    $r['Reservas'] = $numero === null ? [] : array_map(fn($x) => ['Reserva nº ' . $x['numero'], $x['nome'] . ' · ' . dt($x['data_inicio']) . ' · ' . $x['status'],
                                                                 url('reservas', ['a' => 'ver', 'n' => $x['numero']])],
        all('SELECT r.numero, r.data_inicio, r.status, c.nome FROM reservas r JOIN clientes c ON c.id = r.cliente_id WHERE r.numero = :n',
            ['n' => $numero]));

    $r['Produtos'] = $soNumero ? [] : array_map(fn($x) => [$x['codigo'] . ' — ' . $x['descricao'], ($x['ativo'] ? '' : 'inativo · ') . num($x['qtd_total']) . ' em estoque',
                                          url('produtos', ['a' => 'editar', 'id' => $x['id']])],
        all('SELECT id, codigo, descricao, qtd_total, ativo FROM produtos WHERE lower(codigo) LIKE lower(:t1) OR lower(descricao) LIKE lower(:t2)
              ORDER BY ativo DESC, descricao LIMIT ' . BUSCA_LIMITE, ['t1' => $txt, 't2' => $txt]));

    $r['Patrimônio'] = $soNumero ? [] : array_map(fn($x) => ['Unidade ' . $x['patrimonio'], $x['descricao'] . ($x['serie'] !== '' ? ' · série ' . $x['serie'] : '')
                                            . ($x['ativa'] ? '' : ' · baixada'), url('produtos', ['a' => 'unidade', 'id' => $x['id']])],
        all('SELECT u.id, u.patrimonio, u.serie, u.ativa, p.descricao FROM unidades u JOIN produtos p ON p.id = u.produto_id
              WHERE lower(u.patrimonio) LIKE lower(:t1) OR lower(u.serie) LIKE lower(:t2)
              ORDER BY u.ativa DESC, u.patrimonio LIMIT ' . BUSCA_LIMITE, ['t1' => $txt, 't2' => $txt]));

    return array_filter($r);
}

function busca_index(): void
{
    $q = (string) ($_SESSION['busca'] ?? '');
    $grupos = mb_strlen($q) >= 2 ? busca_resultados($q) : [];
    // Um único resultado: abre direto
    $todos = array_merge(...array_values($grupos ?: [[]]));
    if (count($todos) === 1) {
        redirect($todos[0][2]);
    }

    layout_start('Busca', '');
    ?>
    <div class="card">
        <form method="post" action="<?= e(url('busca', ['a' => 'buscar'])) ?>" class="filtros">
            <?= csrf_field() ?>
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="Nome, CPF/CNPJ, telefone, #nº da locação, código, patrimônio..."
                   aria-label="Buscar" style="flex:1;min-width:240px" autofocus>
            <button class="btn">Buscar</button>
        </form>
        <?php if (mb_strlen($q) < 2): ?>
            <p class="muted">Digite pelo menos 2 caracteres.</p>
        <?php elseif (!$grupos): ?>
            <p class="vazio">Nada encontrado para "<?= e($q) ?>".</p>
        <?php endif; ?>
    </div>
    <?php foreach ($grupos as $titulo => $itens): ?>
        <div class="card">
            <h2><?= e($titulo) ?> <span class="muted">(<?= num(count($itens)) ?><?= count($itens) >= BUSCA_LIMITE ? '+' : '' ?>)</span></h2>
            <ul class="busca-lista">
                <?php foreach ($itens as [$rotulo, $detalhe, $link]): ?>
                    <li><a href="<?= e($link) ?>"><?= e($rotulo) ?></a> <span class="muted"><?= e($detalhe) ?></span></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endforeach; ?>
    <?php
    layout_end();
}
