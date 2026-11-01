@extends('layouts.publico', ['titulo' => 'Teste grátis'])

@section('formulario')
<h2 class="text-2xl font-semibold tracking-tight">Teste grátis por {{ numero($dias) }} dias</h2>
<p class="mt-1 text-sm text-zinc-500">Sem cartão. Você cria a conta da sua locadora e já começa a usar.</p>

@foreach ((array) session('flash', []) as $f)
    <div role="alert" class="mt-6 flex gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2.5 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">
        <x-icone nome="alerta" class="mt-0.5 size-4" /><span>{{ $f['msg'] }}</span>
    </div>
@endforeach

<form method="post" action="{{ route('cadastro.criar') }}" class="mt-6 grid gap-4">
    @csrf
    <label class="block"><span class="text-sm font-medium">Nome da locadora <span class="text-red-500">*</span></span>
        <input type="text" name="nome" value="{{ old('nome') }}" required maxlength="150" class="campo mt-1.5">
    </label>
    <label class="block"><span class="text-sm font-medium">Identificador da empresa <span class="text-red-500">*</span></span>
        <input type="text" name="slug" value="{{ old('slug') }}" required maxlength="40" pattern="[a-z0-9][a-z0-9\-]{1,38}[a-z0-9]"
               autocapitalize="none" placeholder="ex.: locadora-silva" class="campo mt-1.5 font-mono">
        <span class="mt-1 block text-xs text-zinc-500">Letras minúsculas, números e hífen. É o que você digita no campo "Empresa" do login.</span>
    </label>
    <div class="grid gap-4 sm:grid-cols-2">
        <label class="block"><span class="text-sm font-medium">E-mail <span class="text-red-500">*</span></span>
            <input type="email" name="email" value="{{ old('email') }}" required maxlength="120" autocomplete="email" class="campo mt-1.5">
        </label>
        <label class="block"><span class="text-sm font-medium">Telefone com DDD <span class="text-red-500">*</span></span>
            <input type="tel" name="telefone" value="{{ old('telefone') }}" required maxlength="20" autocomplete="tel" class="campo mt-1.5">
        </label>
    </div>
    <label class="block"><span class="text-sm font-medium">CNPJ (opcional)</span>
        <input type="text" name="cnpj" value="{{ old('cnpj') }}" maxlength="18" inputmode="numeric" class="campo mt-1.5">
    </label>
    <div class="grid gap-4 sm:grid-cols-2">
        <label class="block"><span class="text-sm font-medium">Seu nome <span class="text-red-500">*</span></span>
            <input type="text" name="admin_nome" value="{{ old('admin_nome') }}" required maxlength="100" class="campo mt-1.5">
        </label>
        <label class="block"><span class="text-sm font-medium">Usuário <span class="text-red-500">*</span></span>
            <input type="text" name="admin_login" value="{{ old('admin_login', 'admin') }}" required maxlength="40" autocapitalize="none" class="campo mt-1.5 font-mono">
        </label>
    </div>
    <div class="grid gap-4 sm:grid-cols-2">
        <label class="block"><span class="text-sm font-medium">Senha <span class="text-red-500">*</span></span>
            <input type="password" name="senha" required minlength="8" autocomplete="new-password" class="campo mt-1.5">
        </label>
        <label class="block"><span class="text-sm font-medium">Confirme a senha <span class="text-red-500">*</span></span>
            <input type="password" name="senha_confirma" required minlength="8" autocomplete="new-password" class="campo mt-1.5">
        </label>
    </div>

    {{-- Campo-isca contra robôs: fica fora da tela e de quem usa o site; quem preenche é recusado --}}
    <div aria-hidden="true" style="position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden">
        <label>Site <input type="text" name="site" tabindex="-1" autocomplete="off"></label>
    </div>

    <label class="flex items-start gap-2 text-sm">
        <input type="checkbox" name="aceite" value="1" required class="mt-0.5">
        <span>Li e aceito os <a href="{{ \App\Support\Configuracoes::ler()->termos_url }}" target="_blank" rel="noopener noreferrer" class="font-medium text-marca-700 underline">termos de uso</a> e o tratamento dos dados conforme a LGPD.</span>
    </label>
    <button class="btn-primario w-full py-2.5">Criar minha conta</button>
</form>

<p class="mt-6 text-center text-sm text-zinc-500">Já tem conta? <a href="{{ route('login') }}" class="font-medium text-marca-700 hover:underline">Entrar</a></p>
@endsection
