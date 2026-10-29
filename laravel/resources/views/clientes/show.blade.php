@extends('layouts.app', ['titulo' => $c->nome, 'ativo' => 'clientes'])

@php
    $endereco = trim(implode(', ', array_filter([$c->endereco, $c->numero, $c->bairro])));
    $cidade = trim($c->cidade . ($c->uf !== '' ? '/' . $c->uf : ''));
    $hoje = date('Y-m-d');
@endphp

@section('conteudo')
<div class="kpis">
    <div class="kpi"><span>Locações</span><strong>{{ numero($r->locacoes) }}</strong><small>{{ numero($r->abertas) }} em aberto</small></div>
    <div class="kpi azul"><span>Total movimentado</span><strong>{{ moeda($r->movimentado) }}</strong><small>pago {{ moeda($r->pago) }}</small></div>
    <div class="kpi {{ $r->saldo > 0 ? 'vermelho' : 'verde' }}"><span>Saldo devedor</span><strong>{{ moeda($r->saldo) }}</strong></div>
</div>
<div class="grid-2">
    <div class="card">
        <div class="card-titulo"><h2>Dados do cliente</h2><div>@include('clientes._selos', ['c' => $c])</div></div>
        <dl class="dados">
            <dt>Tipo</dt><dd>{{ $c->tipo === 'PJ' ? 'Pessoa jurídica' : 'Pessoa física' }}</dd>
            <dt>{{ $c->tipo === 'PJ' ? 'CNPJ' : 'CPF' }}</dt><dd>{{ doc_formatar($c->cpf_cnpj) ?: '—' }}</dd>
            <dt>{{ $c->tipo === 'PJ' ? 'Insc. estadual' : 'RG' }}</dt><dd>{{ $c->rg_ie ?: '—' }}</dd>
            <dt>Telefone</dt><dd>{{ trim($c->telefone . '  ' . $c->celular) ?: '—' }}</dd>
            <dt>E-mail</dt><dd>{{ $c->email ?: '—' }}</dd>
            <dt>Endereço</dt><dd>{{ $endereco ?: '—' }}@if ($cidade !== '')<br>{{ $cidade }}@endif @if ($c->cep !== '') · CEP {{ $c->cep }}@endif</dd>
            @if ($c->obs !== '')<dt>Observações</dt><dd>{!! nl2br(e($c->obs)) !!}</dd>@endif
            <dt>Cadastrado em</dt><dd>{{ data_br($c->criado_em) }}</dd>
        </dl>
    </div>
    <div class="card">
        <h2>Ações</h2>
        <div class="acoes">
            <a class="btn" href="{{ legado('locacoes', ['a' => 'nova', 'cliente' => $c->id]) }}">+ Nova locação</a>
            <a class="btn btn-sec" href="{{ legado('clientes', ['a' => 'editar', 'id' => $c->id]) }}">Editar</a>
            <a class="btn btn-sec" href="{{ route('clientes.index') }}">Voltar para a lista</a>
        </div>
        <p class="muted" style="margin-top:12px">Cadastro, edição, bloqueio e exclusão ainda abrem no sistema antigo.</p>
    </div>
</div>
<div class="card">
    <h2>Histórico de locações</h2>
    <div class="tabela-wrap">
        <table class="tabela">
            <thead><tr><th>Nº</th><th>Saída</th><th>Devolução prevista</th><th>Situação</th><th class="num">Valor</th><th class="num">Pago</th><th class="num">Saldo</th></tr></thead>
            <tbody>
            @forelse ($locacoes as $l)
                @php $saldo = $l->status === 'cancelada' ? 0 : $l->valor_atual - $l->valor_pago; @endphp
                <tr>
                    <td><a href="{{ legado('locacoes', ['a' => 'ver', 'n' => $l->numero]) }}">{{ $l->numero }}</a></td>
                    <td>{{ data_br($l->data_saida) }}</td>
                    <td>{{ data_br($l->data_prev_devolucao) }}</td>
                    <td>
                        @if ($l->status === 'aberta')
                            @if ($l->data_prev_devolucao < $hoje)<span class="selo vermelho">atrasada</span>
                            @elseif ($l->data_prev_devolucao === $hoje)<span class="selo amarelo">vence hoje</span>
                            @else<span class="selo azul">aberta</span>@endif
                        @elseif ($l->status === 'devolvida')<span class="selo verde">devolvida</span>
                        @else<span class="selo">cancelada</span>@endif
                    </td>
                    <td class="num">{{ moeda($l->valor_atual) }}</td>
                    <td class="num">{{ moeda($l->valor_pago) }}</td>
                    <td class="num{{ $saldo > 0.004 ? ' alerta' : '' }}">{{ moeda($saldo) }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="vazio">Nenhuma locação ainda.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
