@extends('layouts.app', ['titulo' => 'Busca'])

@section('subtitulo')
    @if (mb_strlen($q) >= 2) Resultados para “{{ $q }}” @else Dica: aperte <kbd class="rounded border border-zinc-300 px-1 dark:border-zinc-700">Ctrl K</kbd> de qualquer tela @endif
@endsection

@section('conteudo')
@if (!$grupos)
    <section class="cartao px-6 py-16 text-center">
        <span class="mx-auto grid size-12 place-items-center rounded-full bg-marca-50 text-marca-600 dark:bg-marca-500/10"><x-icone nome="busca" /></span>
        <p class="mt-3 font-medium">{{ mb_strlen($q) >= 2 ? 'Nada encontrado para “' . $q . '”' : 'O que você procura?' }}</p>
        <p class="mx-auto mt-1 max-w-md text-sm text-zinc-500">Nome ou CPF/CNPJ do cliente, telefone, número da locação (#141), código ou descrição do produto, patrimônio.</p>
    </section>
@else
    <div class="space-y-6">
        @foreach ($grupos as $grupo => $itens)
            <section class="cartao overflow-hidden">
                <div class="cartao-cabecalho"><h2 class="cartao-titulo">{{ $grupo }}</h2><span class="selo-cinza">{{ count($itens) }}</span></div>
                <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach ($itens as $i)
                        <li>
                            <a href="{{ $i['url'] }}" class="group flex items-center gap-3 px-5 py-3 transition hover:bg-marca-50/50 dark:hover:bg-zinc-800/50">
                                <span class="grid size-9 place-items-center rounded-lg bg-zinc-100 text-zinc-500 dark:bg-zinc-800"><x-icone :nome="$i['ico']" class="size-[18px]" /></span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-medium">{{ $i['rotulo'] }}</span>
                                    <span class="block truncate text-xs text-zinc-500">{{ $i['detalhe'] }}</span>
                                </span>
                                <x-icone nome="seta" class="size-4 text-zinc-300 transition group-hover:translate-x-0.5 group-hover:text-marca-500" />
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    </div>
@endif
@endsection
