@if ($produtos->total() === 0)
    <div class="px-6 py-16 text-center">
        <span class="mx-auto grid size-12 place-items-center rounded-full bg-marca-50 text-marca-600 dark:bg-marca-500/10"><x-icone nome="produtos" /></span>
        @if ($q !== '' || $categoria !== '')
            <p class="mt-3 font-medium">Nenhum produto com esse filtro</p>
            <p class="mt-1 text-sm text-zinc-500">Confira a grafia, busque pelo código ou escolha outra categoria.</p>
        @else
            <p class="mt-3 font-medium">Nenhum produto nesta situação</p>
            <p class="mt-1 text-sm text-zinc-500">Os equipamentos cadastrados aparecem aqui.</p>
        @endif
        <a class="btn-primario mt-5" href="{{ route('produtos.create') }}"><x-icone nome="mais" class="size-4" /> Cadastrar produto</a>
    </div>
@else
    <div class="overflow-x-auto">
        <table class="tabela">
            <thead><tr>
                <th>Produto</th><th>Preço</th>
                <th class="num">Estoque</th><th class="num">Alugado</th><th class="num" title="Reservas ativas para hoje">Reservado</th>
                <th class="num">Manutenção</th><th class="num">Disponível</th><th class="w-px"></th>
            </tr></thead>
            <tbody>
            @foreach ($produtos as $p)
                @php
                    // Igual ao selo do sistema antigo: vermelho sem estoque, amarelo com 20% ou menos, verde no resto
                    $selo = $p->disponivel <= 0 ? 'selo-vermelho' : ($p->disponivel <= max(1, (int) ceil($p->qtd_total * 0.2)) ? 'selo-amarelo' : 'selo-verde');
                @endphp
                <tr data-href="{{ route('produtos.edit', $p->id) }}">
                    <td>
                        <a href="{{ route('produtos.edit', $p->id) }}" class="block min-w-0">
                            <span class="flex items-center gap-2">
                                <span class="truncate font-medium text-zinc-900 dark:text-zinc-100">{{ $p->descricao }}</span>
                                @unless ($p->ativo)<span class="selo-cinza">inativo</span>@endunless
                            </span>
                            <span class="block text-xs text-zinc-500"><span class="font-mono">{{ $p->codigo }}</span>{{ $p->categoria ? ' · ' . $p->categoria : '' }}</span>
                        </a>
                    </td>
                    <td class="whitespace-nowrap text-sm tabular-nums">
                        <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ moeda($p->valor_diaria) }}</span><span class="text-zinc-500">/dia</span>
                        @foreach (['valor_semanal' => 'semana', 'valor_quinzenal' => 'quinzena', 'valor_mensal' => 'mês'] as $campo => $per)
                            @if ($p->$campo > 0)<span class="block text-xs text-zinc-500">{{ moeda($p->$campo) }}/{{ $per }}</span>@endif
                        @endforeach
                    </td>
                    <td class="num">{{ numero($p->qtd_total) }} <span class="text-xs text-zinc-400">{{ $p->unidade }}</span></td>
                    <td class="num {{ $p->alugado > 0 ? '' : 'text-zinc-400' }}">{{ numero($p->alugado) }}</td>
                    <td class="num {{ $p->reservado > 0 ? '' : 'text-zinc-400' }}">{{ numero($p->reservado) }}</td>
                    <td class="num">
                        @if ($p->manutencao > 0)<a class="text-marca-700 underline-offset-2 hover:underline dark:text-marca-400" href="{{ legado('manutencoes') }}">{{ numero($p->manutencao) }}</a>
                        @else<span class="text-zinc-400">0</span>@endif
                    </td>
                    <td class="num"><span class="{{ $selo }}">{{ numero($p->disponivel) }}</span></td>
                    <td>
                        <a class="btn-icone" href="{{ route('produtos.edit', $p->id) }}" title="Editar" aria-label="Editar {{ $p->descricao }}">
                            <x-icone nome="editar" class="size-[18px]" /></a>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    @include('partials.paginacao', ['p' => $produtos])
@endif
