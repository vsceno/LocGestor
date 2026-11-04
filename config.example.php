<?php
/*
 * LocGestor - configuração da raiz (opcional).
 *
 * Copie este arquivo para config.php somente se o endereço antigo (index.php e cadastro.php) precisar de um destino
 * diferente de /novo. O config.php NÃO vai para o GitHub.
 *
 * As senhas do banco, a chave do Asaas, o token do webhook, os proxies e os demais ajustes do sistema ficam no
 * arquivo laravel/.env (copie laravel/.env.example). A tela Configurações da LocGestor, no painel da plataforma,
 * guarda os dados da LocGestor, a identidade visual, os termos e o cadastro público no banco.
 */
return [
    // Endereço do sistema novo (pasta laravel/), por exemplo http://localhost/novo. Para lá vão o index.php e o
    // cadastro.php. Vazio = /novo.
    'laravel_url' => '',
];
