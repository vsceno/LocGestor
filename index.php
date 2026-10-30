<?php
/*
 * LocGestor - sistema das locadoras.
 * Rotas: index.php?p=<modulo>&a=<acao>  →  função <modulo>_<acao>() em modulos/<modulo>.php
 * Conecta ao banco como locgestor_app; o contexto da locadora vem só da sessão.
 */

declare(strict_types=1);

const AREA = 'locadora';

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/layout.php';
require __DIR__ . '/app/pix.php';
require __DIR__ . '/app/arquivos.php';
require __DIR__ . '/app/negocio.php';
require __DIR__ . '/app/orcamento.php';
require __DIR__ . '/app/recibo.php';
require __DIR__ . '/app/ponte.php';

// Senha temporária: até trocá-la, o usuário só acessa a tela de troca de senha (e o Sair)
if (!empty(user()['trocar_senha']) && get('p') !== 'login'
    && !(get('p') === 'config' && in_array(get('a'), ['senha', 'senha_salvar'], true))) {
    redirect(url('config', ['a' => 'senha']));
}

executar_rota(
    ['login', 'dashboard', 'busca', 'roteiro', 'clientes', 'produtos', 'manutencoes', 'locacoes', 'reservas', 'caixa', 'relatorios', 'config', 'api', 'arquivos', 'suporte', 'orcamentos', 'recibos'],
    __DIR__ . '/modulos',
    'dashboard'
);
