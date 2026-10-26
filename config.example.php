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
    // Em produção, com certificado instalado: true (redireciona http -> https)
    'forcar_https' => false,
    'app_name' => 'LocGestor',
    // Contato do suporte mostrado às locadoras no aviso de mensalidade (e-mail, WhatsApp...)
    'suporte_contato' => '',
    'timezone' => 'America/Fortaleza',
    'per_page' => 20,
    // Pasta das fotos de vistoria e assinaturas. Padrão: storage/ dentro do projeto
    // (bloqueada por .htaccess). Em produção, prefira uma pasta FORA da pasta do site.
    // 'pasta_arquivos' => 'C:/laragon/arquivos/locgestor',
    // Cobrança automática das mensalidades pelo Asaas (vazio = desligada; veja o README).
    // A chave da API é segredo: fica só aqui, nunca no Git.
    'asaas' => [
        'ambiente'      => 'sandbox',   // 'sandbox' para testes, 'producao' para cobrar de verdade
        'chave'         => '',          // chave da API (Asaas › Integrações)
        'webhook_token' => '',          // token de autenticação do webhook (invente um longo e aleatório)
        'dias_antes'    => 10,          // gera a cobrança N dias antes do vencimento
    ],
];
