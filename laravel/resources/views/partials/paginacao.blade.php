{{-- Paginação no estilo do sistema (.pager), para um LengthAwarePaginator --}}
@if ($p->lastPage() > 1)
    <nav class="pager" aria-label="Páginas">
        @foreach (range(max(1, $p->currentPage() - 3), min($p->lastPage(), $p->currentPage() + 3)) as $n)
            @if ($n === $p->currentPage())
                <span class="atual" aria-current="page">{{ $n }}</span>
            @else
                <a href="{{ $p->url($n) }}">{{ $n }}</a>
            @endif
        @endforeach
        <span class="pager-total">{{ numero($p->total()) }} registro(s)</span>
    </nav>
@elseif ($p->total() > 0)
    <div class="pager"><span class="pager-total">{{ numero($p->total()) }} registro(s)</span></div>
@endif
