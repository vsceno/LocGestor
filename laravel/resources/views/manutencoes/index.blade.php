@extends('layouts.app', ['titulo' => 'Manutenção', 'ativo' => 'manutencoes'])

@section('subtitulo', 'Peças em conserto ou revisão saem do estoque disponível até a manutenção ser concluída.')

@section('acoes')
    <a class="btn-primario" href="{{ route('manutencoes.create') }}"><x-icone nome="mais" class="size-4" /> Nova manutenção</a>
@endsection

@section('conteudo')
<div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
    <x-kpi rotulo="Peças em manutenção" :valor="numero($abertas)" detalhe="fora do estoque disponível" ico="manutencao"
           cor="bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300" />
    <x-kpi rotulo="Custo no ano" :valor="moeda($custoAno)" :detalhe="'manutenções concluídas em ' . now()->year" ico="dinheiro"
           cor="bg-red-50 text-red-600 dark:bg-red-500/10 dark:text-red-400" />
</div>

<section class="cartao mt-6 overflow-hidden">
    <nav class="flex gap-1 border-b border-zinc-100 p-4 dark:border-zinc-800" aria-label="Situação">
        <div class="flex gap-1 rounded-xl bg-zinc-100 p-1 dark:bg-zinc-800">
            @foreach ($situacoes as $valor => $rotulo)
                <a href="{{ route('manutencoes.index', ['situacao' => $valor]) }}" @if ($valor === $situacao) aria-current="page" @endif
                   class="rounded-lg px-3 py-1.5 text-sm font-medium transition {{ $valor === $situacao ? 'bg-white text-zinc-900 shadow-xs dark:bg-zinc-900 dark:text-zinc-100' : 'text-zinc-500 hover:text-zinc-900 dark:hover:text-zinc-100' }}">{{ $rotulo }}</a>
            @endforeach
        </div>
    </nav>
    @if ($manutencoes->total() === 0)
        <div class="px-6 py-16 text-center">
            <span class="mx-auto grid size-12 place-items-center rounded-full bg-marca-50 text-marca-600 dark:bg-marca-500/10"><x-icone nome="manutencao" /></span>
            <p class="mt-3 font-medium">Nenhuma manutenção {{ $situacao === 'abertas' ? 'em aberto' : 'encontrada' }}</p>
            <p class="mt-1 text-sm text-zinc-500">Peças enviadas para conserto ou revisão aparecem aqui.</p>
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="tabela">
                <thead><tr><th>Nº</th><th>Produto</th><th class="num">Qtd.</th><th>Tipo</th><th>Problema / serviço</th><th>Início</th><th>Conclusão</th><th class="num">Custo</th></tr></thead>
                <tbody>
                @foreach ($manutencoes as $m)
                    <tr data-href="{{ route('manutencoes.show', $m->id) }}">
                        <td><a href="{{ route('manutencoes.show', $m->id) }}" class="font-semibold tabular-nums text-zinc-900 dark:text-zinc-100">{{ numero($m->numero) }}</a></td>
                        <td class="font-medium text-zinc-900 dark:text-zinc-100">{{ $m->produto }}@if ($m->patrimonio) <span class="font-mono text-xs text-zinc-500">{{ $m->patrimonio }}</span>@endif</td>
                        <td class="num">{{ numero($m->quantidade) }}</td>
                        <td class="whitespace-nowrap text-zinc-600 dark:text-zinc-400">{{ \App\Http\Controllers\ManutencoesController::TIPOS[$m->tipo] ?? $m->tipo }}</td>
                        <td class="max-w-md text-sm text-zinc-600 dark:text-zinc-400">{{ mb_strimwidth($m->descricao, 0, 80, '…') }}</td>
                        <td class="tabular-nums whitespace-nowrap text-zinc-600 dark:text-zinc-400">{{ data_br($m->data_inicio) }}</td>
                        <td class="whitespace-nowrap">@if ($m->data_fim)<span class="tabular-nums text-zinc-600 dark:text-zinc-400">{{ data_br($m->data_fim) }}</span>@else<span class="selo-amarelo">em manutenção</span>@endif</td>
                        <td class="num">{{ $m->data_fim ? moeda($m->custo) : '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @include('partials.paginacao', ['p' => $manutencoes])
    @endif
</section>
@endsection
