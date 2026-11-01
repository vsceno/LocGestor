@extends('layouts.app', ['titulo' => 'Configurações', 'ativo' => 'config'])

@section('subtitulo', 'Troque a sua senha de acesso. Ninguém mais vê nem altera a sua senha.')

@section('conteudo')
@include('configuracoes._abas', ['ativa' => 'config.senha'])

@if ($temporaria)
    <div role="alert" class="mb-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-300">
        Você entrou com uma senha temporária. Por segurança, defina uma senha nova para continuar.
    </div>
@endif

@error('senha')
    <div role="alert" class="mb-6 flex gap-3 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/50 dark:text-red-300">
        <x-icone nome="alerta" class="mt-0.5 size-5" /><div><p class="font-medium">A senha não foi trocada.</p><p class="mt-0.5">{{ $message }}</p></div>
    </div>
@enderror

<section class="cartao max-w-md">
    <div class="cartao-cabecalho"><h2 class="cartao-titulo">Trocar minha senha</h2></div>
    <form method="post" action="{{ route('config.senha.salvar') }}" autocomplete="off" class="grid gap-4 p-5">
        @csrf
        @method('PUT')
        <label class="block"><span class="text-sm font-medium">Senha atual <span class="text-red-500">*</span></span>
            <input type="password" name="senha_atual" required autocomplete="current-password" class="campo mt-1.5"></label>
        <label class="block"><span class="text-sm font-medium">Nova senha <span class="text-red-500">*</span></span>
            <input type="password" name="senha_nova" required minlength="8" autocomplete="new-password" class="campo mt-1.5">
            <span class="mt-1 block text-xs text-zinc-500">Mínimo de 8 caracteres.</span></label>
        <label class="block"><span class="text-sm font-medium">Confirme a nova senha <span class="text-red-500">*</span></span>
            <input type="password" name="senha_confirma" required minlength="8" autocomplete="new-password" class="campo mt-1.5"></label>
        <div><button type="submit" class="btn-primario">Trocar senha</button></div>
    </form>
</section>
@endsection
