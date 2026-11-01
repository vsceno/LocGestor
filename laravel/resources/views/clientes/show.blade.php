@php
    $endereco = trim(implode(', ', array_filter([$c->endereco, $c->numero, $c->bairro])));
    $cidade = trim($c->cidade . ($c->uf !== '' ? '/' . $c->uf : ''));
    $hoje = date('Y-m-d');
    $wa = whatsapp_numero($c);
    $fone = so_digitos($c->celular ?: $c->telefone);
@endphp
@extends('layouts.app', ['titulo' => $c->nome, 'ativo' => 'clientes', 'migalhas' => [['Clientes', route('clientes.index')]], 'ocultarTitulo' => true])

@section('conteudo')
{{-- Perfil --}}
<section class="cartao overflow-hidden">
    <div class="h-20 bg-gradient-to-r {{ $c->tipo === 'PJ' ? 'from-sky-500/15 via-indigo-500/10' : 'from-marca-500/15 via-marca-400/10' }} to-transparent"></div>
    <div class="-mt-10 flex flex-wrap items-end gap-5 px-6 pb-6">
        <span class="avatar size-20 text-2xl ring-4 ring-white dark:ring-zinc-900 {{ $c->tipo === 'PJ' ? 'from-sky-500 to-indigo-600' : 'from-marca-400 to-marca-700' }}">{{ iniciais($c->nome) }}</span>
        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="text-xl font-semibold tracking-tight">{{ $c->nome }}</h2>
                @include('clientes._selos', ['c' => $c])
            </div>
            <p class="mt-1 text-sm text-zinc-500">
                {{ $c->tipo === 'PJ' ? 'Pessoa jurídica' : 'Pessoa física' }} · {{ doc_formatar($c->cpf_cnpj) ?: 'sem documento' }}@if ($cidade !== '') · {{ $cidade }}@endif
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($wa)<a class="btn-whats" href="https://wa.me/{{ $wa }}" target="_blank" rel="noopener"><x-icone nome="whatsapp" class="size-4" /> WhatsApp</a>@endif
            @if (strlen($fone) >= 10)<a class="btn-secundario" href="tel:+55{{ ltrim($fone, '0') }}"><x-icone nome="telefone" class="size-4" /> Ligar</a>@endif
            @if ($c->email !== '')<a class="btn-secundario" href="mailto:{{ $c->email }}"><x-icone nome="email" class="size-4" /> E-mail</a>@endif
            <a class="btn-secundario" href="{{ route('clientes.edit', $c->id) }}"><x-icone nome="editar" class="size-4" /> Editar</a>
            <a class="btn-primario" href="{{ route('locacoes.create', ['cliente' => $c->id]) }}"><x-icone nome="mais" class="size-4" /> Nova locação</a>
        </div>
    </div>
</section>

{{-- Números --}}
<div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
    <x-kpi rotulo="Locações" :valor="numero($r->locacoes)" :detalhe="numero($r->abertas) . ' em aberto'" ico="locacoes" cor="bg-sky-50 text-sky-600 dark:bg-sky-500/10 dark:text-sky-400" />
    <x-kpi rotulo="Total movimentado" :valor="moeda($r->movimentado)" :detalhe="'pago ' . moeda($r->pago)" ico="dinheiro" cor="bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400" />
    <x-kpi rotulo="Saldo devedor" :valor="moeda($r->saldo)" :detalhe="$r->saldo > 0 ? 'há valores a receber' : 'nada a receber'" ico="alerta"
           :cor="$r->saldo > 0 ? 'bg-red-50 text-red-600 dark:bg-red-500/10 dark:text-red-400' : 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800'" />
</div>

<div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-3">
    {{-- Histórico --}}
    <section class="cartao overflow-hidden xl:col-span-2">
        <div class="cartao-cabecalho">
            <h2 class="cartao-titulo">Histórico de locações</h2>
            <span class="text-xs text-zinc-500">{{ numero(count($locacoes)) }} locação(ões)</span>
        </div>
        @if (!$locacoes)
            <div class="px-6 py-14 text-center">
                <span class="mx-auto grid size-12 place-items-center rounded-full bg-marca-50 text-marca-600 dark:bg-marca-500/10"><x-icone nome="locacoes" /></span>
                <p class="mt-3 font-medium">Nenhuma locação ainda</p>
                <p class="mt-1 text-sm text-zinc-500">Quando este cliente alugar algo, o histórico aparece aqui.</p>
            </div>
        @else
            <div class="max-h-[520px] overflow-auto">
                <table class="tabela">
                    <thead><tr><th>Locação</th><th>Saída</th><th>Devolução</th><th>Situação</th><th class="num">Valor</th><th class="num">Saldo</th></tr></thead>
                    <tbody>
                    @foreach ($locacoes as $l)
                        @php $saldo = $l->status === 'cancelada' ? 0 : $l->valor_atual - $l->valor_pago; $url = route('locacoes.show', $l->numero); @endphp
                        <tr data-href="{{ $url }}">
                            <td><a href="{{ $url }}" class="font-medium hover:text-marca-600">#{{ $l->numero }}</a></td>
                            <td class="tabular-nums text-zinc-600 dark:text-zinc-400">{{ data_br($l->data_saida) }}</td>
                            <td class="tabular-nums text-zinc-600 dark:text-zinc-400">{{ data_br($l->data_prev_devolucao) }}</td>
                            <td>
                                @if ($l->status === 'aberta')
                                    @if ($l->data_prev_devolucao < $hoje)<span class="selo-vermelho">atrasada</span>
                                    @elseif ($l->data_prev_devolucao === $hoje)<span class="selo-amarelo">vence hoje</span>
                                    @else<span class="selo-azul">aberta</span>@endif
                                @elseif ($l->status === 'devolvida')<span class="selo-verde">devolvida</span>
                                @else<span class="selo-cinza">cancelada</span>@endif
                            </td>
                            <td class="num">{{ moeda($l->valor_atual) }}</td>
                            <td class="num {{ $saldo > 0.004 ? 'font-medium text-red-600 dark:text-red-400' : 'text-zinc-400' }}">{{ moeda($saldo) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{-- Dados --}}
    <section class="cartao">
        <div class="cartao-cabecalho"><h2 class="cartao-titulo">Contato e endereço</h2></div>
        <dl class="divide-y divide-zinc-100 px-5 text-sm dark:divide-zinc-800">
            @foreach ([
                [$c->tipo === 'PJ' ? 'Insc. estadual' : 'RG', $c->rg_ie],
                ['Telefone', implode(' · ', array_filter([$c->telefone, $c->celular]))],
                ['E-mail', $c->email],
                ['Endereço', trim($endereco . ($cidade !== '' ? ' — ' . $cidade : '') . ($c->cep !== '' ? ' · CEP ' . $c->cep : ''), ' —')],
                ['Cliente desde', data_br($c->criado_em)],
            ] as [$rotulo, $valor])
                <div class="flex gap-4 py-3">
                    <dt class="w-28 shrink-0 text-zinc-500">{{ $rotulo }}</dt>
                    <dd class="min-w-0 flex-1 break-words">{{ $valor !== '' ? $valor : '—' }}</dd>
                </div>
            @endforeach
        </dl>
        <div class="border-t border-zinc-100 px-5 py-4 dark:border-zinc-800">
            <p class="text-xs font-medium tracking-wide text-zinc-500 uppercase">Observações</p>
            <p class="mt-1.5 text-sm {{ $c->obs !== '' ? '' : 'text-zinc-400' }}">{!! $c->obs !== '' ? nl2br(e($c->obs)) : 'Nenhuma observação.' !!}</p>
        </div>
        {{-- Ações do cadastro --}}
        <div class="space-y-2 border-t border-zinc-100 px-5 py-4 dark:border-zinc-800">
            <p class="text-xs font-medium tracking-wide text-zinc-500 uppercase">Ações do cadastro</p>
            <form method="post" action="{{ route('clientes.bloquear', $c->id) }}"
                  data-confirmar="{{ $c->bloqueado ? 'Desbloquear este cliente?' : 'Bloquear este cliente? Ele não poderá fazer novas locações.' }}">
                @csrf
                <button class="btn-secundario w-full justify-start">
                    <x-icone :nome="$c->bloqueado ? 'ok' : 'alerta'" class="size-4 {{ $c->bloqueado ? 'text-emerald-600' : 'text-amber-600' }}" />
                    {{ $c->bloqueado ? 'Desbloquear cliente' : 'Bloquear cliente' }}
                </button>
            </form>
            @if ((int) $r->locacoes === 0)
                <form method="post" action="{{ route('clientes.destroy', $c->id) }}" data-confirmar="Excluir este cliente definitivamente?">
                    @csrf @method('DELETE')
                    <button class="btn w-full justify-start border border-red-200 bg-white text-red-600 hover:bg-red-50 dark:border-red-900 dark:bg-transparent dark:hover:bg-red-950">
                        <x-icone nome="fechar" class="size-4" /> Excluir cliente
                    </button>
                </form>
            @else
                <p class="text-xs text-zinc-500">Com locações no histórico, o cadastro não pode ser excluído; desative ou bloqueie.</p>
            @endif
        </div>
    </section>
</div>
@endsection
