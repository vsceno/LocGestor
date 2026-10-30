@extends('layouts.app', ['titulo' => 'Produtos', 'ativo' => 'produtos'])

@section('subtitulo', 'Equipamentos para alugar: preços, estoque e o que está disponível hoje.')

@section('acoes')
    <a class="btn-secundario" href="{{ route('categorias.index') }}">Categorias</a>
    <a class="btn-secundario" href="{{ route('kits.index') }}">Kits</a>
    <a class="btn-primario" href="{{ route('produtos.create') }}"><x-icone nome="mais" class="size-4" /> Novo produto</a>
@endsection

@section('conteudo')
<section class="cartao overflow-hidden">
    <form method="post" action="{{ route('produtos.filtrar') }}" data-busca-viva="#tabela-produtos"
          class="flex flex-col gap-3 border-b border-zinc-100 p-4 lg:flex-row lg:items-center dark:border-zinc-800">
        @csrf
        {{-- Antes dos botões: quem chega por último vale, então o botão clicado ganha deste campo --}}
        <input type="hidden" name="situacao" value="{{ $situacao }}">
        <div class="flex shrink-0 gap-1 rounded-xl bg-zinc-100 p-1 dark:bg-zinc-800" role="group" aria-label="Situação">
            @foreach ($situacoes as $valor => $rotulo)
                <button type="submit" name="situacao" value="{{ $valor }}" @if ($valor === $situacao) aria-pressed="true" @endif
                        class="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium transition
                               {{ $valor === $situacao ? 'bg-white text-zinc-900 shadow-xs dark:bg-zinc-900 dark:text-zinc-100' : 'text-zinc-500 hover:text-zinc-900 dark:hover:text-zinc-100' }}">
                    {{ $rotulo }}
                    <span class="rounded-md px-1.5 text-xs tabular-nums {{ $valor === $situacao ? 'bg-marca-100 text-marca-700 dark:bg-marca-500/15 dark:text-marca-400' : 'bg-zinc-200/70 dark:bg-zinc-700' }}">{{ numero($contagem->$valor) }}</span>
                </button>
            @endforeach
        </div>
        <div class="flex flex-col gap-3 sm:flex-row lg:ml-auto">
            <select name="categoria" aria-label="Categoria" class="campo sm:w-56" data-envia-ao-mudar>
                <option value="">Todas as categorias</option>
                @foreach ($categorias as $id => $nome)<option value="{{ $id }}" @selected($categoria === $id)>{{ $nome }}</option>@endforeach
            </select>
            <label class="relative sm:w-80">
                <x-icone nome="busca" class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-zinc-400" />
                <input type="search" name="q" value="{{ $q }}" placeholder="Código, descrição ou código de barras" aria-label="Buscar produtos"
                       maxlength="100" autocomplete="off" class="campo pl-9">
            </label>
        </div>
    </form>
    <div id="tabela-produtos" class="transition-opacity">
        @include('produtos._tabela')
    </div>
</section>
@endsection
