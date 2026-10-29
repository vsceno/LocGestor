@php
    // Telas já no Laravel usam rota; as outras ainda abrem no sistema antigo
    $menu = [
        'painel'      => ['Painel', 'painel', route('painel')],
        'locacoes'    => ['Locações', 'locacoes', legado('locacoes')],
        'reservas'    => ['Reservas', 'reservas', legado('reservas')],
        'orcamentos'  => ['Orçamentos', 'reservas', legado('orcamentos')],
        'roteiro'     => ['Roteiro', 'roteiro', legado('roteiro')],
        'clientes'    => ['Clientes', 'clientes', route('clientes.index')],
        'produtos'    => ['Produtos', 'produtos', legado('produtos')],
        'manutencoes' => ['Manutenção', 'manutencao', legado('manutencoes')],
        'caixa'       => ['Fluxo de caixa', 'caixa', legado('caixa')],
        'recibos'     => ['Recibos', 'caixa', legado('recibos')],
        'relatorios'  => ['Relatórios', 'relatorios', legado('relatorios')],
        'config'      => ['Configurações', 'config', legado('config')],
    ];
    $nomeLocadora = $empresa->nome_fantasia ?: $empresa->razao_social;
    $temLogo = ($empresa->logo_arquivo ?? '') !== '';
    $iniciais = mb_strtoupper(collect(preg_split('/\s+/', trim($usuario->nome)))->take(2)->map(fn ($p) => mb_substr($p, 0, 1))->join(''));
@endphp
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $titulo }} · {{ $nomeLocadora }}</title>
<link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
</head>
<body>
<input type="checkbox" id="menu-toggle" class="menu-toggle" aria-label="Abrir menu">
<aside class="sidebar">
    <div class="marca{{ $temLogo ? ' marca-com-logo' : '' }}">
        @if ($temLogo)
            <span class="marca-logo"><img src="{{ route('arquivos.logo', ['v' => substr(md5($empresa->logo_arquivo), 0, 8)]) }}" alt="{{ $nomeLocadora }}"></span>
        @else
            <span class="logo">LG</span>
        @endif
        <div class="marca-txt">
            <strong title="{{ $nomeLocadora }}">{{ $nomeLocadora }}</strong>
            <small>LocGestor</small>
        </div>
    </div>
    <nav class="menu">
        @foreach ($menu as $chave => [$rotulo, $ico, $url])
            <a href="{{ $url }}" @if ($chave === ($ativo ?? '')) class="ativo" aria-current="page" @endif>
                <x-icone :nome="$ico" /><span>{{ $rotulo }}</span>
            </a>
        @endforeach
    </nav>
    <div class="usuario">
        <span class="avatar" aria-hidden="true">{{ $iniciais }}</span>
        <div class="usuario-info">
            <strong>{{ $usuario->nome }}</strong>
            <small>{{ $usuario->login }} · {{ $usuario->perfil === 'admin' ? 'administrador' : $usuario->perfil }}</small>
        </div>
        <form method="post" action="{{ route('sair') }}">
            @csrf
            <button type="submit" class="btn-sair" title="Sair"><x-icone nome="sair" /><span>Sair</span></button>
        </form>
    </div>
</aside>
<label for="menu-toggle" class="menu-fundo" aria-hidden="true"></label>
<div class="principal">
    <header class="topo">
        <label for="menu-toggle" class="btn-menu" title="Menu"><x-icone nome="menu" /></label>
        <h1>{{ $titulo }}</h1>
    </header>
    <main class="conteudo">
        @foreach ((array) session('flash', []) as $f)
            <div class="flash flash-{{ $f['tipo'] }}" role="alert">{{ $f['msg'] }}</div>
        @endforeach
        @yield('conteudo')
    </main>
</div>
</body>
</html>
