{{-- Patrimônio do produto (na edição): unidades com número de patrimônio, série e situação --}}
@php
    $ativas = count(array_filter($unidades, fn ($u) => $u->ativa));
@endphp
<section id="patrimonio" class="cartao mt-6 scroll-mt-20 overflow-hidden">
    <div class="cartao-cabecalho">
        <div>
            <h2 class="cartao-titulo">Patrimônio (unidades)</h2>
            <p class="text-xs text-zinc-500">{{ numero($ativas) }} de {{ numero($p->qtd_total) }} peça(s) com número de patrimônio{{ $uso->manutencao > 0 ? ' · ' . numero($uso->manutencao) . ' em manutenção' : '' }}</p>
        </div>
        <div class="flex gap-2">
            <a class="btn-secundario" href="{{ route('manutencoes.create', ['produto' => $p->id]) }}"><x-icone nome="manutencao" class="size-4" /> Enviar para manutenção</a>
            @if ($ativas < $p->qtd_total)
                <a class="btn-primario" href="{{ route('unidades.create', $p->id) }}"><x-icone nome="mais" class="size-4" /> Nova unidade</a>
            @endif
        </div>
    </div>
    @if (!$unidades)
        <p class="px-5 py-6 text-sm text-zinc-500">Opcional: cadastre o número de patrimônio e a série de cada peça para saber qual unidade está com qual cliente
            e ver o histórico de locações e manutenções dela.</p>
    @else
        <div class="overflow-x-auto">
            <table class="tabela">
                <thead><tr><th>Patrimônio</th><th>Série</th><th>Situação</th><th>Aquisição</th><th class="w-px"></th></tr></thead>
                <tbody>
                @foreach ($unidades as $u)
                    <tr data-href="{{ route('unidades.show', $u->id) }}">
                        <td><a href="{{ route('unidades.show', $u->id) }}" class="font-mono font-medium text-zinc-900 dark:text-zinc-100">{{ $u->patrimonio }}</a></td>
                        <td class="text-zinc-600 dark:text-zinc-400">{{ $u->serie ?: '—' }}</td>
                        <td>@include('produtos._situacao_unidade', ['u' => $u])</td>
                        <td class="tabular-nums text-zinc-600 dark:text-zinc-400">{{ data_br($u->aquisicao_data) ?: '—' }}</td>
                        <td><a class="btn-icone" href="{{ route('unidades.show', $u->id) }}" title="Abrir" aria-label="Abrir unidade {{ $u->patrimonio }}"><x-icone nome="editar" class="size-[18px]" /></a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
