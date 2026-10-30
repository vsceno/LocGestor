@php
    $novo = $u === null;
    $enviado = (bool) old('_enviado');
    $v = fn (string $campo, $padrao = '') => old($campo, $u->$campo ?? $padrao);
    $valor = $enviado ? old('aquisicao_valor') : (($u->aquisicao_valor ?? 0) > 0 ? numero($u->aquisicao_valor, 2) : '');
    $titulo = $novo ? 'Nova unidade' : 'Unidade ' . $u->patrimonio;
    $migalhas = [['Produtos', route('produtos.index')], [$p->descricao, route('produtos.edit', $p->id) . '#patrimonio']];
    $campo = fn (string $nome) => 'campo mt-1.5' . ($errors->has($nome) ? ' border-red-400 focus:border-red-500 focus:ring-red-500/15 dark:border-red-700' : '');
    $hoje = now()->toDateString();
@endphp
@extends('layouts.app', ['titulo' => $titulo, 'ativo' => 'produtos', 'migalhas' => $migalhas])

@section('subtitulo', $p->codigo . ' · ' . $p->descricao)

@section('conteudo')
@if ($errors->any() && !$errors->has('baixa'))
    <div role="alert" class="mb-6 flex gap-3 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/50 dark:text-red-300">
        <x-icone nome="alerta" class="mt-0.5 size-5" />
        <div><p class="font-medium">Confira o campo destacado.</p><p class="mt-0.5 text-red-700/80 dark:text-red-300/80">{{ $errors->first() }}</p></div>
    </div>
@endif
@if ($cheio)
    <div role="alert" class="mb-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-300">
        Todas as {{ numero($p->qtd_total) }} peças do estoque já têm patrimônio. Aumente o estoque do produto antes de cadastrar outra unidade.
    </div>
@endif

<div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
    <form method="post" action="{{ $novo ? route('unidades.store', $p->id) : route('unidades.update', $u->id) }}" autocomplete="off" novalidate class="cartao xl:col-span-2">
        @csrf
        @unless ($novo) @method('PUT') @endunless
        <input type="hidden" name="_enviado" value="1">
        <div class="cartao-cabecalho">
            <h2 class="cartao-titulo">Identificação</h2>
            @unless ($novo) @include('produtos._situacao_unidade', ['u' => $u]) @endunless
        </div>
        <div class="grid grid-cols-1 gap-5 p-5 sm:grid-cols-2">
            <label class="block">
                <span class="text-sm font-medium">Nº de patrimônio <span class="text-red-500">*</span></span>
                <input type="text" name="patrimonio" value="{{ $v('patrimonio') }}" required maxlength="30" class="{{ $campo('patrimonio') }} font-mono uppercase" @if ($novo) autofocus @endif>
                @error('patrimonio')<span class="mt-1 block text-xs text-red-600 dark:text-red-400">{{ $message }}</span>@enderror
            </label>
            <label class="block">
                <span class="text-sm font-medium">Nº de série</span>
                <input type="text" name="serie" value="{{ $v('serie') }}" maxlength="60" class="{{ $campo('serie') }}">
                @error('serie')<span class="mt-1 block text-xs text-red-600 dark:text-red-400">{{ $message }}</span>@enderror
            </label>
            <label class="block">
                <span class="text-sm font-medium">Data de aquisição</span>
                <input type="date" name="aquisicao_data" value="{{ $v('aquisicao_data') }}" max="{{ $hoje }}" class="{{ $campo('aquisicao_data') }}">
                @error('aquisicao_data')<span class="mt-1 block text-xs text-red-600 dark:text-red-400">{{ $message }}</span>@enderror
            </label>
            <label class="block">
                <span class="text-sm font-medium">Valor de aquisição</span>
                <span class="relative mt-1.5 block">
                    <span class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-zinc-400">R$</span>
                    <input type="text" name="aquisicao_valor" value="{{ $valor }}" inputmode="decimal" placeholder="0,00" class="{{ $campo('aquisicao_valor') }} mt-0 pl-9 text-right tabular-nums">
                </span>
                @error('aquisicao_valor')<span class="mt-1 block text-xs text-red-600 dark:text-red-400">{{ $message }}</span>@enderror
            </label>
            <label class="block sm:col-span-2">
                <span class="text-sm font-medium">Observações</span>
                <textarea name="obs" rows="3" maxlength="2000" class="{{ $campo('obs') }} resize-y">{{ $v('obs') }}</textarea>
                @error('obs')<span class="mt-1 block text-xs text-red-600 dark:text-red-400">{{ $message }}</span>@enderror
            </label>
            @if (!$novo && !$u->ativa)
                <p class="rounded-lg bg-zinc-100 px-3 py-2 text-sm text-zinc-700 sm:col-span-2 dark:bg-zinc-800 dark:text-zinc-300">Baixada em {{ data_br($u->baixa_data) }}: {{ $u->baixa_motivo }}</p>
            @endif
        </div>
        <div class="flex justify-end gap-2 border-t border-zinc-100 px-5 py-3 dark:border-zinc-800">
            <a class="btn-secundario" href="{{ route('produtos.edit', $p->id) }}#patrimonio">Voltar ao produto</a>
            <button type="submit" class="btn-primario" @disabled($cheio)>{{ $novo ? 'Cadastrar unidade' : 'Salvar alterações' }}</button>
        </div>
    </form>

    @if (!$novo && $u->situacao === 'disponivel')
        <div class="space-y-6">
            <section class="cartao p-5">
                <h2 class="cartao-titulo">Manutenção</h2>
                <p class="mt-1 text-xs text-zinc-500">A unidade fica indisponível até a manutenção ser concluída.</p>
                <a class="btn-secundario mt-3" href="{{ legado('manutencoes', ['a' => 'nova', 'unidade' => $u->id]) }}"><x-icone nome="manutencao" class="size-4" /> Enviar para manutenção</a>
            </section>
            <form method="post" action="{{ route('unidades.baixar', $u->id) }}" class="cartao border-red-200 p-5 dark:border-red-900/60"
                  data-confirmar="Dar baixa nesta unidade? O estoque do produto diminui 1.">
                @csrf
                <h2 class="text-sm font-semibold text-red-700 dark:text-red-400">Baixa</h2>
                <p class="mt-1 text-xs text-zinc-500">Venda, perda ou sucata: a unidade sai do patrimônio e o estoque do produto diminui 1. O histórico continua.</p>
                @error('baixa')<p class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950/50 dark:text-red-300">{{ $message }}</p>@enderror
                <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-[10rem_1fr] xl:grid-cols-1">
                    <label class="block">
                        <span class="text-sm font-medium">Data <span class="text-red-500">*</span></span>
                        <input type="date" name="baixa_data" value="{{ old('baixa_data', $hoje) }}" max="{{ $hoje }}" required class="campo mt-1.5">
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium">Motivo <span class="text-red-500">*</span></span>
                        <input type="text" name="baixa_motivo" value="{{ old('baixa_motivo') }}" maxlength="200" required placeholder="vendida, perdida, sucata…" class="campo mt-1.5">
                    </label>
                </div>
                <button class="btn mt-4 border border-red-200 bg-white text-red-600 hover:bg-red-50 dark:border-red-900 dark:bg-transparent dark:hover:bg-red-950">Dar baixa</button>
            </form>
        </div>
    @endif
</div>

@unless ($novo)
    <section class="cartao mt-6 overflow-hidden">
        <div class="cartao-cabecalho"><h2 class="cartao-titulo">Histórico</h2></div>
        @if (!$historico)
            <div class="flex flex-col gap-3 px-5 py-6 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm text-zinc-500">Ainda sem locações ou manutenções.</p>
                <form method="post" action="{{ route('unidades.destroy', $u->id) }}" data-confirmar="Excluir esta unidade (cadastrada por engano)?">
                    @csrf @method('DELETE')
                    <button class="btn border border-red-200 bg-white text-red-600 hover:bg-red-50 dark:border-red-900 dark:bg-transparent dark:hover:bg-red-950">
                        <x-icone nome="fechar" class="size-4" /> Excluir (cadastrada por engano)</button>
                </form>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="tabela">
                    <thead><tr><th>O quê</th><th>De</th><th>Até</th><th>Detalhe</th></tr></thead>
                    <tbody>
                    @foreach ($historico as $h)
                        <tr>
                            <td>
                                @if ($h->tipo === 'locacao')
                                    <a class="font-medium text-marca-700 hover:underline dark:text-marca-400" href="{{ route('locacoes.show', $h->numero) }}">Locação nº {{ $h->numero }}</a>
                                @else
                                    <a class="font-medium text-marca-700 hover:underline dark:text-marca-400" href="{{ legado('manutencoes', ['a' => 'ver', 'id' => $h->ref]) }}">Manutenção nº {{ $h->numero }}</a>
                                @endif
                            </td>
                            <td class="tabular-nums">{{ data_br($h->inicio) }}</td>
                            <td class="tabular-nums">@if ($h->fim){{ data_br($h->fim) }}@else<span class="selo-azul">em andamento</span>@endif</td>
                            <td class="text-zinc-600 dark:text-zinc-400">{{ \Illuminate\Support\Str::limit((string) $h->detalhe, 80) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endunless
@endsection
