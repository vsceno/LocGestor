@extends('layouts.app', ['titulo' => 'Reservas', 'ativo' => 'reservas'])

@section('subtitulo', 'Equipamentos garantidos para uma data futura. Na retirada, a reserva vira locação.')

@section('acoes')
    <a class="btn-primario" href="{{ route('reservas.create') }}"><x-icone nome="mais" class="size-4" /> Nova reserva</a>
@endsection

@section('conteudo')
<section class="cartao overflow-hidden">
    <nav class="flex gap-1 border-b border-zinc-100 p-4 dark:border-zinc-800" aria-label="Situação">
        <div class="flex gap-1 rounded-xl bg-zinc-100 p-1 dark:bg-zinc-800">
            @foreach ($abas as $valor => $rotulo)
                <a href="{{ route('reservas.index', ['aba' => $valor]) }}" @if ($valor === $aba) aria-current="page" @endif
                   class="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium transition
                          {{ $valor === $aba ? 'bg-white text-zinc-900 shadow-xs dark:bg-zinc-900 dark:text-zinc-100' : 'text-zinc-500 hover:text-zinc-900 dark:hover:text-zinc-100' }}">
                    {{ $rotulo }}
                    <span class="rounded-md px-1.5 text-xs tabular-nums {{ $valor === $aba ? 'bg-marca-100 text-marca-700 dark:bg-marca-500/15 dark:text-marca-400' : 'bg-zinc-200/70 dark:bg-zinc-700' }}">{{ numero($contagem->$valor) }}</span>
                </a>
            @endforeach
        </div>
    </nav>
    @if ($reservas->total() === 0)
        <div class="px-6 py-16 text-center">
            <span class="mx-auto grid size-12 place-items-center rounded-full bg-marca-50 text-marca-600 dark:bg-marca-500/10"><x-icone nome="reservas" /></span>
            <p class="mt-3 font-medium">Nenhuma reserva nesta aba</p>
            <p class="mt-1 text-sm text-zinc-500">A reserva bloqueia o estoque no período escolhido.</p>
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="tabela">
                <thead><tr><th>Nº</th><th>Cliente</th><th>Retirada</th><th>Devolução</th><th>Itens</th><th>Situação</th><th class="w-px"></th></tr></thead>
                <tbody>
                @foreach ($reservas as $r)
                    @php [$rotulo, $selo] = \App\Http\Controllers\ReservasController::situacao($r); @endphp
                    <tr data-href="{{ route('reservas.show', $r->numero) }}">
                        <td><a href="{{ route('reservas.show', $r->numero) }}" class="font-semibold tabular-nums text-zinc-900 dark:text-zinc-100">{{ $r->numero }}</a></td>
                        <td class="font-medium text-zinc-900 dark:text-zinc-100">{{ $r->cliente }}</td>
                        <td class="tabular-nums whitespace-nowrap text-zinc-600 dark:text-zinc-400">{{ data_br($r->data_inicio) }}</td>
                        <td class="tabular-nums whitespace-nowrap text-zinc-600 dark:text-zinc-400">{{ data_br($r->data_fim) }}</td>
                        <td class="max-w-md text-sm text-zinc-600 dark:text-zinc-400">{{ $r->itens }}</td>
                        <td class="whitespace-nowrap"><span class="{{ $selo }}">{{ $rotulo }}</span>
                            @if ($r->locacao)<a class="ml-1 text-sm text-marca-700 hover:underline dark:text-marca-400" href="{{ route('locacoes.show', $r->locacao) }}">locação nº {{ $r->locacao }}</a>@endif</td>
                        <td>@if ($r->status === 'ativa')<a class="btn-primario whitespace-nowrap" href="{{ route('locacoes.create', ['reserva' => $r->id]) }}">Retirar</a>@endif</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @include('partials.paginacao', ['p' => $reservas])
    @endif
</section>
@endsection
