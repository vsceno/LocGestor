@extends('layouts.plataforma', ['titulo' => 'Números', 'ativo' => 'numeros', 'subtitulo' => 'Receita, locadoras novas e canceladas, mês a mês (últimos 12 meses).'])

@section('conteudo')
<div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <x-kpi rotulo="Receita do mês" :valor="moeda($receitaMes)" :detalhe="'mensalidades recebidas em ' . $mesAtual" ico="caixa"
           cor="bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400" />
    <x-kpi rotulo="Novas locadoras no mês" :valor="numero($novasMes)" :detalhe="'cadastradas em ' . $mesAtual" ico="clientes"
           cor="bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-400" />
    <x-kpi rotulo="Cancelamentos no mês" :valor="numero($canceladasMes)" :detalhe="'taxa de cancelamento ' . numero($taxa, 1) . '%'" ico="alerta"
           cor="bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-400" />
    <x-kpi rotulo="Ticket médio" :valor="moeda($ticket)" :detalhe="numero($pagantes) . ' locadora(s) pagante(s)'" ico="relatorios"
           cor="bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300" />
</div>

<div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-2">
    <section class="cartao p-5">
        <h2 class="cartao-titulo">Receita mensal</h2>
        <p class="mt-1 text-sm text-zinc-500">Mensalidades recebidas, mês a mês.</p>
        <div class="mt-4 h-72">
            <canvas role="img" aria-label="Receita mensal"
                    data-grafico="{{ json_encode(['tipo' => 'bar', 'rotulos' => $rotulos, 'moeda' => true,
                        'series' => [['nome' => 'Receita', 'cor' => '#1e8e3e', 'dados' => $receita]]], JSON_UNESCAPED_UNICODE) }}"></canvas>
        </div>
    </section>
    <section class="cartao p-5">
        <h2 class="cartao-titulo">Locadoras: entradas e saídas</h2>
        <p class="mt-1 text-sm text-zinc-500">Locadoras novas e contratos cancelados, por mês.</p>
        <div class="mt-4 h-72">
            <canvas role="img" aria-label="Locadoras novas e canceladas"
                    data-grafico="{{ json_encode(['tipo' => 'bar', 'rotulos' => $rotulos, 'moeda' => false,
                        'series' => [['nome' => 'Novas', 'cor' => '#1a73e8', 'dados' => $novas],
                                     ['nome' => 'Canceladas', 'cor' => '#c5221f', 'dados' => $canceladas]]], JSON_UNESCAPED_UNICODE) }}"></canvas>
        </div>
    </section>
</div>
@endsection

@section('scripts')
<script src="{{ asset('assets/vendor/chart.umd.js') }}"></script>
<script src="{{ asset('assets/js/graficos.js') }}"></script>
@endsection
