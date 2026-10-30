@php
    // Telas já no Laravel usam rota; as outras abrem no sistema antigo (pela ponte, já logado)
    $grupos = [
        'Operação' => [
            'painel'      => ['Painel', 'painel', route('painel')],
            'locacoes'    => ['Locações', 'locacoes', route('locacoes.index')],
            'reservas'    => ['Reservas', 'reservas', route('reservas.index')],
            'orcamentos'  => ['Orçamentos', 'orcamentos', route('orcamentos.index')],
            'roteiro'     => ['Roteiro', 'roteiro', route('roteiro.index')],
        ],
        'Cadastros' => [
            'clientes'    => ['Clientes', 'clientes', route('clientes.index')],
            'produtos'    => ['Produtos', 'produtos', route('produtos.index')],
            'manutencoes' => ['Manutenção', 'manutencao', route('manutencoes.index')],
        ],
        'Financeiro' => [
            'caixa'       => ['Fluxo de caixa', 'caixa', route('caixa.index')],
            'recibos'     => ['Recibos', 'recibos', route('recibos.index')],
            'relatorios'  => ['Relatórios', 'relatorios', route('relatorios.index')],
        ],
    ];
    $nomeLocadora = $empresa->nome_fantasia ?: $empresa->razao_social;
    $temLogo = ($empresa->logo_arquivo ?? '') !== '';
    $migalhas = $migalhas ?? [];
    // Ações rápidas da paleta de comandos (Ctrl+K)
    $comandos = [
        ['Nova locação', route('locacoes.create'), 'locacoes'],
        ['Novo cliente', route('clientes.create'), 'clientes'],
        ['Novo produto', route('produtos.create'), 'produtos'],
        ['Novo recibo', route('recibos.create'), 'recibos'],
        ['Novo orçamento', route('orcamentos.create'), 'orcamentos'],
        ['Nova reserva', route('reservas.create'), 'reservas'],
        ['Nova manutenção', route('manutencoes.create'), 'manutencao'],
        ['Lançar no caixa', route('caixa.index'), 'caixa'],
        ['Ir para o painel', route('painel'), 'painel'],
        ['Ir para clientes', route('clientes.index'), 'clientes'],
        ['Ir para locações', route('locacoes.index'), 'locacoes'],
        ['Ir para produtos', route('produtos.index'), 'produtos'],
        ['Ir para relatórios', route('relatorios.index'), 'relatorios'],
        ['Configurações', route('config.index'), 'config'],
    ];
    $acoesPaleta = array_map(fn ($c) => ['rotulo' => $c[0], 'url' => $c[1], 'ico' => $c[2]], $comandos);
@endphp
<!DOCTYPE html>
<html lang="pt-BR" class="h-full">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>{{ $titulo }} · {{ $nomeLocadora }}</title>
{{-- Tema e menu recolhido aplicados antes de desenhar a página (sem piscar) --}}
<script>
    (function () {
        try {
            var t = localStorage.getItem('lg-tema');
            if (t === 'escuro' || (!t && matchMedia('(prefers-color-scheme: dark)').matches)) { document.documentElement.classList.add('dark'); }
            if (localStorage.getItem('lg-menu') !== 'aberto') { document.documentElement.classList.add('menu-recolhido'); }
        } catch (e) {}
    })();
</script>
@vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full">
<div class="flex min-h-full">
    {{-- Fundo escuro do menu no celular --}}
    <div data-menu-fundo class="fixed inset-0 z-30 hidden bg-zinc-950/40 backdrop-blur-sm lg:hidden"></div>

    {{-- Menu lateral --}}
    <aside data-menu class="fixed inset-y-0 left-0 z-40 flex w-64 -translate-x-full flex-col border-r border-zinc-200 bg-white transition-all duration-200
                            lg:translate-x-0 dark:border-zinc-800 dark:bg-zinc-900
                            menu-fechado:lg:w-[72px] [.menu-recolhido_&]:lg:shadow-2xl [.menu-recolhido_&]:lg:shadow-zinc-900/10 menu-fechado:lg:shadow-none">
        <div class="flex h-16 items-center gap-3 border-b border-zinc-100 px-4 dark:border-zinc-800">
            @if ($temLogo)
                <img src="{{ route('arquivos.logo', ['v' => substr(md5($empresa->logo_arquivo), 0, 8)]) }}" alt="{{ $nomeLocadora }}"
                     class="h-9 max-w-36 rounded-md bg-white object-contain p-0.5 recolhido:lg:hidden">
            @endif
            <span class="grid size-9 shrink-0 place-items-center rounded-xl bg-gradient-to-br from-marca-400 to-marca-700 text-sm font-bold text-white shadow-md shadow-marca-600/30
                         {{ $temLogo ? 'hidden recolhido:lg:grid' : '' }}">LG</span>
            <div class="min-w-0 recolhido:lg:hidden {{ $temLogo ? 'sr-only' : '' }}">
                <p class="truncate text-sm font-semibold">{{ $nomeLocadora }}</p>
                <p class="text-xs text-zinc-500">LocGestor</p>
            </div>
        </div>

        <nav class="flex-1 space-y-6 overflow-x-hidden overflow-y-auto px-3 py-5 [scrollbar-width:thin] recolhido:lg:space-y-3">
            @foreach ($grupos as $grupo => $itens)
                <div class="recolhido:lg:border-t recolhido:lg:border-zinc-100 recolhido:lg:pt-3 recolhido:lg:first:border-0 recolhido:lg:first:pt-0 dark:border-zinc-800">
                    <p class="mb-1.5 px-3 text-[11px] font-semibold tracking-wider text-zinc-400 uppercase recolhido:lg:hidden">{{ $grupo }}</p>
                    <ul class="space-y-0.5">
                        @foreach ($itens as $chave => [$rotulo, $ico, $url])
                            @php $atual = $chave === ($ativo ?? ''); @endphp
                            <li>
                                <a href="{{ $url }}" title="{{ $rotulo }}" @if ($atual) aria-current="page" @endif
                                   class="group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition
                                          {{ $atual ? 'bg-marca-50 text-marca-700 dark:bg-marca-500/10 dark:text-marca-400'
                                                    : 'text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-100' }}">
                                    <x-icone :nome="$ico" class="size-5 shrink-0 {{ $atual ? 'text-marca-600 dark:text-marca-400' : 'text-zinc-400 group-hover:text-zinc-600 dark:group-hover:text-zinc-300' }}" />
                                    <span class="truncate recolhido:lg:hidden">{{ $rotulo }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </nav>

        <div class="border-t border-zinc-100 p-3 dark:border-zinc-800">
            <a href="{{ route('config.index') }}" title="Configurações"
               class="mb-1 flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800">
                <x-icone nome="config" class="size-5 text-zinc-400" /><span class="recolhido:lg:hidden">Configurações</span>
            </a>
            <div class="flex items-center gap-3 rounded-xl px-2 py-2">
                <span class="avatar size-9 from-zinc-600 to-zinc-800 text-xs">{{ iniciais($usuario->nome) }}</span>
                <div class="min-w-0 flex-1 recolhido:lg:hidden">
                    <p class="truncate text-sm font-medium">{{ $usuario->nome }}</p>
                    <p class="truncate text-xs text-zinc-500">{{ $usuario->perfil === 'admin' ? 'Administrador' : ucfirst($usuario->perfil) }}</p>
                </div>
                <form method="post" action="{{ route('sair') }}" class="recolhido:lg:hidden">
                    @csrf
                    <button class="btn-icone" title="Sair" aria-label="Sair"><x-icone nome="sair" class="size-[18px]" /></button>
                </form>
            </div>
        </div>
    </aside>

    {{-- Área principal --}}
    <div class="flex min-w-0 flex-1 flex-col transition-all duration-200 lg:pl-64 [.menu-recolhido_&]:lg:pl-[72px]">
        <header class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-zinc-200/80 bg-white/80 px-4 backdrop-blur-xl sm:px-6 dark:border-zinc-800 dark:bg-zinc-950/80">
            <button class="btn-icone lg:hidden" data-abrir-menu aria-label="Abrir menu"><x-icone nome="menu" /></button>
            <button class="btn-icone hidden lg:inline-grid" data-recolher-menu aria-label="Fixar ou recolher o menu" title="Fixar o menu aberto / deixar retrátil"><x-icone nome="recolher" /></button>

            <nav aria-label="Trilha" class="hidden min-w-0 items-center gap-1.5 text-sm sm:flex">
                <a href="{{ route('painel') }}" class="text-zinc-500 hover:text-zinc-900 dark:hover:text-zinc-100">Início</a>
                @foreach ($migalhas as [$rotuloMigalha, $urlMigalha])
                    <x-icone nome="seta" class="size-3.5 text-zinc-300" />
                    <a href="{{ $urlMigalha }}" class="text-zinc-500 hover:text-zinc-900 dark:hover:text-zinc-100">{{ $rotuloMigalha }}</a>
                @endforeach
                <x-icone nome="seta" class="size-3.5 text-zinc-300" />
                <span class="truncate font-medium text-zinc-900 dark:text-zinc-100">{{ $titulo }}</span>
            </nav>

            <div class="ml-auto flex items-center gap-2">
                <button data-abrir-comandos
                        class="flex h-9 w-full items-center gap-2 rounded-lg border border-zinc-200 bg-zinc-50 px-3 text-sm text-zinc-500 transition hover:border-zinc-300 hover:bg-white
                               sm:w-64 dark:border-zinc-800 dark:bg-zinc-900 dark:hover:border-zinc-700">
                    <x-icone nome="busca" class="size-4" />
                    <span class="flex-1 text-left">Buscar ou executar…</span>
                    <kbd class="hidden rounded border border-zinc-200 bg-white px-1.5 text-[11px] font-medium text-zinc-500 sm:inline dark:border-zinc-700 dark:bg-zinc-800">Ctrl K</kbd>
                </button>
                <button class="btn-icone" data-tema aria-label="Trocar tema claro/escuro" title="Tema claro/escuro">
                    <x-icone nome="lua" class="size-[18px] dark:hidden" /><x-icone nome="sol" class="hidden size-[18px] dark:block" />
                </button>
            </div>
        </header>

        <main class="mx-auto w-full max-w-7xl flex-1 px-4 py-6 sm:px-6 lg:px-8">
            {{-- Telas com cabeçalho próprio (ex.: ficha do cliente) passam ocultarTitulo => true --}}
            @unless ($ocultarTitulo ?? false)
            <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
                <div class="min-w-0">
                    <h1 class="truncate text-2xl font-semibold tracking-tight">{{ $titulo }}</h1>
                    @hasSection('subtitulo')<p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">@yield('subtitulo')</p>@endif
                </div>
                @hasSection('acoes')<div class="flex flex-wrap items-center gap-2">@yield('acoes')</div>@endif
            </div>
            @endunless
            @yield('conteudo')
        </main>
    </div>
</div>

{{-- Avisos flutuantes (somem sozinhos; os de erro ficam até fechar) --}}
<div class="pointer-events-none fixed top-4 right-4 z-50 flex w-full max-w-sm flex-col gap-2" data-avisos>
    @foreach ((array) session('flash', []) as $f)
        @php $erro = $f['tipo'] === 'erro'; $aviso = $f['tipo'] === 'aviso'; @endphp
        <div role="alert" data-aviso="{{ $f['tipo'] }}"
             class="pointer-events-auto flex items-start gap-3 rounded-xl border bg-white p-4 shadow-lg shadow-zinc-900/10 dark:bg-zinc-900
                    {{ $erro ? 'border-red-200 dark:border-red-900' : ($aviso ? 'border-amber-200 dark:border-amber-900' : 'border-emerald-200 dark:border-emerald-900') }}">
            <x-icone :nome="$erro || $aviso ? 'alerta' : 'ok'" class="size-5 {{ $erro ? 'text-red-600' : ($aviso ? 'text-amber-600' : 'text-emerald-600') }}" />
            <p class="flex-1 text-sm">{{ $f['msg'] }}</p>
            <button class="text-zinc-400 hover:text-zinc-600" data-fechar-aviso aria-label="Fechar"><x-icone nome="fechar" class="size-4" /></button>
        </div>
    @endforeach
</div>

{{-- Paleta de comandos (Ctrl+K): busca no banco enquanto digita (por POST) e ações rápidas --}}
<dialog data-comandos class="m-auto mt-[12vh] w-[min(640px,calc(100%-2rem))] overflow-hidden rounded-2xl border border-zinc-200 bg-white p-0 text-zinc-900 shadow-2xl
                            backdrop:bg-zinc-950/40 backdrop:backdrop-blur-sm dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-100"
        data-url-busca="{{ route('busca.rapida') }}">
    <div class="flex items-center gap-3 border-b border-zinc-100 px-4 dark:border-zinc-800">
        <x-icone nome="busca" class="size-5 text-zinc-400" />
        <input type="text" data-comandos-campo placeholder="Busque cliente, CPF, locação (#141), produto… ou digite uma ação"
               class="h-14 flex-1 bg-transparent text-base outline-none placeholder:text-zinc-400" autocomplete="off" aria-label="Buscar ou executar">
        <kbd class="rounded border border-zinc-200 px-1.5 text-[11px] text-zinc-500 dark:border-zinc-700">Esc</kbd>
    </div>
    <div data-comandos-lista class="max-h-[60vh] overflow-y-auto p-2" role="listbox"></div>
    <div class="flex items-center gap-4 border-t border-zinc-100 bg-zinc-50 px-4 py-2 text-xs text-zinc-500 dark:border-zinc-800 dark:bg-zinc-950/50">
        <span><kbd class="font-sans">↑↓</kbd> navegar</span><span><kbd class="font-sans">Enter</kbd> abrir</span><span><kbd class="font-sans">Esc</kbd> fechar</span>
    </div>
    <script type="application/json" data-comandos-acoes>@json($acoesPaleta)</script>
    {{-- Ícones usados pela paleta (o JavaScript copia daqui) --}}
    <template data-icones>
        @foreach (['clientes', 'locacoes', 'reservas', 'orcamentos', 'produtos', 'manutencao', 'caixa', 'painel', 'relatorios', 'config'] as $ic)
            <span data-ico="{{ $ic }}"><x-icone :nome="$ic" class="size-4" /></span>
        @endforeach
    </template>
</dialog>
@yield('scripts')
</body>
</html>
