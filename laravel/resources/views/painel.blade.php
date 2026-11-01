@php
    $h = (int) date('G');
    $saudacao = $h < 12 ? 'Bom dia' : ($h < 18 ? 'Boa tarde' : 'Boa noite');
    $primeiroNome = explode(' ', trim($usuario->nome))[0];
    $hoje = \Illuminate\Support\Str::ucfirst(\Carbon\Carbon::now()->locale('pt_BR')->translatedFormat('l, d \d\e F'));
    $atalhos = [
        ['Nova locação', 'Saída de equipamentos', 'locacoes', route('locacoes.create')],
        ['Novo cliente', 'Cadastro rápido', 'clientes', route('clientes.create')],
        ['Novo orçamento', 'Proposta para o cliente', 'orcamentos', route('orcamentos.create')],
        ['Registrar devolução', 'Entrada de equipamentos', 'devolucao', route('locacoes.index')],
    ];
    $kpis = [
        ['Locações em aberto', numero($k->abertas), numero($k->vencem_hoje) . ' vence(m) hoje', 'locacoes', 'bg-sky-50 text-sky-600 dark:bg-sky-500/10 dark:text-sky-400', route('locacoes.index')],
        ['Atrasadas', numero($k->atrasadas), 'devolução prevista vencida', 'alerta', 'bg-red-50 text-red-600 dark:bg-red-500/10 dark:text-red-400', route('locacoes.index')],
        ['Total a receber', moeda($k->a_receber), 'saldo das locações', 'dinheiro', 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400', null],
        ['Clientes ativos', numero($k->clientes), 'ver a lista de clientes', 'clientes', 'bg-violet-50 text-violet-600 dark:bg-violet-500/10 dark:text-violet-400', route('clientes.index')],
    ];
@endphp
@extends('layouts.app', ['titulo' => "$saudacao, $primeiroNome", 'ativo' => 'painel'])

@section('subtitulo')
    {{ $hoje }} ·
    @if ($k->atrasadas > 0)
        <span class="font-medium text-red-600 dark:text-red-400">{{ numero($k->atrasadas) }} devolução(ões) atrasada(s)</span> esperando contato
    @elseif ($k->vencem_hoje > 0)
        {{ numero($k->vencem_hoje) }} devolução(ões) vence(m) hoje
    @else
        nenhuma devolução atrasada
    @endif
@endsection

@section('acoes')
    <a class="btn-primario" href="{{ route('locacoes.create') }}"><x-icone nome="mais" class="size-4" /> Nova locação</a>
@endsection

@section('conteudo')
{{-- Indicadores --}}
<div class="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
    @foreach ($kpis as [$rotulo, $valor, $detalhe, $ico, $cor, $url])
        <x-kpi :rotulo="$rotulo" :valor="$valor" :detalhe="$detalhe" :ico="$ico" :cor="$cor" :url="$url" />
    @endforeach
</div>

<div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-3">
    {{-- Devoluções --}}
    <section class="cartao overflow-hidden xl:col-span-2">
        <div class="cartao-cabecalho">
            <div>
                <h2 class="cartao-titulo">Devoluções para acompanhar</h2>
                <p class="text-xs text-zinc-500">Atrasadas e as dos próximos 3 dias</p>
            </div>
            <a class="text-sm font-medium text-marca-600 hover:text-marca-700" href="{{ route('locacoes.index') }}">Ver todas</a>
        </div>
        @if (!$atrasadas)
            <div class="px-6 py-14 text-center">
                <span class="mx-auto grid size-12 place-items-center rounded-full bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10"><x-icone nome="ok" /></span>
                <p class="mt-3 font-medium">Tudo em dia</p>
                <p class="mt-1 text-sm text-zinc-500">Nenhuma devolução atrasada ou nos próximos 3 dias.</p>
            </div>
        @else
            <table class="tabela">
                <thead><tr><th>Locação</th><th>Cliente</th><th>Devolução</th><th>Situação</th></tr></thead>
                <tbody>
                @foreach ($atrasadas as $l)
                    @php $url = route('locacoes.show', $l->numero); @endphp
                    <tr data-href="{{ $url }}">
                        <td><a href="{{ $url }}" class="font-medium text-zinc-900 hover:text-marca-600 dark:text-zinc-100">#{{ $l->numero }}</a></td>
                        <td>
                            <div class="flex items-center gap-2.5">
                                <span class="avatar size-7 from-zinc-400 to-zinc-600 text-[10px]">{{ iniciais($l->nome) }}</span>
                                <span class="truncate">{{ $l->nome }}</span>
                            </div>
                        </td>
                        <td class="tabular-nums text-zinc-600 dark:text-zinc-400">{{ data_br($l->data_prev_devolucao) }}</td>
                        <td>
                            @if ($l->dias > 0)<span class="selo-vermelho">{{ $l->dias }} dia(s) de atraso</span>
                            @elseif ($l->dias == 0)<span class="selo-amarelo">vence hoje</span>
                            @else<span class="selo-azul">em {{ -$l->dias }} dia(s)</span>@endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </section>

    {{-- Atalhos --}}
    <section class="cartao p-5">
        <h2 class="cartao-titulo">Atalhos</h2>
        <p class="text-xs text-zinc-500">Ou aperte <kbd class="rounded border border-zinc-200 px-1 dark:border-zinc-700">Ctrl K</kbd> de qualquer tela</p>
        <div class="mt-4 space-y-2">
            @foreach ($atalhos as [$rotulo, $detalhe, $ico, $url])
                <a href="{{ $url }}" class="group flex items-center gap-3 rounded-xl border border-zinc-100 p-3 transition hover:border-marca-200 hover:bg-marca-50/60
                                            dark:border-zinc-800 dark:hover:border-marca-500/30 dark:hover:bg-marca-500/5">
                    <span class="grid size-10 place-items-center rounded-xl bg-marca-50 text-marca-600 transition group-hover:bg-marca-600 group-hover:text-white dark:bg-marca-500/10 dark:text-marca-400">
                        <x-icone :nome="$ico" class="size-5" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-medium">{{ $rotulo }}</span>
                        <span class="block text-xs text-zinc-500">{{ $detalhe }}</span>
                    </span>
                    <x-icone nome="seta" class="size-4 text-zinc-300 transition group-hover:translate-x-0.5 group-hover:text-marca-500" />
                </a>
            @endforeach
        </div>
    </section>
</div>
@endsection
