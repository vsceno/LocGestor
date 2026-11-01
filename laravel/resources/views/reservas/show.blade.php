@php
    [$rotulo, $selo] = \App\Http\Controllers\ReservasController::situacao($r);
    $periodos = ['diaria' => ['Diária', 'dia'], 'semanal' => ['Semanal', 'semana'], 'quinzenal' => ['Quinzenal', 'quinzena'], 'mensal' => ['Mensal', 'mês']];
@endphp
@extends('layouts.app', ['titulo' => 'Reserva nº ' . $r->numero, 'ativo' => 'reservas', 'migalhas' => [['Reservas', route('reservas.index')]]])

@section('subtitulo')
    <span class="inline-flex flex-wrap items-center gap-2"><span class="{{ $selo }}">{{ $rotulo }}</span>
        <a class="font-medium text-zinc-700 hover:underline dark:text-zinc-300" href="{{ route('clientes.show', $r->cliente_id) }}">{{ $r->cliente_nome }}</a></span>
@endsection

@section('acoes')
    @if ($r->status === 'ativa')
        <a class="btn-secundario" href="{{ route('reservas.edit', $r->numero) }}"><x-icone nome="editar" class="size-4" /> Editar</a>
        <a class="btn-primario" href="{{ route('locacoes.create', ['reserva' => $r->id]) }}"><x-icone nome="locacoes" class="size-4" /> Retirar → locação</a>
    @endif
@endsection

@section('conteudo')
<div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
    <div class="space-y-6 xl:col-span-2">
        <section class="cartao overflow-hidden">
            <div class="cartao-cabecalho"><h2 class="cartao-titulo">Itens reservados</h2></div>
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
            <div class="cartao-cabecalho"><h2 class="cartao-titulo">Dados da reserva</h2></div>
            <dl class="grid grid-cols-1 gap-x-6 gap-y-4 p-5 text-sm sm:grid-cols-2">
                <div><dt class="text-xs text-zinc-500">Retirada</dt><dd class="mt-0.5 font-medium tabular-nums">{{ data_br($r->data_inicio) }}</dd></div>
                <div><dt class="text-xs text-zinc-500">Devolução</dt><dd class="mt-0.5 font-medium tabular-nums">{{ data_br($r->data_fim) }} ({{ numero($dias) }} dia(s))</dd></div>
                <div><dt class="text-xs text-zinc-500">Valor estimado</dt><dd class="mt-0.5 font-medium tabular-nums">{{ moeda($estimado) }} <span class="font-normal text-zinc-500">(pelos preços atuais)</span></dd></div>
                <div><dt class="text-xs text-zinc-500">Registrada em</dt><dd class="mt-0.5 font-medium tabular-nums">{{ data_br($r->criado_em, true) }}</dd></div>
                @if ($locacao)<div><dt class="text-xs text-zinc-500">Locação</dt><dd class="mt-0.5"><a class="font-medium text-marca-700 hover:underline dark:text-marca-400" href="{{ route('locacoes.show', $locacao) }}">nº {{ $locacao }}</a></dd></div>@endif
                @if ($r->obs !== '')<div class="sm:col-span-2"><dt class="text-xs text-zinc-500">Observações</dt>
                    <dd class="mt-1 rounded-lg bg-zinc-50 p-3 whitespace-pre-line dark:bg-zinc-950">{{ $r->obs }}</dd></div>@endif
            </dl>
        </section>
    </div>
    <div class="space-y-6">
        @if ($r->status === 'ativa')
            <section class="cartao p-5">
                <h2 class="cartao-titulo">Confirmar com o cliente</h2>
                @if ($whatsapp)
                    <a class="btn mt-3 w-full justify-center border border-emerald-200 bg-emerald-50 text-emerald-800 hover:bg-emerald-100 dark:border-emerald-900 dark:bg-emerald-500/10 dark:text-emerald-300"
                       href="{{ $whatsapp }}" target="_blank" rel="noopener"><x-icone nome="whatsapp" class="size-4" /> Confirmar no WhatsApp</a>
                @else
                    <p class="mt-2 text-sm text-zinc-500">Cadastre o celular do cliente para usar o WhatsApp.</p>
                @endif
                <p class="mt-3 text-xs text-zinc-500">“Retirar” abre uma nova locação com saída hoje, já preenchida com estes itens.</p>
            </section>
            <section class="cartao border-red-200 p-5 dark:border-red-900/60">
                <h2 class="text-sm font-semibold text-red-700 dark:text-red-400">Cancelar reserva</h2>
                <p class="mt-1 text-xs text-zinc-500">Os equipamentos voltam a ficar disponíveis no período.</p>
                <form method="post" action="{{ route('reservas.cancelar', $r->numero) }}" class="mt-3" data-confirmar="Cancelar a reserva nº {{ $r->numero }}?">
                    @csrf
                    <button class="btn w-full justify-center border border-red-200 bg-white text-red-600 hover:bg-red-50 dark:border-red-900 dark:bg-transparent dark:hover:bg-red-950">Cancelar reserva</button>
                </form>
            </section>
        @else
            <section class="cartao p-5"><p class="text-sm text-zinc-500">Reserva {{ $r->status }}: nenhuma ação disponível.</p></section>
        @endif
    </div>
</div>
@endsection
