<?php

return [
    // Mesma pasta de arquivos do sistema antigo (fotos de vistoria, assinaturas, logomarca)
    'pasta_arquivos' => env('LOCGESTOR_PASTA_ARQUIVOS', base_path('../storage')),

    'por_pagina' => 20,

    // Limite de tentativas de login (iguais às do sistema antigo, na mesma tabela login_tentativas)
    'login_janela_min' => 15,
    'login_max_conta' => 5,
    'login_max_ip' => 20,

    // Cadastro público ("Teste grátis"): desligado por padrão (as mesmas chaves do config.php do sistema antigo)
    'cadastro_publico' => filter_var(env('LOCGESTOR_CADASTRO_PUBLICO', false), FILTER_VALIDATE_BOOLEAN),
    'cadastro_dias_teste' => (int) env('LOCGESTOR_CADASTRO_DIAS_TESTE', 14),
    'cadastro_plano' => env('LOCGESTOR_CADASTRO_PLANO', ''),

    // Cobrança automática das mensalidades pelo Asaas. Sem chave, fica desligada (a rotina só suspende e avisa).
    // Os nomes são os mesmos das variáveis LOCGESTOR_ASAAS_* do sistema antigo (config.php).
    'asaas' => [
        'ambiente' => env('LOCGESTOR_ASAAS_AMBIENTE', 'sandbox') === 'producao' ? 'producao' : 'sandbox',
        'url' => env('LOCGESTOR_ASAAS_URL', ''),
        'chave' => env('LOCGESTOR_ASAAS_CHAVE', ''),
        'webhook_token' => env('LOCGESTOR_ASAAS_WEBHOOK_TOKEN', ''),
        'dias_antes' => max(1, min(30, (int) env('LOCGESTOR_ASAAS_DIAS_ANTES', 10))),
    ],

    // Contato mostrado no aviso de mensalidade da locadora (vazio = sem contato)
    'suporte_contato' => env('LOCGESTOR_SUPORTE_CONTATO', ''),

    // Dados da LocGestor nos documentos da plataforma (recibos). Sem razão social e CNPJ, nenhum recibo é emitido.
    'emitente' => [
        'razao_social' => env('LOCGESTOR_EMITENTE_RAZAO', ''),
        'cnpj' => env('LOCGESTOR_EMITENTE_CNPJ', ''),
        'endereco' => env('LOCGESTOR_EMITENTE_ENDERECO', ''),
        'contato' => env('LOCGESTOR_EMITENTE_CONTATO', ''),
    ],

    // Termos de uso publicados. Sem este endereço, o cadastro público não abre: ninguém se cadastra sem aceitar um texto.
    'termos_url' => env('LOCGESTOR_TERMOS_URL', ''),
];
