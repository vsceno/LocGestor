@extends('layouts.app', ['titulo' => 'Nova manutenção', 'ativo' => 'manutencoes', 'migalhas' => [['Manutenção', route('manutencoes.index')]]])

@section('subtitulo', 'Enquanto a manutenção estiver aberta, as peças não podem ser alugadas nem reservadas.')

@section('conteudo')
@php $hoje = now()->toDateString(); @endphp
<form method="post" action="{{ route('manutencoes.store') }}" autocomplete="off">
    @csrf

    @error('manutencao')
        <div role="alert" class="mb-6 flex gap-3 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/50 dark:text-red-300">
            <x-icone nome="alerta" class="mt-0.5 size-5" /><div><p class="font-medium">A manutenção não foi aberta.</p><p class="mt-0.5">{{ $message }}</p></div>
        </div>
    @enderror

    <section class="cartao">
        <div class="cartao-cabecalho"><h2 class="cartao-titulo">O que vai para a manutenção</h2></div>
        <div class="grid grid-cols-1 gap-5 p-5 sm:grid-cols-2">
            <label class="block sm:col-span-2">
                <span class="text-sm font-medium">Unidade (patrimônio)</span>
                <select name="unidade_id" class="campo mt-1.5">
                    <option value="">— nenhuma: informar produto e quantidade —</option>
                    @foreach ($unidades as $u)
                        <option value="{{ $u->id }}" @selected(old('unidade_id', $unidade) === $u->id)>{{ $u->nome }}</option>
                    @endforeach
                </select>
                <span class="mt-1 block text-xs text-zinc-500">Escolhida a unidade, o produto e a quantidade vêm dela. Só aparecem unidades livres agora.</span>
            </label>
            <label class="block">
                <span class="text-sm font-medium">Produto</span>
                <select name="produto_id" class="campo mt-1.5">
                    <option value="">— escolha —</option>
                    @foreach ($produtos as $p)
                        <option value="{{ $p->id }}" @selected(old('produto_id', $produto) === $p->id)>{{ $p->nome }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="text-sm font-medium">Quantidade</span>
                <input type="number" name="quantidade" value="{{ old('quantidade', '1') }}" min="1" step="1" class="campo mt-1.5">
            </label>
        </div>
    </section>

    <section class="cartao mt-6">
        <div class="cartao-cabecalho"><h2 class="cartao-titulo">Serviço</h2></div>
        <div class="grid grid-cols-1 gap-5 p-5 sm:grid-cols-2">
            <label class="block">
                <span class="text-sm font-medium">Tipo <span class="text-red-500">*</span></span>
                <select name="tipo" required class="campo mt-1.5">
                    @foreach ($tipos as $valor => $rotulo)
                        <option value="{{ $valor }}" @selected(old('tipo', 'corretiva') === $valor)>{{ $rotulo }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="text-sm font-medium">Início <span class="text-red-500">*</span></span>
                <input type="date" name="data_inicio" value="{{ old('data_inicio', $hoje) }}" max="{{ $hoje }}" required class="campo mt-1.5">
            </label>
            <label class="block sm:col-span-2">
                <span class="text-sm font-medium">Fornecedor / oficina</span>
                <input type="text" name="fornecedor" value="{{ old('fornecedor') }}" maxlength="100" class="campo mt-1.5">
            </label>
            <label class="block sm:col-span-2">
                <span class="text-sm font-medium">Problema ou serviço a fazer <span class="text-red-500">*</span></span>
                <textarea name="descricao" rows="3" required maxlength="500" class="campo mt-1.5 resize-y">{{ old('descricao') }}</textarea>
            </label>
        </div>
    </section>

    <div class="mt-6 flex items-center justify-end gap-2">
        <a class="btn-secundario" href="{{ route('manutencoes.index') }}">Cancelar</a>
        <button type="submit" class="btn-primario"><x-icone nome="ok" class="size-4" /> Abrir manutenção</button>
    </div>
</form>
@endsection
