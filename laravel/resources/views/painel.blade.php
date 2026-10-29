@extends('layouts.app', ['titulo' => 'Painel', 'ativo' => 'painel'])

@section('conteudo')
<div class="kpis">
    <a class="kpi" href="{{ legado('locacoes') }}"><span>Locações em aberto</span><strong>{{ numero($k->abertas) }}</strong>
        <small>{{ numero($k->vencem_hoje) }} vence(m) hoje</small></a>
    <a class="kpi vermelho" href="{{ legado('locacoes') }}"><span>Atrasadas</span><strong>{{ numero($k->atrasadas) }}</strong>
        <small>devolução prevista vencida</small></a>
    <div class="kpi azul"><span>Total a receber</span><strong>{{ moeda($k->a_receber) }}</strong><small>saldo das locações</small></div>
    <a class="kpi verde" href="{{ route('clientes.index') }}"><span>Clientes ativos</span><strong>{{ numero($k->clientes) }}</strong>
        <small>ver a lista</small></a>
</div>

<div class="card">
    <div class="card-titulo">
        <h2>Devoluções atrasadas e dos próximos 3 dias</h2>
        <a href="{{ legado('locacoes') }}">ver todas</a>
    </div>
    <div class="tabela-wrap">
        <table class="tabela">
            <thead><tr><th>Nº</th><th>Cliente</th><th>Devolução prevista</th><th>Situação</th></tr></thead>
            <tbody>
            @forelse ($atrasadas as $l)
                <tr>
                    <td><a href="{{ legado('locacoes', ['a' => 'ver', 'n' => $l->numero]) }}">{{ $l->numero }}</a></td>
                    <td>{{ $l->nome }}</td>
                    <td>{{ data_br($l->data_prev_devolucao) }}</td>
                    <td>
                        @if ($l->dias > 0)<span class="selo vermelho">{{ $l->dias }} dia(s) de atraso</span>
                        @elseif ($l->dias == 0)<span class="selo amarelo">vence hoje</span>
                        @else<span class="selo azul">em {{ -$l->dias }} dia(s)</span>@endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="vazio">Nenhuma devolução atrasada ou próxima.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
