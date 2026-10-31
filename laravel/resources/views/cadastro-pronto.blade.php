@extends('layouts.publico', ['titulo' => 'Cadastro concluído'])

@section('formulario')
<h2 class="text-2xl font-semibold tracking-tight">Pronto!</h2>
<p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
    A <strong class="text-zinc-900 dark:text-zinc-100">{{ $ok['nome'] }}</strong> já pode usar o sistema.
</p>

<dl class="mt-6 grid grid-cols-[max-content_1fr] gap-x-6 gap-y-3 rounded-xl bg-zinc-50 p-4 text-sm dark:bg-zinc-900">
    <dt class="text-zinc-500">Empresa</dt><dd><code class="font-mono">{{ $ok['slug'] }}</code></dd>
    <dt class="text-zinc-500">Usuário</dt><dd><code class="font-mono">{{ $ok['login'] }}</code></dd>
    <dt class="text-zinc-500">Teste grátis até</dt><dd>{{ data_br($ok['vencimento']) }}</dd>
</dl>

<p class="mt-4 text-sm text-zinc-500">
    Guarde o identificador da empresa: ele é pedido no login, junto com o usuário e a senha que você escolheu.
</p>
<a href="{{ route('login') }}" class="btn-primario mt-6 w-full py-2.5">Entrar no sistema</a>
@endsection
