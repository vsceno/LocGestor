{{-- Tabela de um relatório (tela e impressão): colunas tipadas, total no fim quando há colunas somadas --}}
@php $ehNumero = fn (string $tipo) => in_array($tipo, ['num', 'money', 'pct'], true); @endphp
<div class="overflow-x-auto">
    <table class="tabela">
        <thead>
            <tr>
                @foreach ($rel['colunas'] as $campo => [$rotulo, $tipo])
                    <th @class(['num' => $ehNumero($tipo)])>{{ $rotulo }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($linhas as $l)
                <tr>
                    @foreach ($rel['colunas'] as $campo => [$rotulo, $tipo])
                        <td @class(['num' => $ehNumero($tipo), 'whitespace-nowrap' => $tipo === 'date'])>
                            @if ($tipo === 'link')
                                <a class="font-medium text-marca-700 hover:underline dark:text-marca-400" href="{{ route('locacoes.show', $l[$campo]) }}">{{ $l[$campo] }}</a>
                            @else
                                {{ \App\Support\Relatorios::celula($tipo, $l[$campo]) }}
                            @endif
                        </td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($rel['colunas']) }}" class="py-10 text-center text-sm text-zinc-500">Nenhum registro.</td></tr>
            @endforelse
        </tbody>
        @if ($totais && count($linhas) > 0)
            <tfoot>
                <tr>
                    @php $primeira = true; @endphp
                    @foreach ($rel['colunas'] as $campo => [$rotulo, $tipo])
                        <td @class(['num' => $ehNumero($tipo)])>
                            @if (isset($totais[$campo]))
                                {{ \App\Support\Relatorios::celula($tipo, $totais[$campo]) }}
                            @elseif ($primeira)
                                Total ({{ numero(count($linhas)) }})
                            @endif
                        </td>
                        @php $primeira = false; @endphp
                    @endforeach
                </tr>
            </tfoot>
        @endif
    </table>
</div>
