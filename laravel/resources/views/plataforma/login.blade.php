<!DOCTYPE html>
<html lang="pt-BR" class="h-full">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Painel da plataforma · LocGestor</title>
<script>try { var t = localStorage.getItem('lg-tema'); if (t === 'escuro' || (!t && matchMedia('(prefers-color-scheme: dark)').matches)) document.documentElement.classList.add('dark'); } catch (e) {}</script>
@vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full">
<div class="flex min-h-full items-center justify-center p-6">
    <div class="w-full max-w-sm">
        <div class="mb-8 flex items-center gap-3">
            <span class="grid size-10 place-items-center rounded-xl bg-gradient-to-br from-marca-400 to-marca-700 font-bold text-white">LG</span>
            <div><p class="text-lg font-semibold">LocGestor</p><p class="text-sm text-zinc-500">Painel da plataforma</p></div>
        </div>
        <h1 class="text-2xl font-semibold tracking-tight">Entrar</h1>
        <p class="mt-1 text-sm text-zinc-500">Acesso restrito ao dono da plataforma e à equipe de suporte.</p>

        @foreach ((array) session('flash', []) as $f)
            <div role="alert" class="mt-6 flex gap-2 rounded-lg border px-3 py-2.5 text-sm
                {{ $f['tipo'] === 'erro' ? 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300' : 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-300' }}">
                <x-icone nome="alerta" class="mt-0.5 size-4" /><span>{{ $f['msg'] }}</span>
            </div>
        @endforeach

        <form method="post" action="{{ route('plataforma.entrar') }}" class="mt-6 space-y-4" autocomplete="on">
            @csrf
            <label class="block">
                <span class="text-sm font-medium">Usuário</span>
                <input type="text" name="login" value="{{ old('login') }}" required maxlength="40" autocomplete="username" autocapitalize="none"
                       class="campo mt-1.5" autofocus>
            </label>
            <label class="block">
                <span class="text-sm font-medium">Senha</span>
                <input type="password" name="senha" required autocomplete="current-password" class="campo mt-1.5">
            </label>
            <button class="btn-primario w-full py-2.5">Entrar</button>
        </form>
    </div>
</div>
</body>
</html>
