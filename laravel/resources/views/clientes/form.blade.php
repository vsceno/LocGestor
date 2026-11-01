@php
    $novo = $c === null;
    $v = fn (string $campo, $padrao = '') => old($campo, $c->$campo ?? $padrao);
    $tipo = $v('tipo', 'PF');
    $marcado = fn (string $campo, bool $padrao) => old('_enviado') ? (bool) old($campo) : (bool) ($c->$campo ?? $padrao);
    $titulo = $novo ? 'Novo cliente' : 'Editar cliente';
    $migalhas = [['Clientes', route('clientes.index')]];
    if (!$novo) {
        $migalhas[] = [$c->nome, route('clientes.show', $c->id)];
    }
    // Classe do campo, com borda vermelha quando tem erro
    $campo = fn (string $nome) => 'campo mt-1.5' . ($errors->has($nome) ? ' border-red-400 focus:border-red-500 focus:ring-red-500/15 dark:border-red-700' : '');
@endphp
@extends('layouts.app', ['titulo' => $titulo, 'ativo' => 'clientes', 'migalhas' => $migalhas])

@section('subtitulo', $novo ? 'Só o nome é obrigatório. Com o CEP, o endereço é preenchido sozinho.' : $c->nome)

@section('conteudo')
<form method="post" action="{{ $novo ? route('clientes.store') : route('clientes.update', $c->id) }}" autocomplete="off" novalidate data-form-cliente>
    @csrf
    @unless ($novo) @method('PUT') @endunless
    <input type="hidden" name="_enviado" value="1">

    @if ($errors->any())
        <div role="alert" class="mb-6 flex gap-3 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/50 dark:text-red-300">
            <x-icone nome="alerta" class="mt-0.5 size-5" />
            <div><p class="font-medium">Confira {{ $errors->count() === 1 ? 'o campo destacado' : 'os ' . $errors->count() . ' campos destacados' }}.</p>
                <p class="mt-0.5 text-red-700/80 dark:text-red-300/80">{{ $errors->first() }}</p></div>
        </div>
    @endif

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            {{-- Identificação --}}
            <section class="cartao">
                <div class="cartao-cabecalho"><h2 class="cartao-titulo">Identificação</h2></div>
                <div class="space-y-5 p-5">
                    <div class="inline-flex gap-1 rounded-xl bg-zinc-100 p-1 dark:bg-zinc-800" role="radiogroup" aria-label="Tipo de cliente">
                        @foreach (['PF' => 'Pessoa física', 'PJ' => 'Pessoa jurídica'] as $valor => $rotulo)
                            <label class="cursor-pointer rounded-lg px-4 py-1.5 text-sm font-medium text-zinc-500 transition has-checked:bg-white has-checked:text-zinc-900 has-checked:shadow-xs
                                          dark:has-checked:bg-zinc-900 dark:has-checked:text-zinc-100">
                                <input type="radio" name="tipo" value="{{ $valor }}" class="sr-only" @checked($tipo === $valor) data-tipo>{{ $rotulo }}
                            </label>
                        @endforeach
                    </div>
                    <label class="block">
                        <span class="text-sm font-medium" data-rotulo-nome>{{ $tipo === 'PJ' ? 'Razão social' : 'Nome completo' }} <span class="text-red-500">*</span></span>
                        <input type="text" name="nome" value="{{ $v('nome') }}" required maxlength="150" class="{{ $campo('nome') }}" @if ($novo) autofocus @endif>
                        @error('nome')<span class="mt-1 block text-xs text-red-600 dark:text-red-400">{{ $message }}</span>@enderror
                    </label>
                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <label class="block">
                            <span class="text-sm font-medium" data-rotulo-doc>{{ $tipo === 'PJ' ? 'CNPJ' : 'CPF' }}</span>
                            <input type="text" name="cpf_cnpj" value="{{ doc_formatar($v('cpf_cnpj')) }}" inputmode="numeric" maxlength="18" data-mascara="doc" class="{{ $campo('cpf_cnpj') }}">
                            @error('cpf_cnpj')<span class="mt-1 block text-xs text-red-600 dark:text-red-400">{{ $message }}</span>@enderror
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium" data-rotulo-ie>{{ $tipo === 'PJ' ? 'Inscrição estadual' : 'RG' }}</span>
                            <input type="text" name="rg_ie" value="{{ $v('rg_ie') }}" maxlength="20" class="{{ $campo('rg_ie') }}">
                            @error('rg_ie')<span class="mt-1 block text-xs text-red-600 dark:text-red-400">{{ $message }}</span>@enderror
                        </label>
                    </div>
                </div>
            </section>

            {{-- Contato --}}
            <section class="cartao">
                <div class="cartao-cabecalho"><h2 class="cartao-titulo">Contato</h2></div>
                <div class="grid grid-cols-1 gap-5 p-5 sm:grid-cols-3">
                    <label class="block">
                        <span class="text-sm font-medium">Celular / WhatsApp</span>
                        <input type="tel" name="celular" value="{{ $v('celular') }}" maxlength="20" data-mascara="telefone" placeholder="(85) 99999-0000" class="{{ $campo('celular') }}">
                        @error('celular')<span class="mt-1 block text-xs text-red-600">{{ $message }}</span>@enderror
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium">Telefone</span>
                        <input type="tel" name="telefone" value="{{ $v('telefone') }}" maxlength="20" data-mascara="telefone" class="{{ $campo('telefone') }}">
                        @error('telefone')<span class="mt-1 block text-xs text-red-600">{{ $message }}</span>@enderror
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium">E-mail</span>
                        <input type="email" name="email" value="{{ $v('email') }}" maxlength="120" class="{{ $campo('email') }}">
                        @error('email')<span class="mt-1 block text-xs text-red-600 dark:text-red-400">{{ $message }}</span>@enderror
                    </label>
                </div>
            </section>

            {{-- Endereço --}}
            <section class="cartao">
                <div class="cartao-cabecalho">
                    <h2 class="cartao-titulo">Endereço</h2>
                    <span class="text-xs text-zinc-500" data-cep-status>Digite o CEP para preencher o resto</span>
                </div>
                <div class="grid grid-cols-6 gap-5 p-5">
                    <label class="col-span-6 block sm:col-span-2">
                        <span class="text-sm font-medium">CEP</span>
                        <input type="text" name="cep" value="{{ $v('cep') }}" inputmode="numeric" maxlength="9" data-mascara="cep" data-viacep placeholder="00000-000" class="{{ $campo('cep') }}">
                        @error('cep')<span class="mt-1 block text-xs text-red-600 dark:text-red-400">{{ $message }}</span>@enderror
                    </label>
                    <label class="col-span-6 block sm:col-span-4">
                        <span class="text-sm font-medium">Endereço</span>
                        <input type="text" name="endereco" value="{{ $v('endereco') }}" maxlength="150" class="{{ $campo('endereco') }}">
                    </label>
                    <label class="col-span-2 block">
                        <span class="text-sm font-medium">Número</span>
                        <input type="text" name="numero" value="{{ $v('numero') }}" maxlength="10" class="{{ $campo('numero') }}">
                    </label>
                    <label class="col-span-4 block">
                        <span class="text-sm font-medium">Bairro</span>
                        <input type="text" name="bairro" value="{{ $v('bairro') }}" maxlength="80" class="{{ $campo('bairro') }}">
                    </label>
                    <label class="col-span-4 block">
                        <span class="text-sm font-medium">Cidade</span>
                        <input type="text" name="cidade" value="{{ $v('cidade') }}" maxlength="80" class="{{ $campo('cidade') }}">
                    </label>
                    <label class="col-span-2 block">
                        <span class="text-sm font-medium">UF</span>
                        <select name="uf" class="{{ $campo('uf') }}">
                            <option value=""></option>
                            @foreach (ufs() as $uf)<option value="{{ $uf }}" @selected($v('uf', $empresa->uf ?? 'CE') === $uf)>{{ $uf }}</option>@endforeach
                        </select>
                        @error('uf')<span class="mt-1 block text-xs text-red-600 dark:text-red-400">{{ $message }}</span>@enderror
                    </label>
                </div>
            </section>
        </div>

        {{-- Situação e observações --}}
        <div class="space-y-6">
            <section class="cartao">
                <div class="cartao-cabecalho"><h2 class="cartao-titulo">Situação</h2></div>
                <div class="divide-y divide-zinc-100 px-5 dark:divide-zinc-800">
                    @foreach ([['ativo', 'Ativo', 'Aparece nas listas e pode alugar.', true],
                               ['bloqueado', 'Bloqueado', 'Não pode fazer novas locações (ex.: inadimplência).', false]] as [$nome, $rotulo, $ajuda, $padrao])
                        <label class="flex cursor-pointer items-start justify-between gap-4 py-4">
                            <span><span class="block text-sm font-medium">{{ $rotulo }}</span><span class="block text-xs text-zinc-500">{{ $ajuda }}</span></span>
                            {{-- Chave liga/desliga: o checkbox continua lá, escondido, para o formulário e o teclado --}}
                            <input type="checkbox" name="{{ $nome }}" value="1" class="peer sr-only" @checked($marcado($nome, $padrao))>
                            <span class="relative mt-0.5 h-6 w-11 shrink-0 rounded-full bg-zinc-200 transition peer-checked:bg-marca-600 peer-focus-visible:ring-4 peer-focus-visible:ring-marca-500/30 dark:bg-zinc-700
                                         after:absolute after:top-0.5 after:left-0.5 after:size-5 after:rounded-full after:bg-white after:shadow after:transition peer-checked:after:translate-x-5"></span>
                        </label>
                    @endforeach
                </div>
            </section>
            <section class="cartao">
                <div class="cartao-cabecalho"><h2 class="cartao-titulo">Observações</h2></div>
                <div class="p-5">
                    <textarea name="obs" rows="6" maxlength="2000" placeholder="Preferências, restrições, combinados com o cliente…" class="{{ $campo('obs') }} mt-0 resize-y">{{ $v('obs') }}</textarea>
                    @error('obs')<span class="mt-1 block text-xs text-red-600 dark:text-red-400">{{ $message }}</span>@enderror
                </div>
            </section>
        </div>
    </div>

    {{-- Barra de salvar, sempre à vista --}}
    <div class="sticky bottom-0 z-10 -mx-4 mt-6 border-t border-zinc-200 bg-white/85 px-4 py-3 backdrop-blur-xl sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8 dark:border-zinc-800 dark:bg-zinc-950/85">
        <div class="flex items-center justify-end gap-2">
            <a class="btn-secundario" href="{{ $novo ? route('clientes.index') : route('clientes.show', $c->id) }}">Cancelar</a>
            <button type="submit" class="btn-primario">{{ $novo ? 'Cadastrar cliente' : 'Salvar alterações' }}</button>
        </div>
    </div>
</form>
@endsection
