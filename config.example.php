<?php
/*
 * LocGestor - modelo de configuração.
 * Copie este arquivo para config.php e preencha as senhas dos papéis do banco
 * (as mesmas usadas no database/instalar.bat ou no 00_roles.sql).
 * O config.php NÃO vai para o GitHub.
 */
return [
    // Usado pelo sistema das locadoras (index.php). Papel sem BYPASSRLS.
    'db_app' => [
        'host'     => 'localhost',
        'port'     => 5432,
        'dbname'   => 'locgestor',
        'user'     => 'locgestor_app',
        'password' => '',
    ],
    // Usado pelo painel da plataforma (plataforma.php).
    'db_plataforma' => [
        'host'     => 'localhost',
        'port'     => 5432,
        'dbname'   => 'locgestor',
        'user'     => 'locgestor_plataforma',
        'password' => '',
    ],
    'app_name' => 'LocGestor',
    'timezone' => 'America/Fortaleza',
    'per_page' => 20,
];
