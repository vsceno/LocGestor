@php $hoje = now()->toDateString(); @endphp
@extends('layouts.app', ['titulo' => 'Manutenção nº ' . $m->numero, 'ativo' => 'manutencoes', 'migalhas' => [['Manutenção', route('manutencoes.index')]]])

@section('subtitulo')
    <span class="inline-flex flex-wrap items-center gap-2">
        @if ($m->data_fim === null)<span class="selo-amarelo">em manutenção</span>@else<span class="selo-verde">concluída</span>@endif
        <span class="font-medium text-zinc-700 dark:text-zinc-300">{{ $m->produto }}</span>
    </span>
@endsection

@section('conteudo')
<div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
    <div class="space-y-6 xl:col-span-2">
        <section class="cartao">
            <div class="cartao-cabecalho"><h2 class="cartao-titulo">Dados da manutenção</h2></div>
            <dl class="grid grid-cols-1 gap-x-6 gap-y-4 p-5 text-sm sm:grid-cols-2">
                <div><dt class="text-xs text-zinc-500">Produto</dt>
                    <dd class="mt-0.5 font-medium text-zinc-900 dark:text-zinc-100"><span class="font-mono text-xs text-zinc-500">{{ $m->codigo }}</span> {{ $m->produto }}</dd></div>
                @if ($m->patrimonio)
                    <div><dt class="text-xs text-zinc-500">Unidade</dt>
                        <dd class="mt-0.5"><a class="font-mono font-medium text-marca-700 hover:underline dark:text-marca-400" href="{{ route('unidades.show', $m->unidade_id) }}">{{ $m->patrimonio }}</a>
                            @if ($m->serie !== '')<span class="text-zinc-500">· série {{ $m->serie }}</span>@endif</dd></div>
                @endif
                <div><dt class="text-xs text-zinc-500">Quantidade</dt><dd class="mt-0.5 font-medium tabular-nums">{{ numero($m->quantidade) }}</dd></div>
                <div><dt class="text-xs text-zinc-500">Tipo</dt><dd class="mt-0.5 font-medium">{{ $tipos[$m->tipo] ?? $m->tipo }}</dd></div>
                <div class="sm:col-span-2"><dt class="text-xs text-zinc-500">Problema / serviço</dt>
                    <dd class="mt-1 rounded-lg bg-zinc-50 p-3 whitespace-pre-line dark:bg-zinc-950">{{ $m->descricao }}</dd></div>
                <div><dt class="text-xs text-zinc-500">Fornecedor</dt><dd class="mt-0.5 font-medium">{{ $m->fornecedor ?: '—' }}</dd></div>
                <div><dt class="text-xs text-zinc-500">Início</dt>
                    <dd class="mt-0.5 font-medium tabular-nums">{{ data_br($m->data_inicio) }} <span class="font-normal text-zinc-500">· aberta por {{ $m->usuario ?? '—' }}</span></dd></div>
                @if ($m->data_fim !== null)
                    <div><dt class="text-xs text-zinc-500">Conclusão</dt><dd class="mt-0.5 font-medium tabular-nums">{{ data_br($m->data_fim) }}</dd></div>
                    <div><dt class="text-xs text-zinc-500">Custo</dt>
                        <dd class="mt-0.5 font-medium tabular-nums">{{ moeda($m->custo) }}@if ($m->caixa_id) <span class="font-normal text-zinc-500">· lançado no caixa</span>@endif</dd></div>
                    <div class="sm:col-span-2"><dt class="text-xs text-zinc-500">Serviço realizado</dt>
                        <dd class="mt-1 rounded-lg bg-zinc-50 p-3 whitespace-pre-line dark:bg-zinc-950">{{ $m->solucao ?: '—' }}</dd></div>
                @endif
            </dl>
        </section>
    </div>

    <div class="space-y-6">
        @if ($m->data_fim === null)
            <section class="cartao p-5">
                <h2 class="cartao-titulo">Concluir manutenção</h2>
                <p class="mt-1 text-xs text-zinc-500">As peças voltam para o estoque disponível.</p>
                <form method="post" action="{{ route('manutencoes.concluir', $m->id) }}" class="mt-4 grid gap-4">
                    @csrf
                    @error('concluir')
                        <p role="alert" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950/50 dark:text-red-300">{{ $message }}</p>
                    @enderror
                    <label class="block">
                        <span class="text-sm font-medium">Data de volta <span class="text-red-500">*</span></span>
                        <input type="date" name="data_fim" value="{{ old('data_fim', $hoje) }}" min="{{ $m->data_inicio }}" max="{{ $hoje }}" required class="campo mt-1.5">
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium">Custo</span>
                        <input type="text" name="custo" value="{{ old('custo') }}" inputmode="decimal" placeholder="0,00" class="campo mt-1.5 text-right tabular-nums">
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium">Serviço realizado</span>
                        <textarea name="solucao" rows="3" maxlength="500" class="campo mt-1.5 resize-y">{{ old('solucao') }}</textarea>
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="lancar_caixa" value="1" @checked(session()->hasOldInput() ? old('lancar_caixa') : true)>
                        Lançar o custo como saída no caixa
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium">Forma de pagamento</span>
                        <select name="forma" class="campo mt-1.5">
                            @foreach (\App\Support\Estoque::FORMAS_PGTO as $forma)
                                <option value="{{ $forma }}" @selected(old('forma', 'PIX') === $forma)>{{ $forma }}</option>
                            @endforeach
                        </select>
                    </label>
                    <button type="submit" class="btn-primario w-full justify-center"><x-icone nome="ok" class="size-4" /> Concluir manutenção</button>
                </form>
            </section>

            <section class="cartao border-red-200 p-5 dark:border-red-900/60">
                <h2 class="text-sm font-semibold text-red-700 dark:text-red-400">Excluir</h2>
                <p class="mt-1 text-xs text-zinc-500">Só para manutenção aberta por engano. Concluída fica no histórico.</p>
                <form method="post" action="{{ route('manutencoes.destroy', $m->id) }}" class="mt-3" data-confirmar="Excluir esta manutenção (aberta por engano)?">
                    @csrf
                    @method('DELETE')
                    <button class="btn w-full justify-center border border-red-200 bg-white text-red-600 hover:bg-red-50 dark:border-red-900 dark:bg-transparent dark:hover:bg-red-950">Excluir manutenção</button>
                </form>
            </section>
        @endif
    </div>
</div>
@endsection
