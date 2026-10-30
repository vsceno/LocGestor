@php
    $linhaComErro = old('_linha');
    $erroCampo = 'campo border-red-400 focus:border-red-500 focus:ring-red-500/15 dark:border-red-700';
@endphp
@extends('layouts.app', ['titulo' => 'Categorias', 'ativo' => 'produtos', 'migalhas' => [['Produtos', route('produtos.index')]]])

@section('subtitulo', 'Agrupam os produtos na lista e nos filtros. Renomeie direto na linha.')

@section('conteudo')
<div class="max-w-3xl space-y-6">
    <section class="cartao">
        <form method="post" action="{{ route('categorias.store') }}" class="flex flex-col gap-3 p-4 sm:flex-row sm:items-start">
            @csrf
            <label class="block flex-1">
                <span class="sr-only">Nova categoria</span>
                <input type="text" name="nome" value="{{ $linhaComErro === null ? old('nome') : '' }}" required maxlength="60" placeholder="Nova categoria (ex.: Andaimes)"
                       class="{{ $errors->has('nome') ? $erroCampo : 'campo' }}">
                @error('nome')<span class="mt-1 block text-xs text-red-600 dark:text-red-400">{{ $message }}</span>@enderror
            </label>
            <button class="btn-primario"><x-icone nome="mais" class="size-4" /> Adicionar</button>
        </form>
    </section>

    <section class="cartao overflow-hidden">
        @if (!$categorias)
            <div class="px-6 py-14 text-center">
                <span class="mx-auto grid size-12 place-items-center rounded-full bg-marca-50 text-marca-600 dark:bg-marca-500/10"><x-icone nome="produtos" /></span>
                <p class="mt-3 font-medium">Nenhuma categoria ainda</p>
                <p class="mt-1 text-sm text-zinc-500">Crie a primeira no campo acima.</p>
            </div>
        @else
            <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @foreach ($categorias as $c)
                    @php $campoLinha = "nome_{$c->id}"; @endphp
                    <li class="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-start">
                        <form method="post" action="{{ route('categorias.update', $c->id) }}" class="flex flex-1 items-start gap-2">
                            @csrf @method('PUT')
                            <label class="block flex-1">
                                <span class="sr-only">Nome da categoria</span>
                                <input type="text" name="nome" value="{{ $linhaComErro === $c->id ? old('nome') : $c->nome }}" required maxlength="60"
                                       class="{{ $errors->has($campoLinha) ? $erroCampo : 'campo' }}">
                                @error($campoLinha)<span class="mt-1 block text-xs text-red-600 dark:text-red-400">{{ $message }}</span>@enderror
                            </label>
                            <button class="btn-secundario">Renomear</button>
                        </form>
                        <div class="flex items-center gap-3 sm:h-10">
                            <span class="{{ $c->produtos > 0 ? 'selo-azul' : 'selo-cinza' }} w-24 justify-center">{{ numero($c->produtos) }} {{ $c->produtos == 1 ? 'produto' : 'produtos' }}</span>
                            @if ((int) $c->produtos === 0)
                                <form method="post" action="{{ route('categorias.destroy', $c->id) }}" data-confirmar="Excluir a categoria {{ $c->nome }}?">
                                    @csrf @method('DELETE')
                                    <button class="btn-icone hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-500/10" title="Excluir" aria-label="Excluir a categoria {{ $c->nome }}">
                                        <x-icone nome="fechar" class="size-[18px]" /></button>
                                </form>
                            @else
                                <span class="size-9" aria-hidden="true"></span>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
@endsection
