<!DOCTYPE html>
<html lang="pt-BR" class="h-full">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Entrar · LocGestor</title>
<script>try { var t = localStorage.getItem('lg-tema'); if (t === 'escuro' || (!t && matchMedia('(prefers-color-scheme: dark)').matches)) document.documentElement.classList.add('dark'); } catch (e) {}</script>
@vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full">
<div class="grid min-h-full lg:grid-cols-2">
    {{-- Lado da marca --}}
    <div class="relative hidden overflow-hidden bg-zinc-950 lg:block">
        <div class="absolute -top-32 -left-24 size-[480px] rounded-full bg-marca-600/40 blur-3xl"></div>
        <div class="absolute -right-24 -bottom-40 size-[520px] rounded-full bg-indigo-600/30 blur-3xl"></div>
        <div class="relative flex h-full flex-col justify-between p-12 text-white">
            <div class="flex items-center gap-3">
                <span class="grid size-10 place-items-center rounded-xl bg-gradient-to-br from-marca-400 to-marca-700 font-bold shadow-lg shadow-marca-600/40">LG</span>
                <span class="text-lg font-semibold">LocGestor</span>
            </div>
            <div>
                <h1 class="max-w-md text-4xl leading-tight font-semibold tracking-tight">Sua locadora organizada, do contrato à devolução.</h1>
                <p class="mt-4 max-w-md text-zinc-400">Locações, estoque em tempo real, cobrança com PIX e WhatsApp, contrato e fatura prontos para imprimir.</p>
            </div>
            <p class="text-sm text-zinc-500">Cada locadora vê só os próprios dados.</p>
        </div>
    </div>

    {{-- Formulário --}}
    <div class="flex items-center justify-center p-6 sm:p-12">
        <div class="w-full max-w-sm">
            <div class="mb-8 flex items-center gap-3 lg:hidden">
                <span class="grid size-10 place-items-center rounded-xl bg-gradient-to-br from-marca-400 to-marca-700 font-bold text-white">LG</span>
                <span class="text-lg font-semibold">LocGestor</span>
            </div>
            <h2 class="text-2xl font-semibold tracking-tight">Entrar</h2>
            <p class="mt-1 text-sm text-zinc-500">Use o identificador da sua empresa, seu usuário e senha.</p>

            @foreach ((array) session('flash', []) as $f)
                <div role="alert" class="mt-6 flex gap-2 rounded-lg border px-3 py-2.5 text-sm
                    {{ $f['tipo'] === 'erro' ? 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300' : 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-300' }}">
                    <x-icone nome="alerta" class="mt-0.5 size-4" /><span>{{ $f['msg'] }}</span>
                </div>
            @endforeach

            <form method="post" action="{{ route('login.entrar') }}" class="mt-6 space-y-4" autocomplete="on">
                @csrf
                <label class="block">
                    <span class="text-sm font-medium">Empresa</span>
                    <input type="text" name="empresa" value="{{ old('empresa', $empresaPadrao) }}" required maxlength="40" autocomplete="organization"
                           placeholder="ex.: alfa-locacoes" class="campo mt-1.5" @if (old('empresa', $empresaPadrao) === '') autofocus @endif>
                </label>
                <label class="block">
                    <span class="text-sm font-medium">Usuário</span>
                    <input type="text" name="login" required maxlength="60" autocomplete="username" class="campo mt-1.5" @if (old('empresa', $empresaPadrao) !== '') autofocus @endif>
                </label>
                <label class="block">
                    <span class="text-sm font-medium">Senha</span>
                    <input type="password" name="senha" required autocomplete="current-password" class="campo mt-1.5">
                </label>
                <button class="btn-primario w-full py-2.5">Entrar</button>
            </form>
        </div>
    </div>
</div>
</body>
</html>
