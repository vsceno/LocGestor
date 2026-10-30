@php
    use App\Support\Estoque;
    $hoje = now()->toDateString();
    $final = $anterior + $entradas - $saidas;
    $campo = fn (string $nome) => 'campo mt-1.5' . ($errors->has($nome) ? ' border-red-400 dark:border-red-700' : '');
@endphp
@extends('layouts.app', ['titulo' => 'Fluxo de caixa', 'ativo' => 'caixa'])

@section('subtitulo', 'Extrato com saldo do período. Os recebimentos de locação entram sozinhos pelas telas de locação.')

@section('conteudo')
@include('caixa._abas', ['ativa' => 'lancamentos'])

<form method="get" action="{{ route('caixa.index') }}" class="cartao mb-6 flex flex-wrap items-end gap-3 p-4">
    <label class="block"><span class="text-xs text-zinc-500">De</span><input type="date" name="de" value="{{ $de }}" class="campo mt-1"></label>
    <label class="block"><span class="text-xs text-zinc-500">Até</span><input type="date" name="ate" value="{{ $ate }}" class="campo mt-1"></label>
    <label class="block"><span class="text-xs text-zinc-500">Tipo</span>
        <select name="tipo" class="campo mt-1">@foreach (['' => 'Entradas e saídas', 'E' => 'Só entradas', 'S' => 'Só saídas'] as $v => $r)<option value="{{ $v }}" @selected($tipo === $v)>{{ $r }}</option>@endforeach</select></label>
    <button class="btn-secundario">Filtrar</button>
</form>

<div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
    <x-kpi :rotulo="'Saldo anterior (até ' . data_br(date('Y-m-d', strtotime($de . ' -1 day'))) . ')'" :valor="moeda($anterior)" ico="caixa" cor="bg-sky-50 text-sky-600 dark:bg-sky-500/10 dark:text-sky-400" />
    <x-kpi rotulo="Entradas" :valor="moeda($entradas)" ico="dinheiro" cor="bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400" />
    <x-kpi rotulo="Saídas" :valor="moeda($saidas)" ico="dinheiro" cor="bg-red-50 text-red-600 dark:bg-red-500/10 dark:text-red-400" />
    <x-kpi :rotulo="'Saldo final (' . data_br($ate) . ')'" :valor="moeda($final)" ico="caixa" cor="bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300" />
</div>

<div class="mt-6 space-y-6">
    <section class="cartao overflow-hidden">
        <div class="cartao-cabecalho"><h2 class="cartao-titulo">Extrato de {{ data_br($de) }} a {{ data_br($ate) }}</h2></div>
        <div class="overflow-x-auto">
            <table class="tabela">
                <thead><tr><th>Data</th><th>Descrição</th><th>Categoria</th><th>Forma</th><th class="num">Entrada</th><th class="num">Saída</th>
                    @if ($tipo === '')<th class="num">Saldo</th>@endif<th class="w-px"></th></tr></thead>
                <tbody>
                @if ($tipo === '')
                    <tr><td class="tabular-nums">{{ data_br($de) }}</td><td colspan="5" class="text-zinc-500 italic">Saldo anterior</td><td class="num">{{ moeda($anterior) }}</td><td></td></tr>
                @endif
                @php $acum = $anterior; @endphp
                @forelse ($linhas as $l)
                    @php $acum += $l->tipo === 'E' ? $l->valor : -$l->valor; @endphp
                    <tr>
                        <td class="tabular-nums whitespace-nowrap">{{ data_br($l->data) }}</td>
                        <td class="min-w-72">@if ($l->locacao)<a class="font-medium text-marca-700 hover:underline dark:text-marca-400" href="{{ route('locacoes.show', $l->locacao) }}">{{ $l->descricao }}</a>
                            @else<span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $l->descricao }}</span>@endif
                            @if ($l->usuario)<span class="block text-xs text-zinc-500">{{ $l->usuario }}</span>@endif</td>
                        <td class="text-zinc-600 dark:text-zinc-400">{{ $l->categoria }}</td>
                        <td class="text-zinc-600 dark:text-zinc-400">{{ $l->forma }}</td>
                        <td class="num whitespace-nowrap text-emerald-700 dark:text-emerald-400">{{ $l->tipo === 'E' ? moeda($l->valor) : '' }}</td>
                        <td class="num whitespace-nowrap text-red-600 dark:text-red-400">{{ $l->tipo === 'S' ? moeda($l->valor) : '' }}</td>
                        @if ($tipo === '')<td class="num whitespace-nowrap font-medium">{{ moeda($acum) }}</td>@endif
                        <td>
                            @if ($admin && $l->locacao_id === null && !$l->conta)
                                <form method="post" action="{{ route('caixa.destroy', $l->id) }}" data-confirmar="Excluir o lançamento '{{ $l->descricao }}'?">
                                    @csrf @method('DELETE')
                                    <button class="btn-icone hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-500/10" title="Excluir" aria-label="Excluir lançamento {{ $l->descricao }}"><x-icone nome="fechar" class="size-4" /></button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="py-10 text-center text-sm text-zinc-500">Nenhum lançamento no período.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <section class="cartao">
            <div class="cartao-cabecalho"><h2 class="cartao-titulo">Novo lançamento</h2></div>
            <form method="post" action="{{ route('caixa.store') }}" class="space-y-4 p-5" novalidate>
                @csrf
                @if ($errors->any())<p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950/50 dark:text-red-300" role="alert">{{ $errors->first() }}</p>@endif
                <div class="grid grid-cols-2 gap-3">
                    <label class="block"><span class="text-sm font-medium">Tipo</span>
                        <select name="tipo" class="{{ $campo('tipo') }}"><option value="S" @selected(old('tipo', 'S') === 'S')>Saída (despesa)</option><option value="E" @selected(old('tipo') === 'E')>Entrada</option></select></label>
                    <label class="block"><span class="text-sm font-medium">Data</span>
                        <input type="date" name="data" value="{{ old('data', $hoje) }}" max="{{ $hoje }}" class="{{ $campo('data') }}"></label>
                </div>
                <label class="block"><span class="text-sm font-medium">Descrição</span>
                    <input type="text" name="descricao" value="{{ old('descricao') }}" maxlength="200" class="{{ $campo('descricao') }}"></label>
                <div class="grid grid-cols-2 gap-3">
                    <label class="block"><span class="text-sm font-medium">Categoria</span>
                        <input type="text" name="categoria" value="{{ old('categoria') }}" list="cats" maxlength="60" class="{{ $campo('categoria') }}">
                        <datalist id="cats">@foreach ($categorias as $c)<option value="{{ $c }}">@endforeach</datalist></label>
                    <label class="block"><span class="text-sm font-medium">Valor</span>
                        <span class="relative mt-1.5 block"><span class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-zinc-400">R$</span>
                            <input type="text" name="valor" value="{{ old('valor') }}" inputmode="decimal" placeholder="0,00" class="{{ $campo('valor') }} mt-0 pl-9 text-right tabular-nums"></span></label>
                </div>
                <label class="block"><span class="text-sm font-medium">Forma</span>
                    <select name="forma" class="{{ $campo('forma') }}">@foreach (Estoque::FORMAS_PGTO as $f)<option @selected(old('forma', 'PIX') === $f)>{{ $f }}</option>@endforeach</select></label>
                <button class="btn-primario w-full justify-center"><x-icone nome="mais" class="size-4" /> Lançar</button>
            </form>
        </section>

        @foreach (['Por forma de pagamento' => $porForma, 'Por categoria' => $porCategoria] as $titulo => $dados)
            <section class="cartao self-start overflow-hidden">
                <div class="cartao-cabecalho"><h2 class="cartao-titulo">{{ $titulo }}</h2></div>
                <table class="tabela">
                    <thead><tr><th></th><th class="num">Entradas</th><th class="num">Saídas</th></tr></thead>
                    <tbody>@forelse ($dados as $d)
                        <tr><td>{{ $d->nome ?: '—' }}</td><td class="num whitespace-nowrap">{{ moeda($d->entradas) }}</td><td class="num whitespace-nowrap">{{ moeda($d->saidas) }}</td></tr>
                    @empty<tr><td colspan="3" class="text-center text-zinc-500">—</td></tr>@endforelse</tbody>
                    <tfoot><tr class="font-semibold"><td class="px-4 py-2">Total</td><td class="num px-4 py-2">{{ moeda(array_sum(array_column($dados, 'entradas'))) }}</td>
                        <td class="num px-4 py-2">{{ moeda(array_sum(array_column($dados, 'saidas'))) }}</td></tr></tfoot>
                </table>
            </section>
        @endforeach
    </div>
</div>
@endsection
