@extends('layouts.plataforma', ['titulo' => 'Nova locadora', 'ativo' => 'tenants', 'subtitulo' => 'Cria a locadora com os dados padrão e o administrador. A senha temporária aparece uma única vez, depois de salvar.'])

@section('conteudo')
<div class="mb-6"><a class="btn-fantasma" href="{{ route('plataforma.locadoras') }}">← Voltar para a lista</a></div>

<form method="post" action="{{ route('plataforma.locadoras.criar') }}" class="grid grid-cols-1 gap-6 xl:grid-cols-2">
    @csrf

    <section class="cartao self-start">
        <div class="cartao-cabecalho"><h2 class="cartao-titulo">Dados da locadora</h2></div>
        <div class="grid gap-4 p-5">
            <label class="block"><span class="text-sm font-medium">Nome <span class="text-red-500">*</span></span>
                <input type="text" name="nome" id="nome" value="{{ old('nome') }}" required maxlength="150" class="campo mt-1.5">
            </label>
            <label class="block"><span class="text-sm font-medium">Identificador de acesso <span class="text-red-500">*</span></span>
                <input type="text" name="slug" id="slug" value="{{ old('slug') }}" required maxlength="40"
                       pattern="[a-z0-9][a-z0-9\-]{1,38}[a-z0-9]" autocapitalize="none" class="campo mt-1.5 font-mono">
                <span class="mt-1 block text-xs text-zinc-500">Só letras minúsculas, números e hífen (3 a 40). É o que a locadora digita no campo "Empresa" do login.</span>
            </label>
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block"><span class="text-sm font-medium">CNPJ</span>
                    <input type="text" name="cnpj" value="{{ old('cnpj') }}" maxlength="18" inputmode="numeric" class="campo mt-1.5">
                </label>
                <label class="block"><span class="text-sm font-medium">Telefone</span>
                    <input type="text" name="telefone" value="{{ old('telefone') }}" maxlength="20" class="campo mt-1.5">
                </label>
            </div>
            <label class="block"><span class="text-sm font-medium">E-mail de contato</span>
                <input type="email" name="email_contato" value="{{ old('email_contato') }}" maxlength="120" class="campo mt-1.5">
            </label>
        </div>
    </section>

    <section class="cartao self-start">
        <div class="cartao-cabecalho"><h2 class="cartao-titulo">Plano e mensalidade</h2></div>
        <div class="p-5">
            @include('plataforma.locadoras._assinatura', [
                'valores' => ['vencimento' => now()->addDays(30)->toDateString(), 'carencia_dias' => 5],
                'planos' => $planos,
            ])
            <p class="mt-4 text-xs text-zinc-500">O primeiro vencimento padrão é daqui a 30 dias (período de teste).</p>
        </div>
    </section>

    <section class="cartao xl:col-span-2">
        <div class="cartao-cabecalho">
            <h2 class="cartao-titulo">Administrador da locadora</h2>
            <span class="text-sm text-zinc-500">Recebe uma senha temporária e troca no primeiro acesso.</span>
        </div>
        <div class="grid gap-4 p-5 sm:grid-cols-2">
            <label class="block"><span class="text-sm font-medium">Nome <span class="text-red-500">*</span></span>
                <input type="text" name="admin_nome" value="{{ old('admin_nome', 'Administrador') }}" required maxlength="100" class="campo mt-1.5">
            </label>
            <label class="block"><span class="text-sm font-medium">Usuário (login) <span class="text-red-500">*</span></span>
                <input type="text" name="admin_login" value="{{ old('admin_login', 'admin') }}" required maxlength="40"
                       pattern="[a-z0-9._\-]{3,40}" autocapitalize="none" class="campo mt-1.5 font-mono">
            </label>
        </div>
    </section>

    <div class="flex flex-wrap gap-2 xl:col-span-2">
        <button class="btn-primario">Criar locadora</button>
        <a class="btn-secundario" href="{{ route('plataforma.locadoras') }}">Cancelar</a>
    </div>
</form>
@endsection

@section('scripts')
<script>
// Sugere o identificador a partir do nome, enquanto o campo não for editado à mão
(function () {
    var nome = document.getElementById('nome'), slug = document.getElementById('slug');
    var editado = slug.value !== '';
    slug.addEventListener('input', function () { editado = true; });
    nome.addEventListener('input', function () {
        if (editado) return;
        slug.value = nome.value.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
            .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 40).replace(/-+$/, '');
    });
})();
</script>
@endsection
