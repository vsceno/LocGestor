@extends('layouts.impressao')

@section('folha')
<div class="cabecalho">
    <div class="emitente">
        <img class="logomarca" src="{{ \App\Support\Configuracoes::logoUrl() }}" alt="{{ \App\Support\Configuracoes::nomeSistema() }}">
        <div class="emitente-nome">
            <div class="emitente-razao">{{ $emitente['razao_social'] }}</div>
            <div>CNPJ {{ doc_formatar($emitente['cnpj']) }}</div>
            @if (($emitente['endereco'] ?? '') !== '')<div>{{ $emitente['endereco'] }}</div>@endif
            @if (($emitente['contato'] ?? '') !== '')<div>{{ $emitente['contato'] }}</div>@endif
        </div>
    </div>
</div>

<h1 class="doc-titulo">Recibo de mensalidade <span class="doc-numero">nº {{ str_pad((string) $p->recibo_numero, 6, '0', STR_PAD_LEFT) }}</span></h1>
<p class="doc-emissao">Pagamento em {{ data_br($p->data) }}</p>

@if ($p->estornado_em)
    <p><strong>ESTORNADO em {{ data_br($p->estornado_em) }}. Este recibo não tem mais validade.</strong></p>
@endif

<p>
    Recebemos de <strong>{{ $p->locadora }}</strong>, CNPJ {{ doc_formatar($p->locadora_cnpj) }}
    (identificador {{ $p->slug }}), a importância de <strong>{{ moeda($p->valor) }}</strong>
    ({{ \App\Support\Recibos::valorExtenso((float) $p->valor) }}), referente a {{ numero($p->meses) }}
    mês(es) de assinatura do LocGestor, com vencimento de {{ data_br($p->vencimento_anterior) }}
    para {{ data_br($p->vencimento_novo) }}. Forma de pagamento: {{ $p->forma }}.
</p>

@if ($p->obs !== '')
    <p>Observação: {{ $p->obs }}</p>
@endif

@php $observacaoPadrao = \App\Support\Configuracoes::ler()->observacao_recibo; @endphp
@if ($observacaoPadrao !== '')
    <p>{{ $observacaoPadrao }}</p>
@endif

<p class="muted">Documento emitido pela plataforma LocGestor. Não substitui a nota fiscal de serviço, que é emitida à parte.</p>

<div class="assinaturas">
    <div class="assinatura-impressa">{{ $emitente['razao_social'] }}</div>
</div>
@endsection
