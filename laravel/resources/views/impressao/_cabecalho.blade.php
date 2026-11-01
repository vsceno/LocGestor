{{-- Cabeçalho das páginas de impressão: dados da locadora à esquerda e a logomarca (se houver) à direita.
     Abaixo da linha, no meio da folha, o título do documento em negrito; embaixo, menor, a data de emissão. Igual a cabecalho_impressao(). --}}
@php
    $destaque = $destaque ?? '';
    $info = $info ?? '';
    $nomeLocadora = $empresa->nome_fantasia ?: $empresa->razao_social;
    $doc = array_values(array_filter([
        $empresa->cnpj !== '' ? 'CNPJ ' . doc_formatar($empresa->cnpj) : '',
        $empresa->ie !== '' ? 'IE ' . $empresa->ie : '',
    ]));
    $cidade = array_values(array_filter([
        trim(trim($empresa->cidade) . (trim($empresa->uf) !== '' ? '/' . trim($empresa->uf) : ''), '/'),
        $empresa->cep !== '' ? 'CEP ' . $empresa->cep : '',
    ]));
    $contato = array_values(array_filter([$empresa->telefone, $empresa->email]));
    // Cada informação fica inteira na linha (nada de "CEP 60325-" numa linha e "001" na outra)
    $linha = fn (array $partes) => implode(' · ', array_map(fn ($p) => '<span class="nowrap">' . e($p) . '</span>', $partes));
@endphp
<header class="cabecalho">
    <div class="emitente">
        <div class="emitente-nome">{{ $nomeLocadora }}</div>
        @if ($empresa->nome_fantasia !== '' && $empresa->nome_fantasia !== $empresa->razao_social)
            <div class="emitente-razao">{{ $empresa->razao_social }}</div>
        @endif
        @if ($doc)<div>{!! $linha($doc) !!}</div>@endif
        @if ($empresa->endereco !== '')<div>{{ $empresa->endereco }}</div>@endif
        @if ($cidade)<div>{!! $linha($cidade) !!}</div>@endif
        @if ($contato)<div>{!! $linha($contato) !!}</div>@endif
    </div>
    @if ($empresa->logo_arquivo !== '')
        {{-- O número na URL muda com o arquivo, para o navegador não usar a logomarca antiga do cache --}}
        <img class="logomarca" src="{{ route('arquivos.logo', ['v' => substr(md5($empresa->logo_arquivo), 0, 8)]) }}" alt="Logomarca de {{ $nomeLocadora }}">
    @endif
</header>
<div class="doc">
    <span class="doc-titulo">{{ $titulo }}</span>@if ($destaque !== '') <span class="doc-numero">{{ $destaque }}</span>@endif
    @if ($info !== '')· <span class="doc-info">{{ $info }}</span>@endif
    <div class="doc-emissao">Emitido em {{ now()->format('d/m/Y') }}</div>
</div>
