@extends('layouts.plataforma', ['titulo' => 'Minha senha', 'ativo' => 'conta', 'subtitulo' => 'Depois de trocar, a senha antiga deixa de valer.'])

@section('conteudo')
<section class="cartao max-w-xl">
    <div class="cartao-cabecalho">
        <h2 class="cartao-titulo">Trocar a senha</h2>
        <span class="text-sm text-zinc-500">Usuário: <code class="font-mono">{{ session('plataforma.usuario.login', '') }}</code></span>
    </div>
    <form method="post" action="{{ route('plataforma.conta.senha') }}" class="grid gap-4 p-5">
        @csrf
        @method('PUT')
        <label class="block"><span class="text-sm font-medium">Senha atual <span class="text-red-500">*</span></span>
            <input type="password" name="senha_atual" required autocomplete="current-password" class="campo mt-1.5">
        </label>
        <label class="block"><span class="text-sm font-medium">Nova senha <span class="text-red-500">*</span></span>
            <input type="password" name="senha_nova" required minlength="8" autocomplete="new-password" class="campo mt-1.5">
            <span class="mt-1 block text-xs text-zinc-500">Pelo menos 8 caracteres, diferente da atual.</span>
        </label>
        <label class="block"><span class="text-sm font-medium">Repita a nova senha <span class="text-red-500">*</span></span>
            <input type="password" name="senha_confirma" required minlength="8" autocomplete="new-password" class="campo mt-1.5">
        </label>
        <div><button class="btn-primario">Trocar senha</button></div>
    </form>
</section>
@endsection
