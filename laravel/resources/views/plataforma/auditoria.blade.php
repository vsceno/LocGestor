@extends('layouts.plataforma', ['titulo' => 'Auditoria', 'ativo' => 'auditoria', 'subtitulo' => 'Quem criou, alterou ou excluiu locadoras, planos, pagamentos, recados e usuários do painel, e quando. O histórico não pode ser alterado nem apagado.'])

@section('conteudo')
<section class="cartao overflow-hidden">
    <div class="cartao-cabecalho">
        <h2 class="cartao-titulo">Registros</h2>
        <span class="text-sm text-zinc-500">{{ numero($total) }} registro(s)</span>
    </div>
    <form method="get" class="flex flex-wrap items-center gap-2 border-b border-zinc-100 px-5 py-3 dark:border-zinc-800">
        <input type="date" name="de" value="{{ $de }}" aria-label="De" class="campo w-auto">
        <input type="date" name="ate" value="{{ $ate }}" aria-label="Até" class="campo w-auto">
        <select name="tabela" aria-label="O quê" class="campo w-auto">
            <option value="">Tudo</option>
            @foreach ($tabelas as $chave => $rotulo)<option value="{{ $chave }}" @selected($tabela === $chave)>{{ $rotulo }}</option>@endforeach
        </select>
        <select name="acao" aria-label="Ação" class="campo w-auto">
            <option value="">Todas as ações</option>
            @foreach ($acoes as $chave => $rotulo)<option value="{{ $chave }}" @selected($acao === $chave)>{{ $rotulo }}</option>@endforeach
        </select>
        <select name="usuario" aria-label="Quem" class="campo w-auto">
            <option value="">Todos</option>
            @foreach ($usuarios as $u)<option value="{{ $u }}" @selected($filtroUsuario === $u)>{{ $u }}</option>@endforeach
        </select>
        <button class="btn-secundario">Filtrar</button>
    </form>

    <div class="overflow-x-auto">
        <table class="tabela">
            <thead><tr><th>Quando</th><th>Quem</th><th>Ação</th><th>O quê</th><th>Detalhes</th></tr></thead>
            <tbody>
                @forelse ($linhas as $a)
                    <tr>
                        <td class="whitespace-nowrap tabular-nums">{{ $a['quando'] }}</td>
                        <td>{{ $a['usuario'] }}</td>
                        <td><span class="{{ $a['cor'] }}">{{ $a['acao'] }}</span></td>
                        <td>{{ $a['texto'] }}</td>
                        <td class="text-xs text-zinc-600 dark:text-zinc-400">
                            @foreach ($a['detalhes'] as $detalhe)<div>{{ $detalhe }}</div>@endforeach
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-10 text-center text-zinc-500">Nada registrado no período.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($linhas->hasPages())
        <div class="border-t border-zinc-100 px-5 py-3 dark:border-zinc-800">{{ $linhas->links() }}</div>
    @endif
</section>
@endsection
