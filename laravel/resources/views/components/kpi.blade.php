@props(['rotulo', 'valor', 'detalhe' => '', 'ico', 'cor' => 'bg-zinc-100 text-zinc-600', 'url' => null])
@php $classes = 'cartao block p-4 sm:p-5' . ($url ? ' transition hover:-translate-y-0.5 hover:shadow-md' : ''); @endphp
@if ($url)<a href="{{ $url }}" class="{{ $classes }}">@else<div class="{{ $classes }}">@endif
    <div class="flex items-start justify-between gap-3">
        <p class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ $rotulo }}</p>
        <span class="grid size-9 shrink-0 place-items-center rounded-xl {{ $cor }}"><x-icone :nome="$ico" class="size-[18px]" /></span>
    </div>
    <p class="mt-2 text-xl font-semibold tracking-tight tabular-nums sm:text-3xl">{{ $valor }}</p>
    @if ($detalhe !== '')<p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $detalhe }}</p>@endif
@if ($url)</a>@else</div>@endif
