@extends('layouts.app', ['titulo' => 'Previsão de caixa', 'ativo' => 'caixa'])

@section('subtitulo', 'Quanto deve sobrar no caixa, semana a semana, se tudo for recebido e pago em dia.')

@section('conteudo')
@include('caixa._abas', ['ativa' => 'previsao'])

<div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
    <x-kpi rotulo="Saldo hoje" :valor="moeda($p['saldo'])" detalhe="tudo o que entrou menos o que saiu" ico="caixa"
           cor="bg-sky-50 text-sky-600 dark:bg-sky-500/10 dark:text-sky-400" />
    <x-kpi :rotulo="'Saldo em ' . numero($dias) . ' dias'" :valor="moeda($final)" detalhe="se tudo for recebido e pago em dia" ico="caixa"
           :cor="$final < 0 ? 'bg-red-50 text-red-600 dark:bg-red-500/10 dark:text-red-400' : 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400'" />
    <x-kpi rotulo="Menor saldo no período" :valor="moeda($menor)" :detalhe="$menor < 0 ? 'atenção: o caixa fica negativo' : 'o caixa não fica negativo'" ico="alerta"
           :cor="$menor < 0 ? 'bg-red-50 text-red-600 dark:bg-red-500/10 dark:text-red-400' : 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300'" />
    <x-kpi rotulo="Vencidos" :valor="moeda($p['receber_vencido'])" :detalhe="'a receber · ' . moeda($p['pagar_vencido']) . ' a pagar'" ico="relogio"
           cor="bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300" />
</div>

<section class="cartao mt-6 overflow-hidden">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-100 p-4 dark:border-zinc-800">
        <h2 class="cartao-titulo">Previsão semana a semana</h2>
        <form method="get" action="{{ route('caixa.previsao') }}" class="flex items-center gap-2">
            <select name="dias" aria-label="Horizonte" class="campo">
                @foreach ([30 => 'Próximos 30 dias', 60 => 'Próximos 60 dias', 90 => 'Próximos 90 dias'] as $valor => $rotulo)<option value="{{ $valor }}" @selected($dias === $valor)>{{ $rotulo }}</option>@endforeach
            </select>
            <button class="btn-secundario">Ver</button>
        </form>
    </div>
    <div class="space-y-4 p-5">
        <p class="text-xs text-zinc-500">Entradas: saldo das locações em aberto na data prevista de devolução (as atrasadas e as devolvidas com saldo, nesta semana)
            e reservas ativas estimadas pelos preços atuais. Saídas: contas a pagar em aberto (as vencidas, nesta semana).</p>
        <div class="h-64"><canvas data-grafico="{{ json_encode($grafico, JSON_UNESCAPED_UNICODE) }}"></canvas></div>
    </div>
    <div class="overflow-x-auto">
        <table class="tabela">
            <thead><tr><th>Semana</th><th class="num">Entradas previstas</th><th class="num">Saídas previstas</th><th class="num">Saldo projetado</th></tr></thead>
            <tbody>
            @foreach ($p['semanas'] as $s)
                <tr>
                    <td class="whitespace-nowrap tabular-nums">{{ data_br($s['inicio']) }} a {{ data_br($s['fim']) }}</td>
                    <td class="num whitespace-nowrap">{{ moeda($s['entradas']) }}</td>
                    <td class="num whitespace-nowrap text-red-600 dark:text-red-400">{{ moeda($s['saidas']) }}</td>
                    <td class="num whitespace-nowrap font-semibold {{ $s['saldo'] < 0 ? 'text-red-600 dark:text-red-400' : '' }}">{{ moeda($s['saldo']) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</section>

<section class="cartao mt-6 overflow-hidden">
    <div class="cartao-cabecalho"><h2 class="cartao-titulo">O que compõe a previsão</h2></div>
    <div class="overflow-x-auto">
        <table class="tabela">
            <thead><tr><th>Data</th><th>Descrição</th><th class="num">Entra</th><th class="num">Sai</th></tr></thead>
            <tbody>
            @forelse ($p['itens'] as $x)
                <tr>
                    <td class="whitespace-nowrap tabular-nums">{{ data_br($x['data']) }}@if ($x['vencido']) <span class="selo-vermelho">vencido</span>@endif</td>
                    <td class="text-zinc-900 dark:text-zinc-100">{{ $x['descricao'] }}</td>
                    <td class="num whitespace-nowrap">{{ $x['tipo'] === 'E' ? moeda($x['valor']) : '' }}</td>
                    <td class="num whitespace-nowrap text-red-600 dark:text-red-400">{{ $x['tipo'] === 'S' ? moeda($x['valor']) : '' }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="py-10 text-center text-sm text-zinc-500">Nada previsto no período.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection

@section('scripts')
    <script src="{{ asset_legado('assets/vendor/chart.umd.js') }}"></script>
    <script src="{{ asset_legado('assets/js/graficos.js') }}"></script>
@endsection
