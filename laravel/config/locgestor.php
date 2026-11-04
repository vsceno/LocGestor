<?php

return [
    // Mesma pasta de arquivos do sistema antigo (fotos de vistoria, assinaturas, logomarca da plataforma)
    'pasta_arquivos' => env('LOCGESTOR_PASTA_ARQUIVOS', base_path('../storage')),

    'por_pagina' => 20,

    // Limite de tentativas de login (iguais às do sistema antigo, na mesma tabela login_tentativas)
    'login_janela_min' => 15,
    'login_max_conta' => 5,
    'login_max_ip' => 20,

    // Cobrança automática das mensalidades pelo Asaas. Sem chave, fica desligada (a rotina só suspende e avisa).
    // Os nomes são os mesmos das variáveis LOCGESTOR_ASAAS_* do sistema antigo (config.php).
    'asaas' => [
        'ambiente' => env('LOCGESTOR_ASAAS_AMBIENTE', 'sandbox') === 'producao' ? 'producao' : 'sandbox',
        'url' => env('LOCGESTOR_ASAAS_URL', ''),
        'chave' => env('LOCGESTOR_ASAAS_CHAVE', ''),
        'webhook_token' => env('LOCGESTOR_ASAAS_WEBHOOK_TOKEN', ''),
        'dias_antes' => max(1, min(30, (int) env('LOCGESTOR_ASAAS_DIAS_ANTES', 10))),
    ],

    // Proxies confiáveis (IPs separados por vírgula). Infraestrutura do servidor, por isso fica no ambiente
    // e não na tela. Vazio = nenhum proxy confiável (o padrão seguro).
    'proxies' => env('LOCGESTOR_PROXIES', ''),
    'forcar_https' => (bool) env('LOCGESTOR_FORCAR_HTTPS', false),

    // Os demais dados (empresa, identidade, documentos, cadastro público, suporte) ficam na tabela
    // plataforma_configuracoes e são alterados pela tela Configurações da LocGestor, no painel da plataforma.
];
