@php
    $hoje = now()->toDateString();
    $vencido = $o->status === 'aberto' && $o->validade < $hoje;
    $periodos = ['diaria' => ['Diária', 'dia'], 'semanal' => ['Semanal', 'semana'], 'quinzenal' => ['Quinzenal', 'quinzena'], 'mensal' => ['Mensal', 'mês']];
@endphp
@extends('layouts.app', ['titulo' => 'Orçamento nº ' . $o->numero, 'ativo' => 'orcamentos', 'migalhas' => [['Orçamentos', route('orcamentos.index')]]])

@section('subtitulo')
    <span class="inline-flex flex-wrap items-center gap-2">
        @if ($o->status === 'convertido')
            <a class="selo-verde" href="{{ $o->locacao_id ? route('locacoes.show', $o->locacao_numero) : route('reservas.show', $o->reserva_numero) }}">
                {{ $o->locacao_id ? 'convertido em locação nº ' . $o->locacao_numero : 'convertido em reserva nº ' . $o->reserva_numero }}</a>
        @elseif ($vencido)<span class="selo-vermelho">vencido</span>
        @else<span class="selo-azul">em aberto</span>@endif
        <a class="font-medium text-zinc-700 hover:underline dark:text-zinc-300" href="{{ route('clientes.show', $o->cliente_id) }}">{{ $o->cliente_nome }}</a>
    </span>
@endsection

@section('acoes')
    <a class="btn-secundario" href="{{ route('orcamentos.imprimir', $o->id) }}" target="_blank" rel="noopener">Imprimir</a>
    @if ($whatsapp)
        <a class="btn border border-emerald-200 bg-emerald-50 text-emerald-800 hover:bg-emerald-100 dark:border-emerald-900 dark:bg-emerald-500/10 dark:text-emerald-300"
           href="{{ $whatsapp }}" target="_blank" rel="noopener"><x-icone nome="whatsapp" class="size-4" /> Enviar no WhatsApp</a>
    @endif
@endsection

@section('conteudo')
<div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
    <div class="space-y-6 xl:col-span-2">
        <section class="cartao overflow-hidden">
            <div class="cartao-cabecalho"><h2 class="cartao-titulo">Itens</h2><span class="text-sm">Valor Total <strong class="ml-1 tabular-nums">{{ moeda($o->previsto) }}</strong></span></div>
            <div class="overflow-x-auto">
                <table class="tabela">
                    <thead><tr><th>Produto</th><th class="num">Qtd.</th><th>Período</th></tr></thead>
                    <tbody>
                    @foreach ($itens as $i)
                        @php $per = $periodos[$i->periodo] ?? null; $preco = $i->periodo === 'diaria' || !$per ? $i->valor_diaria : $i->{'valor_' . $i->periodo}; @endphp
                        <tr><td><span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $i->descricao }}</span><span class="block font-mono text-xs text-zinc-500">{{ $i->codigo }}</span></td>
                            <td class="num">{{ numero($i->quantidade) }} <span class="text-xs text-zinc-400">{{ $i->unidade }}</span></td>
                            <td class="whitespace-nowrap">@if ($per){{ $per[0] }}<span class="block text-xs text-zinc-500">{{ moeda($preco) }}/{{ $per[1] }}</span>
                                @else Automático<span class="block text-xs text-zinc-500">{{ moeda($i->valor_diaria) }}/dia</span>@endif</td></tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>
        <section class="cartao">
            <div class="cartao-cabecalho"><h2 class="cartao-titulo">Dados</h2></div>
            <dl class="grid grid-cols-1 gap-x-6 gap-y-4 p-5 text-sm sm:grid-cols-2">
                @foreach (array_filter([
                    ['Período', data_br($o->data_saida) . ' a ' . data_br($o->data_prev_devolucao)],
                    ['Validade', data_br($o->validade)],
                    $o->local_uso !== '' ? ['Local de uso', $o->local_uso] : null,
                    ['Acréscimo', moeda($o->acrescimo)], ['Frete', moeda($o->frete_entrega)], ['Frete de recolhimento', moeda($o->frete_recolhimento)],
                    ['Desconto', ($o->desconto > 0 ? '− ' : '') . moeda($o->desconto)], ['Emissão', data_br($o->emissao)],
                ]) as [$rot, $val])
                    <div><dt class="text-xs text-zinc-500">{{ $rot }}</dt><dd class="mt-0.5 font-medium tabular-nums">{{ $val }}</dd></div>
                @endforeach
                @if ($o->obs !== '')<div class="sm:col-span-2"><dt class="text-xs text-zinc-500">Observações</dt><dd class="mt-1 rounded-lg bg-zinc-50 p-3 whitespace-pre-line dark:bg-zinc-950">{{ $o->obs }}</dd></div>@endif
            </dl>
        </section>
    </div>
    <div class="space-y-6">
        @if ($o->status === 'aberto')
            <section class="cartao p-5">
                <h2 class="cartao-titulo">Converter</h2>
                <p class="mt-1 text-xs text-zinc-500">Ao converter, o orçamento fica registrado e não pode mais ser alterado.</p>
                @if ($vencido)
                    <p class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950/50 dark:text-red-300">Vencido em {{ data_br($o->validade) }}. Faça um novo orçamento.</p>
                @else
                    <div class="mt-3 flex flex-col gap-2">
                        @if ($o->data_saida <= $hoje)
                            <form method="post" action="{{ route('orcamentos.converter', $o->id) }}" data-confirmar="Virar locação? O equipamento sai agora.">
                                @csrf <input type="hidden" name="tipo" value="locacao">
                                <button class="btn-primario w-full justify-center"><x-icone nome="locacoes" class="size-4" /> Transformar em locação</button>
                            </form>
                        @else
                            <p class="text-xs text-zinc-500">A saída é no futuro: só dá para transformar em reserva.</p>
                        @endif
                        <form method="post" action="{{ route('orcamentos.converter', $o->id) }}" data-confirmar="Transformar em reserva? Os equipamentos ficam garantidos no período.">
                            @csrf <input type="hidden" name="tipo" value="reserva">
                            <button class="btn-secundario w-full justify-center"><x-icone nome="reservas" class="size-4" /> Transformar em reserva</button>
                        </form>
                    </div>
                @endif
            </section>
            <section class="cartao border-red-200 p-5 dark:border-red-900/60">
                <h2 class="text-sm font-semibold text-red-700 dark:text-red-400">Excluir orçamento</h2>
                <form method="post" action="{{ route('orcamentos.destroy', $o->id) }}" class="mt-3" data-confirmar="Excluir este orçamento?">
                    @csrf @method('DELETE')
                    <button class="btn w-full justify-center border border-red-200 bg-white text-red-600 hover:bg-red-50 dark:border-red-900 dark:bg-transparent dark:hover:bg-red-950">Excluir</button>
                </form>
            </section>
        @endif
    </div>
</div>
@endsection
