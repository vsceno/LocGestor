<?php

return [
    // Telas que ainda não foram para o Laravel abrem no sistema antigo
    'legado_url' => env('LOCGESTOR_LEGADO_URL', 'http://locgestor.test'),

    // Mesma pasta de arquivos do sistema antigo (fotos de vistoria, assinaturas, logomarca)
    'pasta_arquivos' => env('LOCGESTOR_PASTA_ARQUIVOS', base_path('../storage')),

    'por_pagina' => 20,

    // Limite de tentativas de login (iguais às do sistema antigo, na mesma tabela login_tentativas)
    'login_janela_min' => 15,
    'login_max_conta' => 5,
    'login_max_ip' => 20,
];
