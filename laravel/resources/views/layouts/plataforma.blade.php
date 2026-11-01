<!DOCTYPE html>
<html lang="pt-BR" class="h-full">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/png" href="{{ asset('assets/brand/emblema-locgestor-64.png') }}">
<title>{{ $titulo ?? 'Plataforma' }} · Painel da plataforma · LocGestor</title>
<script>try { var t = localStorage.getItem('lg-tema'); if (t === 'escuro' || (!t && matchMedia('(prefers-color-scheme: dark)').matches)) document.documentElement.classList.add('dark'); } catch (e) {}</script>
@vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-zinc-50 text-zinc-900 dark:bg-zinc-950 dark:text-zinc-100">
@php
    // Módulos do painel, na ordem do menu antigo (MENU_PLATAFORMA)
    $menu = [
        'tenants'   => ['Locadoras', 'plataforma.locadoras'],
        'numeros'   => ['Números', 'plataforma.numeros'],
        'uso'       => ['Uso', 'plataforma.uso'],
        'recados'   => ['Recados', 'plataforma.recados.index'],
        'auditoria' => ['Auditoria', 'plataforma.auditoria'],
        'planos'    => ['Planos', 'plataforma.planos.index'],
        'conta'     => ['Minha senha', 'plataforma.conta'],
    ];
@endphp
<header class="border-b border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
    <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-3 px-4 py-3 sm:px-6">
        <div class="flex items-center gap-3">
            <img src="{{ asset('assets/brand/emblema-locgestor-64.png') }}" alt="" class="size-9 object-contain">
            <div><p class="text-sm font-semibold">LocGestor</p><p class="text-xs text-zinc-500">Painel da plataforma</p></div>
        </div>
        <nav class="flex flex-wrap items-center gap-1" aria-label="Módulos da plataforma">
            @foreach ($menu as $chave => [$rotulo, $rota])
                @continue(!\Illuminate\Support\Facades\Route::has($rota))
                <a href="{{ route($rota) }}" @if (($ativo ?? '') === $chave) aria-current="page" @endif
                   class="rounded-lg px-3 py-1.5 text-sm font-medium transition
                          {{ ($ativo ?? '') === $chave ? 'bg-marca-50 text-marca-700 dark:bg-marca-500/10 dark:text-marca-400' : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800' }}">{{ $rotulo }}</a>
            @endforeach
        </nav>
        <div class="flex items-center gap-3">
            <span class="text-sm text-zinc-500">{{ $plataformaUsuario['nome'] ?? '' }}</span>
            <form method="post" action="{{ route('plataforma.sair') }}">
                @csrf
                <button class="btn-secundario">Sair</button>
            </form>
        </div>
    </div>
</header>

<main class="mx-auto max-w-7xl px-4 py-8 sm:px-6">
    @foreach ((array) session('flash', []) as $f)
        @php
            $cor = match ($f['tipo']) {
                'erro' => 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300',
                'aviso' => 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-300',
                default => 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-300',
            };
        @endphp
        <div role="alert" class="mb-6 flex gap-2 rounded-lg border px-3 py-2.5 text-sm {{ $cor }}">
            <x-icone nome="{{ $f['tipo'] === 'erro' ? 'alerta' : 'ok' }}" class="mt-0.5 size-4" /><span>{{ $f['msg'] }}</span>
        </div>
    @endforeach

    <h1 class="text-2xl font-semibold tracking-tight">{{ $titulo ?? '' }}</h1>
    @isset($subtitulo)<p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ $subtitulo }}</p>@endisset

    <div class="mt-6">
        @yield('conteudo')
    </div>
</main>
@yield('scripts')
</body>
</html>
