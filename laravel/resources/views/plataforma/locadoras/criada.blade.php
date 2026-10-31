@extends('layouts.plataforma', ['titulo' => 'Locadora criada', 'ativo' => 'tenants'])

@section('conteudo')
<section class="cartao max-w-2xl">
    <div class="cartao-cabecalho"><h2 class="cartao-titulo">Dados de acesso do administrador</h2></div>
    <div class="grid gap-4 p-5 text-sm">
        <p class="text-zinc-600 dark:text-zinc-400">
            A locadora <strong class="text-zinc-900 dark:text-zinc-100">{{ $p['nome'] }}</strong> foi criada.
            Anote ou envie agora: a senha temporária não será mostrada de novo.
        </p>
        <dl class="grid grid-cols-[max-content_1fr] gap-x-6 gap-y-3 rounded-xl bg-zinc-50 p-4 dark:bg-zinc-950">
            <dt class="text-zinc-500">Endereço</dt><dd class="break-all">{{ route('login') }}</dd>
            <dt class="text-zinc-500">Empresa</dt><dd><code class="font-mono">{{ $p['slug'] }}</code></dd>
            <dt class="text-zinc-500">Usuário</dt><dd><code class="font-mono">{{ $p['login'] }}</code></dd>
            <dt class="text-zinc-500">Senha temporária</dt><dd><code id="senha-temp" class="font-mono text-base font-semibold">{{ $p['senha'] }}</code></dd>
        </dl>
        <p class="text-zinc-500">No primeiro acesso, o sistema obriga o administrador a definir uma senha nova.</p>
        <div><a class="btn-primario" href="{{ route('plataforma.locadoras') }}">Voltar para a lista</a></div>
    </div>
</section>
@endsection
