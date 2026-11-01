@extends('layouts.app', ['titulo' => 'Recibos', 'ativo' => 'recibos'])

@section('subtitulo', 'Comprovantes numerados do pagamento. O número é fixo: o recibo não se altera nem se apaga. O pagamento continua sendo lançado no caixa.')

@section('acoes')
    <a class="btn-primario" href="{{ route('recibos.create') }}"><x-icone nome="mais" class="size-4" /> Novo recibo</a>
@endsection

@section('conteudo')
<section class="cartao overflow-hidden">
    @if ($recibos->total() === 0)
        <div class="px-6 py-16 text-center">
            <span class="mx-auto grid size-12 place-items-center rounded-full bg-marca-50 text-marca-600 dark:bg-marca-500/10"><x-icone nome="recibos" /></span>
            <p class="mt-3 font-medium">Nenhum recibo emitido ainda</p>
            <p class="mt-1 text-sm text-zinc-500">Emita um recibo avulso ou a partir de uma locação.</p>
            <a class="btn-primario mt-5" href="{{ route('recibos.create') }}"><x-icone nome="mais" class="size-4" /> Emitir recibo</a>
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="tabela">
                <thead><tr><th>Nº</th><th>Emissão</th><th>Recebido de</th><th>Referente a</th><th>Locação</th><th class="num">Valor</th><th class="w-px"></th></tr></thead>
                <tbody>
                @foreach ($recibos as $r)
                    <tr data-href="{{ route('recibos.show', $r->id) }}">
                        <td><a href="{{ route('recibos.show', $r->id) }}" class="font-semibold tabular-nums text-zinc-900 dark:text-zinc-100">{{ $r->numero }}</a></td>
                        <td class="tabular-nums whitespace-nowrap text-zinc-600 dark:text-zinc-400">{{ data_br($r->emissao) }}</td>
                        <td class="font-medium text-zinc-900 dark:text-zinc-100">{{ $r->recebido_de }}</td>
                        <td class="max-w-xs truncate text-zinc-600 dark:text-zinc-400">{{ $r->referente }}</td>
                        <td class="whitespace-nowrap">
                            @if ($r->locacao_numero !== null)
                                <a class="text-marca-700 hover:underline dark:text-marca-400" href="{{ route('locacoes.show', $r->locacao_numero) }}">nº {{ $r->locacao_numero }}</a>
                            @else<span class="text-zinc-400">—</span>@endif
                        </td>
                        <td class="num whitespace-nowrap font-medium">{{ moeda($r->valor) }}</td>
                        <td><a class="btn-secundario whitespace-nowrap" href="{{ route('recibos.imprimir', $r->id) }}" target="_blank" rel="noopener">Imprimir</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @include('partials.paginacao', ['p' => $recibos])
    @endif
</section>
@endsection
