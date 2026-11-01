@php
    use App\Http\Controllers\NovaLocacaoController;
    $voltou = (bool) old('_enviado');
    $v = fn (string $campo, string $padrao = '') => $voltou ? (string) old($campo) : $padrao;
    $linhas = [];
    if ($voltou) {
        foreach ((array) old('produto_id', []) as $i => $pid) {
            $linhas[] = ['produto_id' => (string) $pid, 'quantidade' => old('quantidade', [])[$i] ?? '', 'diaria' => old('diaria', [])[$i] ?? '', 'periodo' => old('periodo', [])[$i] ?? 'diaria'];
        }
    }
    $linhas = $linhas ?: [['produto_id' => '', 'quantidade' => '1', 'diaria' => '', 'periodo' => 'diaria']];
    $reais = 'campo pl-9 text-right tabular-nums';
@endphp
@extends('layouts.app', ['titulo' => 'Novo orçamento', 'ativo' => 'orcamentos', 'migalhas' => [['Orçamentos', route('orcamentos.index')]]])

@section('subtitulo', 'O preço do cadastro entra sozinho; o valor do período pode ser negociado.')

@section('conteudo')
<form method="post" action="{{ route('orcamentos.store') }}" autocomplete="off" novalidate data-orcamento data-disponivel="{{ route('locacoes.disponivel') }}">
    @csrf
    <input type="hidden" name="_enviado" value="1">
    @error('orcamento')
        <div role="alert" class="mb-6 flex gap-3 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/50 dark:text-red-300">
            <x-icone nome="alerta" class="mt-0.5 size-5" /><div><p class="font-medium">O orçamento não foi salvo.</p><p class="mt-0.5">{{ $message }}</p></div>
        </div>
    @enderror

    <section class="cartao">
        <div class="cartao-cabecalho"><h2 class="cartao-titulo">Cliente e período</h2></div>
        <div class="grid grid-cols-1 gap-5 p-5 md:grid-cols-3">
            <label class="block md:col-span-3"><span class="text-sm font-medium">Cliente <span class="text-red-500">*</span></span>
                <input type="text" name="cliente" value="{{ $v('cliente') }}" list="lista-clientes" required placeholder="Digite o nome e escolha na lista" class="campo mt-1.5" autofocus>
                <datalist id="lista-clientes">@foreach ($clientes as $c)<option value="{{ NovaLocacaoController::rotuloCliente($c) }}"></option>@endforeach</datalist></label>
            <label class="block"><span class="text-sm font-medium">Saída prevista <span class="text-red-500">*</span></span>
                <input type="date" name="data_saida" value="{{ $v('data_saida', now()->addDay()->toDateString()) }}" required class="campo mt-1.5" data-saida></label>
            <label class="block"><span class="text-sm font-medium">Devolução prevista <span class="text-red-500">*</span></span>
                <input type="date" name="data_prev_devolucao" value="{{ $v('data_prev_devolucao', now()->addDays(2)->toDateString()) }}" required class="campo mt-1.5" data-prev>
                <span class="mt-1 block text-xs text-zinc-500" data-info-dias></span></label>
            <label class="block"><span class="text-sm font-medium">Validade <span class="text-red-500">*</span></span>
                <input type="date" name="validade" value="{{ $v('validade', now()->addDays(7)->toDateString()) }}" min="{{ now()->toDateString() }}" max="{{ now()->addDays(\App\Http\Controllers\OrcamentosController::VALIDADE_MAX_DIAS)->toDateString() }}" required class="campo mt-1.5">
                <span class="mt-1 block text-xs text-zinc-500">Até {{ \App\Http\Controllers\OrcamentosController::VALIDADE_MAX_DIAS }} dias.</span></label>
            <label class="block md:col-span-3"><span class="text-sm font-medium">Local de uso (obra/evento)</span>
                <input type="text" name="local_uso" value="{{ $v('local_uso') }}" maxlength="200" class="campo mt-1.5"></label>
        </div>
    </section>

    <section class="cartao mt-6">
        <div class="cartao-cabecalho"><h2 class="cartao-titulo">Itens</h2></div>
        <div class="overflow-x-auto">
            <table class="tabela">
                <thead><tr><th>Produto</th><th>Período</th><th class="num">Qtd.</th><th class="num">Valor</th><th class="num">Disponível</th><th class="num">Subtotal</th><th class="w-px"></th></tr></thead>
                <tbody data-corpo>@foreach ($linhas as $ln) @include('orcamentos._linha', ['ln' => $ln]) @endforeach</tbody>
            </table>
        </div>
        <template data-modelo>@include('orcamentos._linha', ['ln' => ['produto_id' => '', 'quantidade' => '1', 'diaria' => '', 'periodo' => 'diaria']])</template>
        <div class="border-t border-zinc-100 px-5 py-3 dark:border-zinc-800"><button type="button" class="btn-secundario" data-adicionar><x-icone nome="mais" class="size-4" /> Adicionar item</button></div>
    </section>

    <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-3">
        <section class="cartao xl:col-span-2">
            <div class="cartao-cabecalho"><h2 class="cartao-titulo">Valores</h2></div>
            <div class="grid grid-cols-2 gap-5 p-5 md:grid-cols-4">
                @foreach (['acrescimo' => 'Acréscimo', 'frete_entrega' => 'Frete', 'frete_recolhimento' => 'Recolhimento', 'desconto' => 'Desconto'] as $campo => $rot)
                    <label class="block"><span class="text-sm font-medium">{{ $rot }}</span>
                        <span class="relative mt-1.5 block"><span class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-zinc-400">R$</span>
                            <input type="text" name="{{ $campo }}" value="{{ $v($campo) }}" inputmode="decimal" placeholder="0,00" class="{{ $reais }}" data-valor="{{ $campo }}"></span></label>
                @endforeach
                @unless ($admin)
                    <label class="col-span-2 block md:col-span-4"><span class="text-sm font-medium">Senha do administrador</span>
                        <input type="password" name="senha_admin" autocomplete="new-password" placeholder="Só com desconto ou acréscimo" class="campo mt-1.5 max-w-sm"></label>
                @endunless
            </div>
        </section>
        <section class="cartao">
            <div class="cartao-cabecalho"><h2 class="cartao-titulo">Observações</h2></div>
            <div class="p-5"><textarea name="obs" rows="4" maxlength="2000" class="campo resize-y">{{ $v('obs') }}</textarea></div>
        </section>
    </div>

    <div class="sticky bottom-0 z-10 -mx-4 mt-6 border-t border-zinc-200 bg-white/85 px-4 py-3 backdrop-blur-xl sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8 dark:border-zinc-800 dark:bg-zinc-950/85">
        <div class="flex flex-wrap items-center justify-end gap-x-6 gap-y-2">
            <p class="mr-auto text-sm text-zinc-500">Valor Total <strong class="ml-1 text-xl font-semibold tabular-nums text-zinc-900 dark:text-zinc-100" data-total>R$ 0,00</strong></p>
            <a class="btn-secundario" href="{{ route('orcamentos.index') }}">Cancelar</a>
            <button type="submit" class="btn-primario"><x-icone nome="ok" class="size-4" /> Salvar orçamento</button>
        </div>
    </div>
</form>
@endsection
