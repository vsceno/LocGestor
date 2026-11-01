@extends('layouts.app', ['titulo' => 'Orçamentos', 'ativo' => 'orcamentos'])

@section('subtitulo', 'Cotação com validade. Não reserva equipamento: para garantir, converta em reserva (ou em locação, se já saiu).')

@section('acoes')
    <a class="btn-primario" href="{{ route('orcamentos.create') }}"><x-icone nome="mais" class="size-4" /> Novo orçamento</a>
@endsection

@section('conteudo')
@php $hoje = now()->toDateString(); @endphp
<section class="cartao overflow-hidden">
    <nav class="border-b border-zinc-100 p-4 dark:border-zinc-800" aria-label="Situação">
        <div class="inline-flex gap-1 rounded-xl bg-zinc-100 p-1 dark:bg-zinc-800">
            @foreach ($situacoes as $valor => $rotulo)
                <a href="{{ route('orcamentos.index', ['situacao' => $valor]) }}" @if ($valor === $situacao) aria-current="page" @endif
                   class="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium transition
                          {{ $valor === $situacao ? 'bg-white text-zinc-900 shadow-xs dark:bg-zinc-900 dark:text-zinc-100' : 'text-zinc-500 hover:text-zinc-900 dark:hover:text-zinc-100' }}">
                    {{ $rotulo }}
                    <span class="rounded-md px-1.5 text-xs tabular-nums {{ $valor === $situacao ? 'bg-marca-100 text-marca-700 dark:bg-marca-500/15 dark:text-marca-400' : 'bg-zinc-200/70 dark:bg-zinc-700' }}">{{ numero($contagem->$valor) }}</span>
                </a>
            @endforeach
        </div>
    </nav>
    @if ($orcamentos->total() === 0)
        <div class="px-6 py-16 text-center">
            <span class="mx-auto grid size-12 place-items-center rounded-full bg-marca-50 text-marca-600 dark:bg-marca-500/10"><x-icone nome="orcamentos" /></span>
            <p class="mt-3 font-medium">Nenhum orçamento nesta situação</p>
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="tabela">
                <thead><tr><th>Nº</th><th>Cliente</th><th>Período</th><th>Validade</th><th class="num">Valor Total</th><th>Situação</th></tr></thead>
                <tbody>
                @foreach ($orcamentos as $o)
                    <tr data-href="{{ route('orcamentos.show', $o->id) }}">
                        <td><a href="{{ route('orcamentos.show', $o->id) }}" class="font-semibold tabular-nums text-zinc-900 dark:text-zinc-100">{{ $o->numero }}</a></td>
                        <td class="font-medium text-zinc-900 dark:text-zinc-100">{{ $o->nome }}</td>
                        <td class="tabular-nums whitespace-nowrap text-zinc-600 dark:text-zinc-400">{{ data_br($o->data_saida) }} a {{ data_br($o->data_prev_devolucao) }}</td>
                        <td class="tabular-nums whitespace-nowrap text-zinc-600 dark:text-zinc-400">{{ data_br($o->validade) }}
                            @if ($o->status === 'aberto' && $o->validade < $hoje)<span class="selo-vermelho ml-1">vencido</span>@endif</td>
                        <td class="num whitespace-nowrap font-medium">{{ moeda($o->previsto) }}</td>
                        <td class="whitespace-nowrap">
                            @if ($o->status === 'convertido')
                                <span class="selo-verde">{{ $o->locacao_id ? 'locação nº ' . $o->locacao_numero : 'reserva nº ' . $o->reserva_numero }}</span>
                            @else<span class="selo-azul">em aberto</span>@endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @include('partials.paginacao', ['p' => $orcamentos])
    @endif
</section>
@endsection
