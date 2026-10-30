{{-- Seções do caixa: lançamentos, contas a pagar e previsão --}}
<nav class="mb-6 inline-flex gap-1 rounded-xl bg-zinc-100 p-1 dark:bg-zinc-800" aria-label="Seções do caixa">
    @foreach (['lancamentos' => ['Lançamentos', route('caixa.index')], 'contas' => ['Contas a pagar', route('caixa.contas')], 'previsao' => ['Previsão', route('caixa.previsao')]] as $secao => [$rotulo, $url])
        @if ($secao === $ativa)
            <a href="{{ $url }}" aria-current="page" class="rounded-lg bg-white px-3 py-1.5 text-sm font-medium text-zinc-900 shadow-xs dark:bg-zinc-900 dark:text-zinc-100">{{ $rotulo }}</a>
        @else
            <a href="{{ $url }}" class="rounded-lg px-3 py-1.5 text-sm font-medium text-zinc-500 hover:text-zinc-900 dark:hover:text-zinc-100">{{ $rotulo }}</a>
        @endif
    @endforeach
</nav>
