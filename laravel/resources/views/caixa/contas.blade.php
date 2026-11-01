@php
    $hoje = now()->toDateString();
    $bagConta = $errors->getBag('conta');
    $bagRecorrente = $errors->getBag('recorrente');
    $erroConta = $bagConta->any();
    $erroRecorrente = $bagRecorrente->any();
    $campoConta = fn (string $nome) => 'campo mt-1.5' . ($bagConta->has($nome) ? ' border-red-400 dark:border-red-700' : '');
    $campoRecorrente = fn (string $nome) => 'campo mt-1.5' . ($bagRecorrente->has($nome) ? ' border-red-400 dark:border-red-700' : '');
    // Após erro, o que foi digitado; senão, o que está gravado na recorrente em edição
    $rec = fn (string $campo, $padrao = '') => $erroRecorrente ? old($campo) : ($editar->$campo ?? $padrao);
@endphp
@extends('layouts.app', ['titulo' => 'Contas a pagar', 'ativo' => 'caixa'])

@section('subtitulo', 'Despesas com vencimento. Pagar lança a saída no caixa; as recorrentes geram as parcelas dos próximos 90 dias.')

@section('conteudo')
@include('caixa._abas', ['ativa' => 'contas'])

<div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
    <x-kpi rotulo="Vencidas" :valor="moeda((float) $resumo->vencidas)" detalhe="em aberto, com vencimento passado" ico="alerta"
           cor="bg-red-50 text-red-600 dark:bg-red-500/10 dark:text-red-400" />
    <x-kpi rotulo="Próximos 30 dias" :valor="moeda((float) $resumo->proximas)" detalhe="a vencer" ico="relogio"
           cor="bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300" />
</div>

<div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
    <section class="cartao self-start">
        <div class="cartao-cabecalho"><h2 class="cartao-titulo">Nova conta</h2></div>
        <form method="post" action="{{ route('caixa.contas.store') }}" class="space-y-4 p-5">
            @csrf
            @if ($erroConta)
                <p role="alert" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950/50 dark:text-red-300">{{ $bagConta->first() }}</p>
            @endif
            <label class="block"><span class="text-sm font-medium">Descrição <span class="text-red-500">*</span></span>
                <input type="text" name="descricao" value="{{ $erroConta ? old('descricao') : '' }}" maxlength="200" required placeholder="Ex.: Conta de luz de outubro" class="{{ $campoConta('descricao') }}"></label>
            <div class="grid grid-cols-2 gap-3">
                <label class="block"><span class="text-sm font-medium">Categoria <span class="text-red-500">*</span></span>
                    <input type="text" name="categoria" value="{{ $erroConta ? old('categoria') : '' }}" list="cats-conta" maxlength="60" required class="{{ $campoConta('categoria') }}"></label>
                <label class="block"><span class="text-sm font-medium">Valor <span class="text-red-500">*</span></span>
                    <span class="relative mt-1.5 block"><span class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-zinc-400">R$</span>
                        <input type="text" name="valor" value="{{ $erroConta ? old('valor') : '' }}" inputmode="decimal" placeholder="0,00" required class="{{ $campoConta('valor') }} mt-0 pl-9 text-right tabular-nums"></span></label>
            </div>
            <datalist id="cats-conta">@foreach ($categorias as $c)<option value="{{ $c }}">@endforeach</datalist>
            <label class="block"><span class="text-sm font-medium">Vencimento <span class="text-red-500">*</span></span>
                <input type="date" name="vencimento" value="{{ $erroConta ? old('vencimento') : '' }}" required class="{{ $campoConta('vencimento') }}"></label>
            <button class="btn-primario w-full justify-center"><x-icone nome="mais" class="size-4" /> Incluir conta</button>
        </form>
    </section>

    <section class="cartao self-start overflow-hidden">
        <div class="cartao-cabecalho"><h2 class="cartao-titulo">{{ $editar ? 'Editar conta recorrente' : 'Contas recorrentes' }}</h2></div>
        @if (!$editar && count($recorrentes) > 0)
            <div class="overflow-x-auto">
                <table class="tabela">
                    <thead><tr><th>Descrição</th><th class="num">Dia</th><th class="num">Valor</th><th class="w-px"></th></tr></thead>
                    <tbody>
                    @foreach ($recorrentes as $x)
                        <tr>
                            <td><span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $x->descricao }}</span>
                                @unless ($x->ativa)<span class="selo-cinza">inativa</span>@endunless
                                <span class="block text-xs text-zinc-500">{{ $x->categoria }}</span></td>
                            <td class="num">{{ numero($x->dia) }}</td>
                            <td class="num whitespace-nowrap">{{ moeda((float) $x->valor) }}</td>
                            <td>@if ($admin)<a class="btn-secundario" href="{{ route('caixa.contas', ['recorrente' => $x->id]) }}">Editar</a>@endif</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        @if ($admin)
            <form method="post" action="{{ $editar ? route('caixa.recorrentes.update', $editar->id) : route('caixa.recorrentes.store') }}" class="space-y-4 p-5">
                @csrf
                @if ($editar) @method('PUT') @endif
                @if (!$editar)
                    <p class="text-xs text-zinc-500">Despesa fixa que vence todo mês (aluguel, salário, internet): as parcelas aparecem sozinhas até 90 dias à frente.</p>
                @endif
                @if ($erroRecorrente)
                    <p role="alert" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950/50 dark:text-red-300">{{ $bagRecorrente->first() }}</p>
                @endif
                <label class="block"><span class="text-sm font-medium">Descrição <span class="text-red-500">*</span></span>
                    <input type="text" name="descricao" value="{{ $rec('descricao') }}" maxlength="200" required placeholder="Ex.: Aluguel do galpão" class="{{ $campoRecorrente('descricao') }}"></label>
                <div class="grid grid-cols-2 gap-3">
                    <label class="block"><span class="text-sm font-medium">Categoria <span class="text-red-500">*</span></span>
                        <input type="text" name="categoria" value="{{ $rec('categoria') }}" list="cats-conta" maxlength="60" required class="{{ $campoRecorrente('categoria') }}"></label>
                    <label class="block"><span class="text-sm font-medium">Valor <span class="text-red-500">*</span></span>
                        <span class="relative mt-1.5 block"><span class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-zinc-400">R$</span>
                            <input type="text" name="valor" value="{{ $erroRecorrente ? old('valor') : ($editar ? numero($editar->valor, 2) : '') }}" inputmode="decimal" placeholder="0,00" required class="{{ $campoRecorrente('valor') }} mt-0 pl-9 text-right tabular-nums"></span></label>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <label class="block"><span class="text-sm font-medium">Vence todo dia <span class="text-red-500">*</span></span>
                        <input type="number" name="dia" value="{{ $rec('dia', 10) }}" min="1" max="28" required class="{{ $campoRecorrente('dia') }}"></label>
                    <label class="block"><span class="text-sm font-medium">A partir de <span class="text-red-500">*</span></span>
                        <input type="date" name="inicio" value="{{ $rec('inicio', $hoje) }}" required class="{{ $campoRecorrente('inicio') }}"></label>
                </div>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="ativa" value="1" @checked($erroRecorrente ? old('ativa') : ($editar ? $editar->ativa : true))>
                    Ativa (desmarcar remove as parcelas futuras em aberto)
                </label>
                <div class="flex flex-wrap gap-2">
                    <button class="btn-primario">{{ $editar ? 'Salvar' : 'Incluir recorrente' }}</button>
                    @if ($editar)<a class="btn-secundario" href="{{ route('caixa.contas') }}">Cancelar</a>@endif
                </div>
            </form>
        @endif
    </section>
</div>

<section class="cartao mt-6 overflow-hidden">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-100 p-4 dark:border-zinc-800">
        <h2 class="cartao-titulo">Contas</h2>
        <form method="get" action="{{ route('caixa.contas') }}" class="flex items-center gap-2">
            <select name="situacao" aria-label="Situação" class="campo">
                @foreach ($situacoes as $valor => $rotulo)<option value="{{ $valor }}" @selected($situacao === $valor)>{{ $rotulo }}</option>@endforeach
            </select>
            <button class="btn-secundario">Filtrar</button>
        </form>
    </div>
    <div class="overflow-x-auto">
        <table class="tabela">
            <thead><tr><th>Vencimento</th><th>Descrição</th><th>Categoria</th><th class="num">Valor</th><th>Situação</th><th></th></tr></thead>
            <tbody>
            @forelse ($contas as $c)
                @php $vencida = $c->pago_em === null && $c->vencimento < $hoje; @endphp
                <tr>
                    <td class="whitespace-nowrap tabular-nums">{{ data_br($c->vencimento) }}</td>
                    <td class="font-medium text-zinc-900 dark:text-zinc-100">{{ $c->descricao }}@if ($c->recorrente_id) <span class="selo-azul">recorrente</span>@endif</td>
                    <td class="text-zinc-600 dark:text-zinc-400">{{ $c->categoria }}</td>
                    <td class="num whitespace-nowrap">{{ moeda((float) $c->valor) }}</td>
                    <td class="whitespace-nowrap">@if ($c->pago_em)<span class="selo-verde">paga em {{ data_br($c->pago_em) }}</span>@elseif ($vencida)<span class="selo-vermelho">vencida</span>@else<span class="selo-amarelo">a vencer</span>@endif</td>
                    <td>
                        @if ($c->pago_em === null)
                            <div class="flex flex-wrap items-center justify-end gap-2">
                                <form method="post" action="{{ route('caixa.contas.pagar', $c->id) }}" class="flex flex-wrap items-center gap-2">
                                    @csrf
                                    <input type="date" name="data" value="{{ $hoje }}" max="{{ $hoje }}" aria-label="Data do pagamento" class="campo">
                                    <select name="forma" aria-label="Forma" class="campo">
                                        @foreach (\App\Support\Estoque::FORMAS_PGTO as $forma)<option value="{{ $forma }}" @selected($forma === 'PIX')>{{ $forma }}</option>@endforeach
                                    </select>
                                    <button class="btn-primario">Pagar</button>
                                </form>
                                @if ($admin)
                                    <form method="post" action="{{ route('caixa.contas.excluir', $c->id) }}" data-confirmar="Excluir esta conta?">
                                        @csrf @method('DELETE')
                                        <button class="btn-secundario">Excluir</button>
                                    </form>
                                @endif
                            </div>
                        @elseif ($admin)
                            <form method="post" action="{{ route('caixa.contas.desfazer', $c->id) }}" data-confirmar="Desfazer o pagamento? A saída é apagada do caixa." class="flex justify-end">
                                @csrf
                                <button class="btn-secundario">Desfazer pagamento</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="py-10 text-center text-sm text-zinc-500">Nenhuma conta {{ $situacao === 'abertas' ? 'em aberto' : 'encontrada' }}.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @include('partials.paginacao', ['p' => $contas])
</section>
@endsection
