@php
    $campo = fn (string $nome) => 'campo mt-1.5';
    // Após erro, o que foi digitado; senão, o que está gravado
    $v = fn (string $c, $padrao = '') => old($c, $e->$c ?? $padrao);
@endphp
@extends('layouts.app', ['titulo' => 'Configurações', 'ativo' => 'config'])

@section('subtitulo', 'Dados da locadora e os textos dos documentos impressos.')

@section('conteudo')
@include('configuracoes._abas', ['ativa' => 'config.index'])

@error('empresa')
    <div role="alert" class="mb-6 flex gap-3 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/50 dark:text-red-300">
        <x-icone nome="alerta" class="mt-0.5 size-5" /><div><p class="font-medium">Confira os dados.</p><p class="mt-0.5">{{ $message }}</p></div>
    </div>
@enderror

<form method="post" action="{{ route('config.empresa.salvar') }}" enctype="multipart/form-data" class="space-y-6">
    @csrf
    @method('PUT')

    <section class="cartao">
        <div class="cartao-cabecalho"><div><h2 class="cartao-titulo">Dados da locadora</h2>
            <p class="text-xs text-zinc-500">Aparecem no topo do menu, no contrato e na fatura.</p></div></div>
        <div class="grid grid-cols-1 gap-5 p-5 sm:grid-cols-2">
            <label class="block sm:col-span-2"><span class="text-sm font-medium">Razão social <span class="text-red-500">*</span></span>
                <input type="text" name="razao_social" value="{{ $v('razao_social') }}" required maxlength="150" class="{{ $campo('razao_social') }}"></label>
            <label class="block"><span class="text-sm font-medium">Nome fantasia</span>
                <input type="text" name="nome_fantasia" value="{{ $v('nome_fantasia') }}" maxlength="150" class="{{ $campo('nome_fantasia') }}"></label>
            <label class="block"><span class="text-sm font-medium">CNPJ</span>
                <input type="text" name="cnpj" value="{{ doc_formatar($v('cnpj')) }}" data-mascara="doc" inputmode="numeric" maxlength="18" class="{{ $campo('cnpj') }}"></label>
            <label class="block"><span class="text-sm font-medium">Inscrição estadual</span>
                <input type="text" name="ie" value="{{ $v('ie') }}" maxlength="20" class="{{ $campo('ie') }}"></label>
            <label class="block"><span class="text-sm font-medium">Telefone</span>
                <input type="tel" name="telefone" value="{{ $v('telefone') }}" data-mascara="telefone" maxlength="20" class="{{ $campo('telefone') }}"></label>
            <label class="block"><span class="text-sm font-medium">E-mail</span>
                <input type="email" name="email" value="{{ $v('email') }}" maxlength="120" class="{{ $campo('email') }}"></label>
            <label class="block sm:col-span-2"><span class="text-sm font-medium">Endereço</span>
                <input type="text" name="endereco" value="{{ $v('endereco') }}" maxlength="200" class="{{ $campo('endereco') }}"></label>
            <label class="block"><span class="text-sm font-medium">Cidade</span>
                <input type="text" name="cidade" value="{{ $v('cidade') }}" maxlength="80" class="{{ $campo('cidade') }}"></label>
            <div class="grid grid-cols-2 gap-5">
                <label class="block"><span class="text-sm font-medium">UF</span>
                    <select name="uf" class="{{ $campo('uf') }}">
                        <option value=""></option>
                        @foreach (ufs() as $sigla)<option value="{{ $sigla }}" @selected($v('uf') === $sigla)>{{ $sigla }}</option>@endforeach
                    </select></label>
                <label class="block"><span class="text-sm font-medium">CEP</span>
                    <input type="text" name="cep" value="{{ $v('cep') }}" data-mascara="cep" inputmode="numeric" maxlength="9" class="{{ $campo('cep') }}"></label>
            </div>
        </div>
    </section>

    <section class="cartao">
        <div class="cartao-cabecalho"><div><h2 class="cartao-titulo">Logomarca</h2>
            <p class="text-xs text-zinc-500">Aparece no topo do contrato, da fatura, do recibo e dos outros documentos impressos.
                Envie PNG, JPG ou WebP de até 5 MB; a imagem é reduzida para no máximo 800 pixels.</p></div></div>
        <div class="space-y-4 p-5">
            @if ($e->logo_arquivo !== '')
                <img src="{{ route('arquivos.logo', ['v' => substr(md5($e->logo_arquivo), 0, 8)]) }}" alt="Logomarca atual"
                     class="max-h-24 rounded-lg bg-white p-2">
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="logo_remover" value="1" @checked(old('logo_remover'))>
                    Remover a logomarca</label>
            @endif
            <label class="block"><span class="text-sm font-medium">{{ $e->logo_arquivo !== '' ? 'Trocar a logomarca' : 'Enviar a logomarca' }}</span>
                <input type="file" name="logo" accept="image/png,image/jpeg,image/webp" class="mt-1.5 block text-sm"></label>
        </div>
    </section>

    <section class="cartao">
        <div class="cartao-cabecalho"><h2 class="cartao-titulo">Contrato de locação</h2></div>
        <div class="p-5"><label class="block"><span class="text-sm font-medium">Cláusulas <span class="text-red-500">*</span></span>
            <textarea name="termos_contrato" rows="14" required class="{{ $campo('termos_contrato') }} resize-y">{{ $v('termos_contrato') }}</textarea></label></div>
    </section>

    <section class="cartao">
        <div class="cartao-cabecalho"><h2 class="cartao-titulo">Fatura do contrato de locação</h2></div>
        <div class="p-5"><label class="block"><span class="text-sm font-medium">Observação fiscal impressa na fatura</span>
            <textarea name="obs_fatura" rows="3" class="{{ $campo('obs_fatura') }} resize-y">{{ $v('obs_fatura') }}</textarea>
            <span class="mt-1 block text-xs text-zinc-500">Confirme o texto com o seu contador. Esta versão não emite NF-e.</span></label></div>
    </section>

    <section class="cartao">
        <div class="cartao-cabecalho"><div><h2 class="cartao-titulo">PIX para cobrança</h2>
            <p class="text-xs text-zinc-500">Com a chave preenchida, a locação e a fatura mostram um QR Code e o "PIX copia e cola" com o
                valor do saldo, e a cobrança pelo WhatsApp já leva o código. O pagamento cai direto na conta da locadora;
                registre o recebimento na locação quando o dinheiro entrar.</p></div></div>
        <div class="grid grid-cols-1 gap-5 p-5 sm:grid-cols-3">
            <label class="block"><span class="text-sm font-medium">Chave PIX</span>
                <input type="text" name="pix_chave" value="{{ $v('pix_chave') }}" maxlength="77" autocomplete="off"
                       placeholder="CPF, CNPJ, e-mail, +55 celular ou chave aleatória" class="{{ $campo('pix_chave') }}">
                <span class="mt-1 block text-xs text-zinc-500">Deixe vazia para não oferecer PIX.</span></label>
            <label class="block"><span class="text-sm font-medium">Nome do recebedor</span>
                <input type="text" name="pix_nome" value="{{ $v('pix_nome') }}" maxlength="25" class="{{ $campo('pix_nome') }}">
                <span class="mt-1 block text-xs text-zinc-500">Até 25 letras, sem acentos. Vazio: usa o nome da empresa.</span></label>
            <label class="block"><span class="text-sm font-medium">Cidade do recebedor</span>
                <input type="text" name="pix_cidade" value="{{ $v('pix_cidade') }}" maxlength="15" class="{{ $campo('pix_cidade') }}">
                <span class="mt-1 block text-xs text-zinc-500">Até 15 letras. Vazio: usa a cidade da empresa.</span></label>
        </div>
    </section>

    <div class="flex justify-end">
        <button type="submit" class="btn-primario"><x-icone nome="ok" class="size-4" /> Salvar</button>
    </div>
</form>
@endsection
