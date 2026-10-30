@php [$rotulo, $cor] = \App\Http\Controllers\UnidadesController::SITUACOES[$u->situacao]; @endphp
<span class="inline-flex items-center gap-2 whitespace-nowrap">
    <span class="{{ $cor }}">{{ $rotulo }}</span>
    @if ($u->situacao === 'na_rua')
        <a class="text-sm text-marca-700 underline-offset-2 hover:underline dark:text-marca-400" href="{{ route('locacoes.show', $u->locacao) }}">locação nº {{ $u->locacao }}</a>
    @elseif ($u->situacao === 'manutencao')
        <a class="text-sm text-marca-700 underline-offset-2 hover:underline dark:text-marca-400" href="{{ legado('manutencoes', ['a' => 'ver', 'id' => $u->manutencao_id]) }}">manutenção nº {{ $u->manutencao }}</a>
    @endif
</span>
