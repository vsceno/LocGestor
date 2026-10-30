@extends('layouts.app', ['titulo' => 'Locações', 'ativo' => 'locacoes'])

@section('subtitulo', 'Equipamentos na rua, devoluções e o que cada cliente deve.')

@section('acoes')
    <a class="btn-primario" href="{{ route('locacoes.create') }}"><x-icone nome="mais" class="size-4" /> Nova locação</a>
@endsection

@section('conteudo')
<section class="cartao overflow-hidden">
    {{-- O nome do cliente buscado vai por POST e fica na sessão, nunca no endereço --}}
    <form method="post" action="{{ route('locacoes.filtrar') }}" data-busca-viva="#tabela-locacoes"
          class="flex flex-col gap-3 border-b border-zinc-100 p-4 xl:flex-row xl:items-center dark:border-zinc-800">
        @csrf
        <input type="hidden" name="aba" value="{{ $aba }}">
        <div class="flex shrink-0 gap-1 overflow-x-auto rounded-xl bg-zinc-100 p-1 dark:bg-zinc-800" role="group" aria-label="Situação">
            @foreach ($abas as $valor => $rotulo)
                <button type="submit" name="aba" value="{{ $valor }}" @if ($valor === $aba) aria-pressed="true" @endif
                        class="flex shrink-0 items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium transition
                               {{ $valor === $aba ? 'bg-white text-zinc-900 shadow-xs dark:bg-zinc-900 dark:text-zinc-100' : 'text-zinc-500 hover:text-zinc-900 dark:hover:text-zinc-100' }}">
                    {{ $rotulo }}
                    <span class="rounded-md px-1.5 text-xs tabular-nums
                                 {{ $valor === 'atrasadas' && $contagem->atrasadas > 0 ? 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-400'
                                    : ($valor === $aba ? 'bg-marca-100 text-marca-700 dark:bg-marca-500/15 dark:text-marca-400' : 'bg-zinc-200/70 dark:bg-zinc-700') }}">{{ numero($contagem->$valor) }}</span>
                </button>
            @endforeach
        </div>
        <label class="relative xl:ml-auto xl:w-80">
            <x-icone nome="busca" class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-zinc-400" />
            <input type="search" name="q" value="{{ $q }}" placeholder="Nº da locação ou nome do cliente" aria-label="Buscar locações"
                   maxlength="100" autocomplete="off" class="campo pl-9">
        </label>
    </form>
    <div id="tabela-locacoes" class="transition-opacity">
        @include('locacoes._tabela')
    </div>
</section>
@endsection
