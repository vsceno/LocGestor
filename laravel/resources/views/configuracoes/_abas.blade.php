{{-- Seções de configurações. Administrador vê tudo; os demais, só a própria senha --}}
@php
    $abas = session('usuario.perfil') === 'admin'
        ? ['config.index' => 'Empresa e documentos', 'config.usuarios' => 'Usuários', 'config.auditoria' => 'Auditoria', 'config.senha' => 'Minha senha']
        : ['config.senha' => 'Minha senha'];
@endphp
<nav class="mb-6 inline-flex gap-1 rounded-xl bg-zinc-100 p-1 dark:bg-zinc-800" aria-label="Seções de configurações">
    @foreach ($abas as $rota => $rotulo)
        @if ($rota === $ativa)
            <a href="{{ route($rota) }}" aria-current="page" class="rounded-lg bg-white px-3 py-1.5 text-sm font-medium text-zinc-900 shadow-xs dark:bg-zinc-900 dark:text-zinc-100">{{ $rotulo }}</a>
        @else
            <a href="{{ route($rota) }}" class="rounded-lg px-3 py-1.5 text-sm font-medium text-zinc-500 hover:text-zinc-900 dark:hover:text-zinc-100">{{ $rotulo }}</a>
        @endif
    @endforeach
</nav>
