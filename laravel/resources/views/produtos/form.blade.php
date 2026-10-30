@php
    $novo = $p === null;
    $enviado = (bool) old('_enviado');
    $v = fn (string $campo, $padrao = '') => old($campo, $p->$campo ?? $padrao);
    // Valores em reais com vírgula; semanal/quinzenal/mensal zerados = não oferece (campo vazio)
    $reais = fn (string $campo, bool $opcional = false) => $enviado ? old($campo)
        : (isset($p->$campo) && (!$opcional || $p->$campo > 0) ? numero($p->$campo, 2) : '');
    $ativo = $enviado ? (bool) old('ativo') : (bool) ($p->ativo ?? true);
    $titulo = $novo ? 'Novo produto' : 'Editar produto';
    $migalhas = [['Produtos', route('produtos.index')]];
    $campo = fn (string $nome) => 'campo mt-1.5' . ($errors->has($nome) ? ' border-red-400 focus:border-red-500 focus:ring-red-500/15 dark:border-red-700' : '');
    $minimo = $uso ? $uso->alugado + $uso->manutencao : 0;
@endphp
@extends('layouts.app', ['titulo' => $titulo, 'ativo' => 'produtos', 'migalhas' => $migalhas])

@section('subtitulo', $novo ? 'Código, descrição e diária são o essencial. Os outros preços são opcionais.' : $p->codigo . ' · ' . $p->descricao)

@section('conteudo')
<form method="post" action="{{ $novo ? route('produtos.store') : route('produtos.update', $p->id) }}" autocomplete="off" novalidate>
    @csrf
    @unless ($novo) @method('PUT') @endunless
    <input type="hidden" name="_enviado" value="1">

    @if ($errors->any())
        <div role="alert" class="mb-6 flex gap-3 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/50 dark:text-red-300">
            <x-icone nome="alerta" class="mt-0.5 size-5" />
            <div><p class="font-medium">Confira {{ $errors->count() === 1 ? 'o campo destacado' : 'os ' . $errors->count() . ' campos destacados' }}.</p>
                <p class="mt-0.5 text-red-700/80 dark:text-red-300/80">{{ $errors->first() }}</p></div>
        </div>
    @endif

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            {{-- Identificação --}}
            <section class="cartao">
                <div class="cartao-cabecalho"><h2 class="cartao-titulo">Produto</h2></div>
                <div class="grid grid-cols-6 gap-5 p-5">
                    <label class="col-span-6 block sm:col-span-2">
                        <span class="text-sm font-medium">Código <span class="text-red-500">*</span></span>
                        <input type="text" name="codigo" value="{{ $v('codigo') }}" required maxlength="30" class="{{ $campo('codigo') }} font-mono uppercase" @if ($novo) autofocus @endif>
                        @error('codigo')<span class="mt-1 block text-xs text-red-600 dark:text-red-400">{{ $message }}</span>@enderror
                    </label>
                    <label class="col-span-6 block sm:col-span-4">
                        <span class="text-sm font-medium">Descrição <span class="text-red-500">*</span></span>
                        <input type="text" name="descricao" value="{{ $v('descricao') }}" required maxlength="150" class="{{ $campo('descricao') }}">
                        @error('descricao')<span class="mt-1 block text-xs text-red-600 dark:text-red-400">{{ $message }}</span>@enderror
                    </label>
                    <label class="col-span-6 block sm:col-span-4">
                        <span class="text-sm font-medium">Categoria</span>
                        <select name="categoria_id" class="{{ $campo('categoria_id') }}">
                            <option value="">— sem categoria —</option>
                            @foreach ($categorias as $id => $nome)<option value="{{ $id }}" @selected($v('categoria_id') === $id)>{{ $nome }}</option>@endforeach
                        </select>
                        @error('categoria_id')<span class="mt-1 block text-xs text-red-600 dark:text-red-400">{{ $message }}</span>@enderror
                    </label>
                    <label class="col-span-6 block sm:col-span-2">
                        <span class="text-sm font-medium">Unidade <span class="text-red-500">*</span></span>
                        <input type="text" name="unidade" value="{{ $v('unidade', 'UN') }}" required maxlength="10" list="unidades" class="{{ $campo('unidade') }} uppercase">
                        <datalist id="unidades">@foreach (\App\Http\Controllers\ProdutosController::UNIDADES as $u)<option value="{{ $u }}">@endforeach</datalist>
                        @error('unidade')<span class="mt-1 block text-xs text-red-600 dark:text-red-400">{{ $message }}</span>@enderror
                    </label>
                    <label class="col-span-6 block">
                        <span class="text-sm font-medium">Códigos de barras</span>
                        <textarea name="codigos_barras" rows="2" placeholder="Um por linha; o primeiro é o principal" class="{{ $campo('codigos_barras') }} resize-y font-mono">{{ old('codigos_barras', $codigos) }}</textarea>
                        @error('codigos_barras')<span class="mt-1 block text-xs text-red-600 dark:text-red-400">{{ $message }}</span>@else
                        <span class="mt-1 block text-xs text-zinc-500">Só números, de 5 a 20 dígitos. Cada código fica em um produto só.</span>@enderror
                    </label>
                </div>
            </section>

            {{-- Preços --}}
            <section class="cartao">
                <div class="cartao-cabecalho">
                    <h2 class="cartao-titulo">Preços</h2>
                    <span class="text-xs text-zinc-500">Vazio = não oferece esse período</span>
                </div>
                <div class="grid grid-cols-2 gap-5 p-5 sm:grid-cols-4">
                    @foreach ([['valor_diaria', 'Diária', true, 'Usada nos dias que sobram.'],
                               ['valor_semanal', 'Semana (7 dias)', false, null],
                               ['valor_quinzenal', 'Quinzena (15 dias)', false, null],
                               ['valor_mensal', 'Mês (30 dias)', false, null]] as [$nome, $rotulo, $obrig, $ajuda])
                        <label class="block">
                            <span class="text-sm font-medium">{{ $rotulo }} @if ($obrig)<span class="text-red-500">*</span>@endif</span>
                            <span class="relative mt-1.5 block">
                                <span class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-zinc-400">R$</span>
                                <input type="text" name="{{ $nome }}" value="{{ $reais($nome, !$obrig) }}" inputmode="decimal" placeholder="{{ $obrig ? '0,00' : 'não oferece' }}"
                                       class="{{ $campo($nome) }} mt-0 pl-9 text-right tabular-nums">
                            </span>
                            @error($nome)<span class="mt-1 block text-xs text-red-600 dark:text-red-400">{{ $message }}</span>@else
                            @if ($ajuda)<span class="mt-1 block text-xs text-zinc-500">{{ $ajuda }}</span>@endif @enderror
                        </label>
                    @endforeach
                </div>
                <div class="grid grid-cols-2 gap-5 border-t border-zinc-100 p-5 sm:grid-cols-4 dark:border-zinc-800">
                    <label class="col-span-2 block">
                        <span class="text-sm font-medium">Valor de reposição (un.)</span>
                        <span class="relative mt-1.5 block">
                            <span class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-zinc-400">R$</span>
                            <input type="text" name="valor_reposicao" value="{{ $reais('valor_reposicao') }}" inputmode="decimal" placeholder="0,00"
                                   class="{{ $campo('valor_reposicao') }} mt-0 pl-9 text-right tabular-nums">
                        </span>
                        @error('valor_reposicao')<span class="mt-1 block text-xs text-red-600 dark:text-red-400">{{ $message }}</span>@else
                        <span class="mt-1 block text-xs text-zinc-500">Cobrado em caso de perda ou avaria total (vai na cláusula 4ª do contrato).</span>@enderror
                    </label>
                </div>
            </section>
        </div>

        <div class="space-y-6">
            {{-- Estoque --}}
            <section class="cartao">
                <div class="cartao-cabecalho"><h2 class="cartao-titulo">Estoque</h2></div>
                <div class="space-y-4 p-5">
                    <label class="block">
                        <span class="text-sm font-medium">Quantidade total <span class="text-red-500">*</span></span>
                        <input type="number" name="qtd_total" value="{{ $v('qtd_total', '0') }}" min="{{ $minimo }}" step="1" required class="{{ $campo('qtd_total') }} tabular-nums">
                        @error('qtd_total')<span class="mt-1 block text-xs text-red-600 dark:text-red-400">{{ $message }}</span>@enderror
                    </label>
                    @if ($uso)
                        <dl class="grid grid-cols-3 divide-x divide-zinc-100 rounded-xl bg-zinc-50 text-center dark:divide-zinc-800 dark:bg-zinc-900">
                            @foreach ([['Na rua', $uso->alugado], ['Manutenção', $uso->manutencao], ['Patrimônio', $uso->unidades]] as [$rotulo, $n])
                                <div class="px-2 py-3"><dt class="text-xs text-zinc-500">{{ $rotulo }}</dt><dd class="mt-0.5 text-lg font-semibold tabular-nums">{{ numero($n) }}</dd></div>
                            @endforeach
                        </dl>
                        <p class="text-xs text-zinc-500">O total não pode ficar menor que o que está fora nem que as unidades de patrimônio.
                            <a class="text-marca-700 underline-offset-2 hover:underline dark:text-marca-400" href="{{ legado('produtos', ['a' => 'editar', 'id' => $p->id]) }}#unidades">Ver unidades</a></p>
                    @endif
                </div>
            </section>

            {{-- Situação --}}
            <section class="cartao">
                <div class="px-5">
                    <label class="flex cursor-pointer items-start justify-between gap-4 py-4">
                        <span><span class="block text-sm font-medium">Ativo</span><span class="block text-xs text-zinc-500">Aparece para novas locações, reservas e orçamentos.</span></span>
                        <input type="checkbox" name="ativo" value="1" class="peer sr-only" @checked($ativo)>
                        <span class="relative mt-0.5 h-6 w-11 shrink-0 rounded-full bg-zinc-200 transition peer-checked:bg-marca-600 peer-focus-visible:ring-4 peer-focus-visible:ring-marca-500/30 dark:bg-zinc-700
                                     after:absolute after:top-0.5 after:left-0.5 after:size-5 after:rounded-full after:bg-white after:shadow after:transition peer-checked:after:translate-x-5"></span>
                    </label>
                </div>
            </section>

            {{-- Fiscal e observações --}}
            <section class="cartao">
                <div class="cartao-cabecalho"><h2 class="cartao-titulo">Outros dados</h2></div>
                <div class="space-y-4 p-5">
                    <label class="block">
                        <span class="text-sm font-medium">NCM</span>
                        <input type="text" name="ncm" value="{{ $v('ncm') }}" inputmode="numeric" maxlength="8" class="{{ $campo('ncm') }} tabular-nums">
                        @error('ncm')<span class="mt-1 block text-xs text-red-600 dark:text-red-400">{{ $message }}</span>@else
                        <span class="mt-1 block text-xs text-zinc-500">8 dígitos. Reservado para a futura NF-e de remessa.</span>@enderror
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium">Observações</span>
                        <textarea name="obs" rows="4" maxlength="2000" class="{{ $campo('obs') }} resize-y">{{ $v('obs') }}</textarea>
                        @error('obs')<span class="mt-1 block text-xs text-red-600 dark:text-red-400">{{ $message }}</span>@enderror
                    </label>
                </div>
            </section>
        </div>
    </div>

    {{-- Barra de salvar, sempre à vista --}}
    <div class="sticky bottom-0 z-10 -mx-4 mt-6 border-t border-zinc-200 bg-white/85 px-4 py-3 backdrop-blur-xl sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8 dark:border-zinc-800 dark:bg-zinc-950/85">
        <div class="flex items-center justify-end gap-2">
            <a class="btn-secundario" href="{{ route('produtos.index') }}">Cancelar</a>
            <button type="submit" class="btn-primario">{{ $novo ? 'Cadastrar produto' : 'Salvar alterações' }}</button>
        </div>
    </div>
</form>

@if ($uso)
    <section class="mt-6 flex flex-col gap-3 rounded-2xl border border-red-200 p-5 sm:flex-row sm:items-center sm:justify-between dark:border-red-900/60">
        <div>
            <h2 class="text-sm font-semibold text-red-700 dark:text-red-400">Excluir produto</h2>
            <p class="text-xs text-zinc-500">{{ $uso->usado ? 'Já foi usado (locação, reserva, orçamento, kit, patrimônio ou manutenção): não pode ser excluído. Desmarque “Ativo” para tirá-lo das novas locações.' : 'Nunca foi usado. A exclusão é definitiva.' }}</p>
        </div>
        @unless ($uso->usado)
            <form method="post" action="{{ route('produtos.destroy', $p->id) }}" data-confirmar="Excluir este produto definitivamente?">
                @csrf @method('DELETE')
                <button class="btn border border-red-200 bg-white text-red-600 hover:bg-red-50 dark:border-red-900 dark:bg-transparent dark:hover:bg-red-950">
                    <x-icone nome="fechar" class="size-4" /> Excluir produto
                </button>
            </form>
        @endunless
    </section>
@endif
@endsection
