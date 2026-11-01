@php
    $novo = $k === null;
    $enviado = (bool) old('_enviado');
    $v = fn (string $campo, $padrao = '') => old($campo, $k->$campo ?? $padrao);
    $ativo = $enviado ? (bool) old('ativo') : (bool) ($k->ativo ?? true);
    // Linhas da composição: as enviadas (se voltou com erro) ou as gravadas, mais linhas vazias para funcionar sem JavaScript
    if ($enviado) {
        $linhas = [];
        foreach ((array) old('produto_id', []) as $i => $pid) {
            if ((string) $pid !== '') {
                $linhas[] = ['produto_id' => (string) $pid, 'quantidade' => (string) (((array) old('quantidade', []))[$i] ?? '1')];
            }
        }
    } else {
        $linhas = array_map(fn ($it) => ['produto_id' => $it->produto_id, 'quantidade' => (string) $it->quantidade], $itens);
    }
    for ($i = 0, $vazias = $linhas ? 2 : 5; $i < $vazias; $i++) {
        $linhas[] = ['produto_id' => '', 'quantidade' => '1'];
    }
    $titulo = $novo ? 'Novo kit' : 'Editar kit';
    $migalhas = [['Produtos', route('produtos.index')], ['Kits', route('kits.index')]];
    $campo = fn (string $nome) => 'campo mt-1.5' . ($errors->has($nome) ? ' border-red-400 focus:border-red-500 focus:ring-red-500/15 dark:border-red-700' : '');
@endphp
@extends('layouts.app', ['titulo' => $titulo, 'ativo' => 'produtos', 'migalhas' => $migalhas])

@section('subtitulo', $novo ? 'Escolha os produtos e quantos de cada vão em um kit.' : $k->codigo . ' · ' . $k->nome)

@section('conteudo')
<form method="post" action="{{ $novo ? route('kits.store') : route('kits.update', $k->id) }}" autocomplete="off" novalidate data-form-kit>
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
        <section class="cartao xl:col-span-2">
            <div class="cartao-cabecalho">
                <h2 class="cartao-titulo">Composição</h2>
                <span class="text-sm text-zinc-500">Diária dos itens: <strong class="tabular-nums text-zinc-900 dark:text-zinc-100" data-kit-total>—</strong></span>
            </div>
            @error('itens')<p class="mx-5 mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950/50 dark:text-red-300">{{ $message }}</p>@enderror
            <div class="p-5">
                <div class="mb-2 hidden grid-cols-[1fr_7rem_2.25rem] gap-3 text-xs font-medium text-zinc-500 sm:grid">
                    <span>Produto</span><span class="text-right">Qtd. por kit</span><span></span>
                </div>
                <div class="space-y-2" data-kit-linhas>
                    @foreach ($linhas as $l)
                        <div class="grid grid-cols-[1fr_5.5rem_2.25rem] items-center gap-3 sm:grid-cols-[1fr_7rem_2.25rem]" data-kit-linha>
                            <select name="produto_id[]" aria-label="Produto" class="campo">
                                <option value="">— escolha o produto —</option>
                                @foreach ($produtos as $id => $p)
                                    <option value="{{ $id }}" data-diaria="{{ $p->valor_diaria }}" @selected($l['produto_id'] === $id)>{{ $p->descricao }} ({{ $p->codigo }}){{ $p->ativo ? '' : ' — inativo' }}</option>
                                @endforeach
                            </select>
                            <input type="number" name="quantidade[]" value="{{ $l['quantidade'] }}" min="1" max="100000" step="1" aria-label="Quantidade por kit" class="campo text-right tabular-nums">
                            <button type="button" class="btn-icone hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-500/10" title="Remover linha" aria-label="Remover linha" data-kit-remover>
                                <x-icone nome="fechar" class="size-[18px]" /></button>
                        </div>
                    @endforeach
                </div>
                <button type="button" class="btn-secundario mt-3" data-kit-adicionar hidden><x-icone nome="mais" class="size-4" /> Adicionar produto</button>
                <p class="mt-3 text-xs text-zinc-500">Linhas sem produto são ignoradas. O mesmo produto em duas linhas soma as quantidades.</p>
            </div>
        </section>

        <div class="space-y-6">
            <section class="cartao">
                <div class="cartao-cabecalho"><h2 class="cartao-titulo">Kit</h2></div>
                <div class="space-y-4 p-5">
                    <label class="block">
                        <span class="text-sm font-medium">Código <span class="text-red-500">*</span></span>
                        <input type="text" name="codigo" value="{{ $v('codigo') }}" required maxlength="30" class="{{ $campo('codigo') }} font-mono uppercase" @if ($novo) autofocus @endif>
                        @error('codigo')<span class="mt-1 block text-xs text-red-600 dark:text-red-400">{{ $message }}</span>@enderror
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium">Nome <span class="text-red-500">*</span></span>
                        <input type="text" name="nome" value="{{ $v('nome') }}" required maxlength="150" class="{{ $campo('nome') }}">
                        @error('nome')<span class="mt-1 block text-xs text-red-600 dark:text-red-400">{{ $message }}</span>@enderror
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium">Observações</span>
                        <textarea name="obs" rows="3" maxlength="2000" class="{{ $campo('obs') }} resize-y">{{ $v('obs') }}</textarea>
                        @error('obs')<span class="mt-1 block text-xs text-red-600 dark:text-red-400">{{ $message }}</span>@enderror
                    </label>
                </div>
            </section>
            <section class="cartao">
                <div class="px-5">
                    <label class="flex cursor-pointer items-start justify-between gap-4 py-4">
                        <span><span class="block text-sm font-medium">Ativo</span><span class="block text-xs text-zinc-500">Aparece na locação e na reserva.</span></span>
                        <input type="checkbox" name="ativo" value="1" class="peer sr-only" @checked($ativo)>
                        <span class="relative mt-0.5 h-6 w-11 shrink-0 rounded-full bg-zinc-200 transition peer-checked:bg-marca-600 peer-focus-visible:ring-4 peer-focus-visible:ring-marca-500/30 dark:bg-zinc-700
                                     after:absolute after:top-0.5 after:left-0.5 after:size-5 after:rounded-full after:bg-white after:shadow after:transition peer-checked:after:translate-x-5"></span>
                    </label>
                </div>
            </section>
        </div>
    </div>

    <div class="sticky bottom-0 z-10 -mx-4 mt-6 border-t border-zinc-200 bg-white/85 px-4 py-3 backdrop-blur-xl sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8 dark:border-zinc-800 dark:bg-zinc-950/85">
        <div class="flex items-center justify-end gap-2">
            <a class="btn-secundario" href="{{ route('kits.index') }}">Cancelar</a>
            <button type="submit" class="btn-primario">{{ $novo ? 'Cadastrar kit' : 'Salvar alterações' }}</button>
        </div>
    </div>
</form>

@unless ($novo)
    <section class="mt-6 flex flex-col gap-3 rounded-2xl border border-red-200 p-5 sm:flex-row sm:items-center sm:justify-between dark:border-red-900/60">
        <div>
            <h2 class="text-sm font-semibold text-red-700 dark:text-red-400">Excluir kit</h2>
            <p class="text-xs text-zinc-500">Os produtos e as locações já feitas não são afetados.</p>
        </div>
        <form method="post" action="{{ route('kits.destroy', $k->id) }}" data-confirmar="Excluir este kit? Os produtos e as locações não são afetados.">
            @csrf @method('DELETE')
            <button class="btn border border-red-200 bg-white text-red-600 hover:bg-red-50 dark:border-red-900 dark:bg-transparent dark:hover:bg-red-950">
                <x-icone nome="fechar" class="size-4" /> Excluir kit
            </button>
        </form>
    </section>
@endunless
@endsection
