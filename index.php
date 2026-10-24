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

executar_rota(
    ['login', 'dashboard', 'clientes', 'produtos', 'locacoes', 'reservas', 'caixa', 'relatorios', 'config', 'api'],
    __DIR__ . '/modulos',
    'dashboard'
);
