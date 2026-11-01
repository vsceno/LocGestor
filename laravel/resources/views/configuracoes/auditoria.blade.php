@extends('layouts.app', ['titulo' => 'Configurações', 'ativo' => 'config'])

@section('subtitulo', 'Quem incluiu, alterou ou excluiu o quê, e quando. Não pode ser alterado nem apagado pelo sistema.')

@section('conteudo')
@include('configuracoes._abas', ['ativa' => 'config.auditoria'])

<section class="cartao mb-6 p-5">
    <p class="mb-4 text-sm text-zinc-500 dark:text-zinc-400">Toda inclusão, alteração e exclusão em locações, clientes, produtos, caixa, usuários e demais cadastros.
        Dados pessoais e senhas aparecem como <em>(oculto)</em>: o registro mostra que mudaram, não os valores.</p>
    <form method="get" action="{{ route('config.auditoria') }}" class="flex flex-wrap items-end gap-3">
        @if ($registro !== '')<input type="hidden" name="registro" value="{{ $registro }}">@endif
        <label class="block"><span class="text-xs text-zinc-500">De</span><input type="date" name="de" value="{{ $de }}" class="campo mt-1"></label>
        <label class="block"><span class="text-xs text-zinc-500">Até</span><input type="date" name="ate" value="{{ $ate }}" class="campo mt-1"></label>
        <label class="block"><span class="text-xs text-zinc-500">O quê</span>
            <select name="tabela" class="campo mt-1">
                <option value="">Tudo</option>
                @foreach ($tabelas as $valor => $rotulo)<option value="{{ $valor }}" @selected($tabela === $valor)>{{ $rotulo }}</option>@endforeach
            </select></label>
        <label class="block"><span class="text-xs text-zinc-500">Ação</span>
            <select name="acao" class="campo mt-1">
                <option value="">Todas as ações</option>
                @foreach ($acoes as $valor => $rotulo)<option value="{{ $valor }}" @selected($acao === $valor)>{{ $rotulo }}</option>@endforeach
            </select></label>
        <label class="block"><span class="text-xs text-zinc-500">Usuário</span>
            <select name="usuario" class="campo mt-1">
                <option value="">Todos os usuários</option>
                @foreach ($usuarios as $nome)<option value="{{ $nome }}" @selected($filtroUsuario === $nome)>{{ $nome }}</option>@endforeach
            </select></label>
        <button class="btn-secundario">Filtrar</button>
        @if ($registro !== '')<a class="text-sm text-marca-700 hover:underline dark:text-marca-400" href="{{ route('config.auditoria') }}">ver tudo</a>@endif
    </form>
</section>

<section class="cartao overflow-hidden">
    <div class="flex items-center justify-between border-b border-zinc-100 px-5 py-4 dark:border-zinc-800">
        <h2 class="cartao-titulo">Auditoria</h2>
        <span class="text-sm text-zinc-500">{{ numero($total) }} registro(s)</span>
    </div>
    <div class="overflow-x-auto">
        <table class="tabela">
            <thead><tr><th>Quando</th><th>Usuário</th><th>Ação</th><th>O quê</th><th>Detalhes</th></tr></thead>
            <tbody>
            @forelse ($linhas as $l)
                <tr>
                    <td class="whitespace-nowrap tabular-nums">{{ $l['quando'] }}</td>
                    <td class="text-zinc-900 dark:text-zinc-100">{{ $l['usuario'] }}</td>
                    <td><span class="{{ $l['cor'] }}">{{ $l['acao'] }}</span></td>
                    <td>
                        @if ($l['url'])
                            <a class="font-medium text-marca-700 hover:underline dark:text-marca-400" href="{{ $l['url'] }}">{{ $l['texto'] }}</a>
                        @else
                            <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $l['texto'] }}</span>
                        @endif
                        @if ($l['historico'])
                            <a class="ml-1 text-sm text-zinc-500 hover:underline" title="Todo o histórico deste registro"
                               href="{{ route('config.auditoria', ['registro' => $l['registro'], 'de' => '2000-01-01']) }}">histórico</a>
                        @endif
                    </td>
                    <td class="max-w-md text-sm text-zinc-600 dark:text-zinc-400">
                        @foreach ($l['detalhes'] as $linha)
                            <div>{{ $linha }}</div>
                        @endforeach
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="py-10 text-center text-sm text-zinc-500">Nada registrado no período.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @include('partials.paginacao', ['p' => $linhas])
</section>
@endsection
