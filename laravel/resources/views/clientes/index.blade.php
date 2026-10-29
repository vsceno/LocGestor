@extends('layouts.app', ['titulo' => 'Clientes', 'ativo' => 'clientes'])

@section('conteudo')
<div class="card">
    <div class="card-titulo">
        <h2>Clientes</h2>
        <a class="btn" href="{{ legado('clientes', ['a' => 'novo']) }}">+ Novo cliente</a>
    </div>
    <form method="get" action="{{ route('clientes.index') }}" class="filtros">
        <input type="search" name="q" value="{{ $q }}" placeholder="Nome, CPF/CNPJ ou telefone" aria-label="Buscar" maxlength="100">
        <select name="situacao" aria-label="Situação">
            @foreach ($situacoes as $valor => $rotulo)
                <option value="{{ $valor }}" @selected($valor === $situacao)>{{ $rotulo }}</option>
            @endforeach
        </select>
        <button class="btn btn-sec">Buscar</button>
        @if ($q !== '' || $situacao !== 'ativos')
            <a class="btn btn-sec" href="{{ route('clientes.index') }}">Limpar</a>
        @endif
    </form>
    <div class="tabela-wrap">
        <table class="tabela">
            <thead><tr><th>Nome</th><th>CPF/CNPJ</th><th>Telefone</th><th>Cidade</th><th>Situação</th><th class="num">Abertas</th><th></th></tr></thead>
            <tbody>
            @forelse ($clientes as $c)
                <tr>
                    <td><a href="{{ route('clientes.show', $c->id) }}"><strong>{{ $c->nome }}</strong></a> <span class="muted">{{ $c->tipo }}</span></td>
                    <td class="nowrap">{{ doc_formatar($c->cpf_cnpj) }}</td>
                    <td class="nowrap">{{ $c->celular ?: $c->telefone }}</td>
                    <td>{{ $c->cidade }}{{ $c->uf !== '' ? '/' . $c->uf : '' }}</td>
                    <td>@include('clientes._selos', ['c' => $c])</td>
                    <td class="num">{{ numero($c->abertas) }}</td>
                    <td class="nowrap"><a class="btn btn-sec btn-peq" href="{{ legado('clientes', ['a' => 'editar', 'id' => $c->id]) }}">Editar</a></td>
                </tr>
            @empty
                <tr><td colspan="7" class="vazio">Nenhum cliente encontrado.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @include('partials.paginacao', ['p' => $clientes])
</div>
@endsection
