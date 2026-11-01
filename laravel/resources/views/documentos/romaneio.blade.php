@php $titulo = $troca ? 'Romaneio de troca' : 'Romaneio de retirada'; @endphp
@extends('layouts.impressao', ['titulo' => $titulo . ' · locação nº ' . $loc->numero, 'voltar' => route('locacoes.show', $loc->numero)])

@section('folha')
    @include('impressao._cabecalho', ['titulo' => $titulo, 'destaque' => 'da locação nº ' . $loc->numero])
    @include('impressao._locatario', ['c' => $cliente])

    <h2>Saída e devolução previstas</h2>
    <p>{{ data_br($loc->data_saida) }} → {{ data_br($loc->data_prev_devolucao) }}@if ($loc->local_uso !== '') · local: {{ $loc->local_uso }}@endif</p>

    <h2>Itens</h2>
    <table>
        <thead>
            <tr>
                <th class="num">Qtd.</th><th>Código</th><th>Descrição</th><th>Patrimônio</th>
                @if ($troca)<th>Saiu</th><th>Entrou</th>@else<th>Conferido</th>@endif
            </tr>
        </thead>
        <tbody>
            @foreach ($itens as $it)
                <tr>
                    <td class="num">{{ numero($it->quantidade) }} {{ $it->unidade }}</td>
                    <td class="nowrap">{{ $it->codigo }}</td>
                    <td>{{ $it->descricao }}</td>
                    <td>{{ $it->patrimonios ?? '' }}</td>
                    @if ($troca)<td></td><td></td>@else<td>&#9744;</td>@endif
                </tr>
            @endforeach
            @if ($troca)
                @for ($i = 0; $i < 3; $i++)
                    <tr><td></td><td></td><td>&nbsp;</td><td></td><td></td><td></td></tr>
                @endfor
            @endif
        </tbody>
    </table>

    <p style="margin-top:50px">Assinatura do cliente: ______________________________ Documento: ____________________ Data: ____/____/______</p>
    <p>Responsável da locadora: ______________________________</p>
@endsection
