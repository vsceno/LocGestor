@extends('layouts.plataforma', ['titulo' => 'Locadoras', 'ativo' => 'tenants', 'subtitulo' => 'Quem usa o LocGestor, a mensalidade de cada uma e o que pede atenção.'])

@section('conteudo')
<div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
    <x-kpi rotulo="Locadoras" :valor="numero(count($locadoras))" :detalhe="numero($ativas) . ' ativa(s)'" ico="clientes"
           cor="bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-400" />
    <x-kpi rotulo="Receita mensal recorrente" :valor="moeda($mrr)" detalhe="locadoras ativas com mensalidade" ico="caixa"
           cor="bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400" />
    <x-kpi rotulo="Suspensas por inadimplência" :valor="numero($inadimplentes)" ico="alerta"
           cor="bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-400" />
</div>

@if ($atencao)
    <section class="cartao mt-6 overflow-hidden">
        <div class="cartao-cabecalho">
            <h2 class="cartao-titulo flex items-center gap-2">Precisam de atenção <span class="selo-amarelo">{{ numero(count($atencao)) }}</span></h2>
            <a class="btn-secundario" href="{{ route('plataforma.locadoras', ['filtro' => 'atencao']) }}">Ver só essas</a>
        </div>
        <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
            @foreach ($atencao as $t)
                <li class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 px-5 py-3 text-sm">
                    <a class="font-medium hover:text-marca-700 dark:hover:text-marca-400" href="{{ route('plataforma.locadoras.editar', $t->id) }}">{{ $t->nome }}</a>
                    <span class="text-zinc-500 dark:text-zinc-400">{{ implode(' · ', $t->alertas) }}</span>
                </li>
            @endforeach
        </ul>
    </section>
@endif

<section class="cartao mt-6 overflow-hidden">
    <div class="cartao-cabecalho">
        <h2 class="cartao-titulo">Locadoras cadastradas</h2>
        <div class="flex flex-wrap gap-2">
            <a class="btn-secundario" href="{{ route('plataforma.planos.index') }}">Planos</a>
            <a class="btn-primario" href="{{ route('plataforma.locadoras.nova') }}">+ Nova locadora</a>
        </div>
    </div>
    <form method="get" class="flex flex-wrap items-center gap-2 border-b border-zinc-100 px-5 py-3 dark:border-zinc-800">
        <select name="filtro" aria-label="Filtrar locadoras" class="campo w-auto">
            @foreach ($filtros as $chave => $rotulo)
                <option value="{{ $chave }}" @selected($filtro === $chave)>{{ $rotulo }}</option>
            @endforeach
        </select>
        <button class="btn-secundario">Filtrar</button>
        <span class="text-sm text-zinc-500">{{ numero(count($visiveis)) }} de {{ numero(count($locadoras)) }}</span>
    </form>

    <div class="overflow-x-auto">
        <table class="tabela">
            <thead>
                <tr><th>Locadora</th><th>Status</th><th>Plano</th><th>Mensalidade</th><th class="num">Usuários</th>
                    <th class="num">Clientes</th><th class="num">Loc. abertas</th><th>Último acesso</th><th></th></tr>
            </thead>
            <tbody>
                @forelse ($visiveis as $t)
                    <tr>
                        <td>
                            <a class="font-medium text-zinc-900 hover:text-marca-700 dark:text-zinc-100 dark:hover:text-marca-400"
                               href="{{ route('plataforma.locadoras.editar', $t->id) }}">{{ $t->nome }}</a>
                            <div class="text-xs text-zinc-500"><code class="font-mono">{{ $t->slug }}</code>@if ($t->email_contato !== '') · {{ $t->email_contato }}@endif</div>
                        </td>
                        <td>
                            <span class="{{ $t->status === 'ativa' ? 'selo-verde' : 'selo-vermelho' }}">{{ $t->status }}</span>
                            @if ($t->motivo_suspensao === 'inadimplencia')<div class="mt-1 text-xs text-zinc-500">por inadimplência</div>@endif
                        </td>
                        <td>
                            {{ $t->plano_nome ?? '—' }}
                            @if ($t->preco_mensal !== null)<div class="text-xs text-zinc-500">{{ moeda($t->preco_mensal) }}/mês</div>@endif
                        </td>
                        <td>
                            <span class="{{ $t->situacao[1] }}">{{ $t->situacao[0] }}</span>
                            @if ($t->vencimento)<div class="text-xs text-zinc-500">vence {{ data_br($t->vencimento) }}</div>@endif
                        </td>
                        <td class="num">{{ numero($t->usuarios) }} / {{ numero($t->limite_usuarios) }}</td>
                        <td class="num">{{ numero($t->clientes) }}</td>
                        <td class="num">{{ numero($t->abertas) }}</td>
                        <td class="whitespace-nowrap tabular-nums">{{ $t->ultimo_acesso ? data_br($t->ultimo_acesso, true) : 'nunca' }}</td>
                        <td class="whitespace-nowrap">
                            <a class="btn-secundario" href="{{ route('plataforma.locadoras.editar', $t->id) }}">Gerenciar</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-4 py-10 text-center text-zinc-500">{{ $locadoras ? 'Nenhuma locadora neste filtro.' : 'Nenhuma locadora cadastrada.' }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection
