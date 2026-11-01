@if ($locacoes->total() === 0)
    <div class="px-6 py-16 text-center">
        <span class="mx-auto grid size-12 place-items-center rounded-full bg-marca-50 text-marca-600 dark:bg-marca-500/10"><x-icone nome="locacoes" /></span>
        <p class="mt-3 font-medium">{{ $q !== '' ? "Nenhuma locação para “{$q}”" : 'Nenhuma locação nesta aba' }}</p>
        <p class="mt-1 text-sm text-zinc-500">{{ $q !== '' ? 'Busque pelo número da locação ou por parte do nome do cliente.' : 'As locações aparecem aqui conforme a situação.' }}</p>
    </div>
@else
    <div class="overflow-x-auto">
        <table class="tabela">
            <thead><tr>
                <th>Nº</th><th>Cliente</th><th>Saída</th><th>Devolução prevista</th><th>Situação</th>
                <th class="num">Na rua</th><th class="num">Valor</th><th class="num">Pago</th><th class="num">Saldo</th>
                @if ($lembrete)<th class="w-px"></th>@endif
            </tr></thead>
            <tbody>
            @foreach ($locacoes as $l)
                @php
                    [$rotulo, $selo] = \App\Support\Locacoes::situacao($l);
                    $saldo = \App\Support\Locacoes::saldo($l);
                @endphp
                <tr data-href="{{ route('locacoes.show', $l->numero) }}">
                    <td><a href="{{ route('locacoes.show', $l->numero) }}" class="font-semibold tabular-nums text-zinc-900 dark:text-zinc-100">{{ $l->numero }}</a></td>
                    <td class="max-w-64 truncate font-medium text-zinc-900 dark:text-zinc-100">{{ $l->cliente_nome }}</td>
                    <td class="tabular-nums whitespace-nowrap text-zinc-600 dark:text-zinc-400">{{ data_br($l->data_saida) }}</td>
                    <td class="tabular-nums whitespace-nowrap text-zinc-600 dark:text-zinc-400">{{ data_br($l->data_prev_devolucao) }}</td>
                    <td><span class="{{ $selo }}">{{ $rotulo }}</span></td>
                    <td class="num {{ $l->pendentes > 0 ? '' : 'text-zinc-400' }}">{{ numero($l->pendentes) }}</td>
                    <td class="num whitespace-nowrap">{{ moeda($l->valor_atual) }}</td>
                    <td class="num whitespace-nowrap text-zinc-600 dark:text-zinc-400">{{ moeda($l->valor_pago) }}</td>
                    <td class="num whitespace-nowrap font-medium {{ $saldo > 0.004 ? 'text-red-600 dark:text-red-400' : 'text-zinc-400' }}">{{ moeda($saldo) }}</td>
                    @if ($lembrete)
                        <td>
                            @if ($l->lembrete)
                                <a class="btn-icone hover:bg-emerald-50 hover:text-emerald-600 dark:hover:bg-emerald-500/10" href="{{ $l->lembrete }}" target="_blank" rel="noopener"
                                   title="Lembrete de devolução pelo WhatsApp" aria-label="Lembrete de devolução da locação {{ $l->numero }} pelo WhatsApp"><x-icone nome="whatsapp" class="size-[18px]" /></a>
                            @endif
                        </td>
                    @endif
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    @include('partials.paginacao', ['p' => $locacoes])
@endif
