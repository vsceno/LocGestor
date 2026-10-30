@extends('layouts.app', ['titulo' => $rel['titulo'], 'ativo' => 'relatorios', 'migalhas' => [['Relatórios', route('relatorios.index')]]])

@section('subtitulo', $rel['descricao'])

@section('acoes')
    <a class="btn-secundario" href="{{ route('relatorios.ver', array_merge($params, ['imprimir' => 1])) }}" target="_blank" rel="noopener">Imprimir</a>
    <a class="btn-secundario" href="{{ route('relatorios.ver', array_merge($params, ['csv' => 1])) }}">Exportar CSV</a>
@endsection

@section('conteudo')
<section class="cartao mb-6 p-5">
    @if ($rel['periodo'])
        <form method="get" action="{{ route('relatorios.ver', $chave) }}" class="flex flex-wrap items-end gap-3">
            <label class="block"><span class="text-xs text-zinc-500">De</span><input type="date" name="de" value="{{ $de }}" class="campo mt-1"></label>
            <label class="block"><span class="text-xs text-zinc-500">Até</span><input type="date" name="ate" value="{{ $ate }}" class="campo mt-1"></label>
            <button class="btn-secundario">Atualizar</button>
        </form>
    @else
        <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $subtitulo }}</p>
    @endif
</section>

@if ($cfg)
    <section class="cartao mb-6 p-5">
        <div class="h-72"><canvas data-grafico="{{ json_encode($cfg, JSON_UNESCAPED_UNICODE) }}" role="img" aria-label="Gráfico do relatório"></canvas></div>
    </section>
@endif

<section class="cartao overflow-hidden">
    @include('relatorios._tabela', ['rel' => $rel, 'linhas' => $linhas, 'totais' => $totais])
</section>
@endsection

@section('scripts')
    @if ($cfg)
        <script src="{{ asset_legado('assets/vendor/chart.umd.js') }}"></script>
        <script src="{{ asset_legado('assets/js/graficos.js') }}"></script>
    @endif
@endsection
