<!DOCTYPE html>
<html lang="pt-BR" class="h-full">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/png" href="{{ asset('assets/brand/emblema-locgestor-64.png') }}">
<title>{{ $titulo ?? 'LocGestor' }} · LocGestor</title>
<script>try { var t = localStorage.getItem('lg-tema'); if (t === 'escuro' || (!t && matchMedia('(prefers-color-scheme: dark)').matches)) document.documentElement.classList.add('dark'); } catch (e) {}</script>
@vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full">
<div class="grid min-h-full lg:grid-cols-2">
    {{-- Lado da marca (igual à tela de entrada) --}}
    <div class="relative hidden overflow-hidden bg-zinc-950 lg:block">
        <div class="absolute -top-32 -left-24 size-[480px] rounded-full bg-marca-600/40 blur-3xl"></div>
        <div class="absolute -right-24 -bottom-40 size-[520px] rounded-full bg-indigo-600/30 blur-3xl"></div>
        <div class="relative flex h-full flex-col justify-between p-12 text-white">
            <div class="flex items-center gap-3">
                <img src="{{ asset('assets/brand/emblema-locgestor-256.png') }}" alt="" class="size-10 object-contain">
                <span class="text-lg font-semibold">LocGestor</span>
            </div>
            <div>
                <h1 class="max-w-md text-4xl leading-tight font-semibold tracking-tight">Experimente sem compromisso.</h1>
                <p class="mt-4 max-w-md text-zinc-400">Crie a conta da sua locadora, cadastre seus produtos e faça a primeira locação ainda hoje. Sem cartão.</p>
            </div>
            <p class="text-sm text-zinc-500">Cada locadora vê só os próprios dados.</p>
        </div>
    </div>

    {{-- Formulário --}}
    <div class="flex items-center justify-center p-6 sm:p-12">
        <div class="w-full max-w-md">
            <div class="mb-8 flex items-center gap-3 lg:hidden">
                <img src="{{ asset('assets/brand/emblema-locgestor-256.png') }}" alt="LocGestor" class="size-10 object-contain">
                <span class="text-lg font-semibold">LocGestor</span>
            </div>
            @yield('formulario')
        </div>
    </div>
</div>
@yield('scripts')
</body>
</html>
