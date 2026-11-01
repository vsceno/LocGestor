@extends('layouts.app', ['titulo' => 'Recibo nº ' . $r->numero, 'ativo' => 'recibos', 'migalhas' => [['Recibos', route('recibos.index')]]])

@section('subtitulo', 'Emitido em ' . data_br($r->emissao))

@section('acoes')
    <a class="btn-primario" href="{{ route('recibos.imprimir', $r->id) }}" target="_blank" rel="noopener"><x-icone nome="recibos" class="size-4" /> Imprimir</a>
@endsection

@section('conteudo')
<section class="cartao mx-auto max-w-3xl">
    <div class="cartao-cabecalho">
        <h2 class="cartao-titulo">Recibo nº {{ $r->numero }}</h2>
        <span class="selo-verde">{{ moeda($r->valor) }}</span>
    </div>
    <dl class="grid grid-cols-1 gap-x-6 gap-y-4 p-5 text-sm sm:grid-cols-2">
        <div class="sm:col-span-2">
            <dt class="text-xs text-zinc-500">Recebido de</dt>
            <dd class="mt-0.5 font-medium">{{ $r->recebido_de }}@if ($r->documento !== '') <span class="font-normal text-zinc-500">· {{ doc_formatar($r->documento) }}</span>@endif</dd>
        </div>
        <div><dt class="text-xs text-zinc-500">Valor</dt><dd class="mt-0.5 font-medium tabular-nums">{{ moeda($r->valor) }}</dd></div>
        <div><dt class="text-xs text-zinc-500">Forma</dt><dd class="mt-0.5 font-medium">{{ $r->forma }}</dd></div>
        <div class="sm:col-span-2"><dt class="text-xs text-zinc-500">Referente a</dt><dd class="mt-0.5 font-medium">{{ $r->referente }}</dd></div>
        <div><dt class="text-xs text-zinc-500">Emissão</dt><dd class="mt-0.5 font-medium tabular-nums">{{ data_br($r->emissao) }}</dd></div>
        @if ($r->locacao_numero !== null)
            <div>
                <dt class="text-xs text-zinc-500">Locação</dt>
                <dd class="mt-0.5"><a class="font-medium text-marca-700 hover:underline dark:text-marca-400" href="{{ route('locacoes.show', $r->locacao_numero) }}">nº {{ $r->locacao_numero }}</a></dd>
            </div>
        @endif
    </dl>
</section>
@endsection
