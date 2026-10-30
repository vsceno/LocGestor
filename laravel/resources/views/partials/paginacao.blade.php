{{-- Paginação para um LengthAwarePaginator --}}
@if ($p->total() > 0)
    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-100 px-4 py-3 text-sm dark:border-zinc-800">
        <p class="text-zinc-500">
            @if ($p->lastPage() > 1)Mostrando {{ numero($p->firstItem()) }}–{{ numero($p->lastItem()) }} de @endif
            <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ numero($p->total()) }}</span> registro(s)
        </p>
        @if ($p->lastPage() > 1)
            <nav class="flex items-center gap-1" aria-label="Páginas">
                @foreach (range(max(1, $p->currentPage() - 2), min($p->lastPage(), $p->currentPage() + 2)) as $n)
                    @if ($n === $p->currentPage())
                        <span aria-current="page" class="grid size-8 place-items-center rounded-lg bg-marca-600 font-medium text-white">{{ $n }}</span>
                    @else
                        <a href="{{ $p->url($n) }}" class="grid size-8 place-items-center rounded-lg text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800">{{ $n }}</a>
                    @endif
                @endforeach
            </nav>
        @endif
    </div>
@endif
