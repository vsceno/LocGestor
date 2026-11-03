@extends('layouts.plataforma', ['titulo' => 'Números', 'ativo' => 'numeros', 'subtitulo' => 'Receita, locadoras novas e canceladas, mês a mês (últimos 12 meses), e a situação das assinaturas.'])

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

<div class="mt-10">
    <h2 class="text-base font-semibold">Assinaturas e inadimplência</h2>
    <p class="mt-1 text-sm text-zinc-500">Quanto as locadoras pagam por mês, o que está em atraso e quantas pagam depois do teste grátis.</p>
</div>

<div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <x-kpi rotulo="Receita recorrente (MRR)" :valor="moeda($fin['mrr'])" :detalhe="'ARR: ' . moeda($fin['arr']) . ' por ano'" ico="caixa"
           cor="bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400" />
    <x-kpi rotulo="Cancelado no mês" :valor="moeda($fin['churnValor'])" :detalhe="numero($fin['churnQuantidade']) . ' locadora(s) cancelada(s)'" ico="alerta"
           cor="bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-400" />
    <x-kpi rotulo="Inadimplência" :valor="moeda($fin['inadimplencia']['valor'])" :detalhe="numero($fin['inadimplencia']['quantidade']) . ' cobrança(s) vencida(s) e não paga(s)'" ico="alerta"
           cor="bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400" />
    <x-kpi rotulo="Teste grátis que virou pagamento" :valor="numero($fin['teste']['taxa'], 1) . '%'"
           :detalhe="numero($fin['teste']['pagaram']) . ' de ' . numero($fin['teste']['encerrados']) . ' que acabaram o teste · ' . numero($fin['teste']['emTeste']) . ' ainda em teste'"
           ico="clientes" cor="bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-400" />
</div>

<div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-2">
    <section class="cartao p-5">
        <h2 class="cartao-titulo">Receita recorrente (MRR)</h2>
        <p class="mt-1 text-sm text-zinc-500">Mensalidades das locadoras não canceladas, no fim de cada mês.</p>
        <div class="mt-4 h-72">
            <canvas role="img" aria-label="Receita recorrente por mês"
                    data-grafico="{{ json_encode(['tipo' => 'bar', 'rotulos' => $fin['mrrRotulos'], 'moeda' => true,
                        'series' => [['nome' => 'MRR', 'cor' => '#1e8e3e', 'dados' => $fin['mrrSerie']]]], JSON_UNESCAPED_UNICODE) }}"></canvas>
        </div>
    </section>
    <section class="cartao p-5">
        <h2 class="cartao-titulo">Inadimplência por atraso</h2>
        <p class="mt-1 text-sm text-zinc-500">Cobranças do Asaas vencidas e não pagas, pelos dias de atraso.</p>
        <div class="mt-4 h-72">
            <canvas role="img" aria-label="Inadimplência por faixa de atraso"
                    data-grafico="{{ json_encode(['tipo' => 'bar', 'rotulos' => array_column($fin['inadimplencia']['faixas'], 'rotulo'), 'moeda' => true,
                        'series' => [['nome' => 'Valor em atraso', 'cor' => '#c5221f', 'dados' => array_column($fin['inadimplencia']['faixas'], 'valor')]]], JSON_UNESCAPED_UNICODE) }}"></canvas>
        </div>
    </section>
</div>

<div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-2">
    <section class="cartao">
        <div class="cartao-cabecalho"><div><h2 class="cartao-titulo">Cobranças do mês</h2>
            <p class="text-xs text-zinc-500">Cobranças com vencimento neste mês.</p></div></div>
        <table class="tabela">
            <thead><tr><th>Situação</th><th class="num">Quantidade</th><th class="num">Valor</th></tr></thead>
            <tbody>
                @foreach ($fin['cobrancasMes'] as $situacao)
                    <tr><td>{{ $situacao['rotulo'] }}</td><td class="num">{{ numero($situacao['quantidade']) }}</td><td class="num">{{ moeda($situacao['valor']) }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </section>
    <section class="cartao">
        <div class="cartao-cabecalho"><div><h2 class="cartao-titulo">Receita do mês por plano</h2>
            <p class="text-xs text-zinc-500">Mensalidades recebidas neste mês, pelo plano atual de cada locadora.</p></div></div>
        @if (count($fin['receitaPlanos']) === 0)
            <p class="px-5 py-6 text-sm text-zinc-500">Nenhuma mensalidade recebida neste mês.</p>
        @else
            <table class="tabela">
                <thead><tr><th>Plano</th><th class="num">Pagamentos</th><th class="num">Valor</th></tr></thead>
                <tbody>
                    @foreach ($fin['receitaPlanos'] as $plano)
                        <tr><td>{{ $plano->plano }}</td><td class="num">{{ numero($plano->pagamentos) }}</td><td class="num">{{ moeda($plano->valor) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>
</div>

<section class="cartao mt-6">
    <div class="cartao-cabecalho"><div><h2 class="cartao-titulo">Maiores atrasos</h2>
        <p class="text-xs text-zinc-500">As 15 cobranças vencidas há mais tempo. Clique na locadora para abrir a ficha.</p></div></div>
    @if (count($fin['inadimplencia']['maiores']) === 0)
        <p class="px-5 py-6 text-sm text-zinc-500">Nenhuma cobrança em atraso.</p>
    @else
        <table class="tabela">
            <thead><tr><th>Locadora</th><th>Vencimento</th><th class="num">Dias em atraso</th><th class="num">Valor</th></tr></thead>
            <tbody>
                @foreach ($fin['inadimplencia']['maiores'] as $atraso)
                    <tr>
                        <td><a href="{{ route('plataforma.locadoras.editar', $atraso->id) }}" class="font-medium text-marca-700 hover:underline dark:text-marca-400">{{ $atraso->nome }}</a></td>
                        <td>{{ \Carbon\Carbon::parse($atraso->vencimento)->format('d/m/Y') }}</td>
                        <td class="num">{{ numero($atraso->dias) }}</td>
                        <td class="num">{{ moeda($atraso->valor) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</section>
@endsection

@section('scripts')
<script src="{{ asset('assets/vendor/chart.umd.js') }}"></script>
<script src="{{ asset('assets/js/graficos.js') }}"></script>
@endsection
