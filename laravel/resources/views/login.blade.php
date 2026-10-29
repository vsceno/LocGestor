<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Entrar · LocGestor</title>
<link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
</head>
<body>
<main class="pagina-login">
    <div class="login-caixa">
        <div class="login-marca"><span class="logo">LG</span><strong>LocGestor</strong></div>
        <p class="login-sub">Locação de equipamentos</p>
        @foreach ((array) session('flash', []) as $f)
            <div class="flash flash-{{ $f['tipo'] }}" role="alert">{{ $f['msg'] }}</div>
        @endforeach
        <form method="post" action="{{ route('login.entrar') }}" class="form" autocomplete="on">
            @csrf
            <label>Empresa<input type="text" name="empresa" value="{{ old('empresa', $empresaPadrao) }}" required maxlength="40"
                                 autocomplete="organization" @if (old('empresa', $empresaPadrao) === '') autofocus @endif></label>
            <label>Usuário<input type="text" name="login" required maxlength="60" autocomplete="username"
                                 @if (old('empresa', $empresaPadrao) !== '') autofocus @endif></label>
            <label>Senha<input type="password" name="senha" required autocomplete="current-password"></label>
            <button class="btn btn-bloco">Entrar</button>
        </form>
    </div>
</main>
</body>
</html>
