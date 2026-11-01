@extends('layouts.impressao', ['titulo' => 'Orçamento nº ' . $o->numero, 'voltar' => route('orcamentos.show', $o->id)])

@section('folha')
    @include('impressao._cabecalho', ['titulo' => 'Orçamento', 'destaque' => 'Nº ' . $o->numero])

    <h2>Cliente</h2>
    <p><strong>{{ $o->cliente_nome }}</strong> · {{ doc_formatar($o->cliente_doc) }}</p>

    <h2>Período</h2>
    <p>Retirada em {{ data_br($o->data_saida) }} · devolução em {{ data_br($o->data_prev_devolucao) }} ({{ numero($dias) }} dia(s))
        @if ($o->local_uso !== '') · local: {{ $o->local_uso }}@endif</p>

    <h2>Itens</h2>
    <table>
        <thead>
            <tr><th>Descrição</th><th>Período</th><th class="num">Qtd.</th><th class="num">Preço (un.)</th><th class="num">Subtotal previsto</th></tr>
        </thead>
        <tbody>
            @foreach ($itens as $it)
                <tr>
                    <td>{{ $it->descricao }}</td>
                    <td>{{ $it->periodo_nome }}</td>
                    <td class="num">{{ numero($it->quantidade) }}</td>
                    <td class="num">{{ moeda($it->preco_un) }}</td>
                    <td class="num">{{ moeda($it->quantidade * (float) $it->preco_total) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr><td colspan="4" class="num">Subtotal</td><td class="num">{{ moeda($subtotal) }}</td></tr>
            @include('impressao._ajustes', ['v' => $o, 'colunas' => 5])
            <tr><td colspan="4" class="num">Valor Total</td><td class="num">{{ moeda($o->previsto) }}</td></tr>
        </tfoot>
    </table>

    <p class="obs-fiscal">Orçamento válido até {{ data_br($o->validade) }}. Não reserva equipamento: a disponibilidade é confirmada na reserva ou na retirada.
        Valores em reais, para o período informado.@if ($o->obs !== '') Observações: {{ $o->obs }}@endif</p>

    @if (trim((string) $empresa->cidade) !== '')
        <p style="margin-top:6mm">{{ $empresa->cidade }}, {{ data_br(now()->toDateString()) }}.</p>
    @endif
@endsection
