@extends('layouts.impressao', ['titulo' => 'Contrato de locação nº ' . $loc->numero, 'voltar' => route('locacoes.show', $loc->numero)])

@section('folha')
    @include('impressao._cabecalho', ['titulo' => 'Contrato de locação', 'destaque' => 'Nº ' . $loc->numero])
    @include('impressao._locatario', ['c' => $cliente])

    <p class="preambulo">Pelo presente instrumento, de um lado <strong>{{ $empresa->razao_social }}</strong>, doravante
        LOCADORA, e de outro <strong>{{ $loc->cliente_nome }}</strong>, doravante LOCATÁRIO, ajustam a locação
        dos bens abaixo, nas condições a seguir.</p>

    <h2>Período e local</h2>
    <table>
        <tr>
            <th style="width:22%">Saída</th><td>{{ data_br($loc->data_saida) }}</td>
            <th style="width:22%">Devolução prevista</th><td>{{ data_br($loc->data_prev_devolucao) }} ({{ numero($dias) }} dia(s))</td>
        </tr>
        @if ($loc->local_uso !== '')
            <tr><th>Local de uso</th><td colspan="3">{{ $loc->local_uso }}</td></tr>
        @endif
    </table>

    <h2>Bens locados</h2>
    <table>
        <thead>
            <tr>
                <th>Descrição</th><th style="width:30mm">Período</th><th class="num" style="width:16mm">Qtd.</th>
                <th class="num" style="width:30mm">Preço (un.)</th><th class="num" style="width:34mm">Subtotal previsto</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($itens as $it)
                <tr>
                    <td>{{ $it->descricao }}@if ($it->patrimonios)<br><small>Patrimônio: {{ $it->patrimonios }}</small>@endif</td>
                    <td>{{ \App\Support\Documentos::periodoNome($it->periodo) }}</td>
                    <td class="num">{{ numero($it->quantidade) }}</td>
                    <td class="num">{{ moeda(\App\Support\Documentos::precoUnitario($it)) }}</td>
                    <td class="num">{{ moeda($it->quantidade * (float) $it->preco_total) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr><td colspan="4" class="num">Subtotal</td><td class="num">{{ moeda($subtotal) }}</td></tr>
            @include('impressao._ajustes', ['v' => $loc, 'colunas' => 5])
            <tr><td colspan="4" class="num">Valor Total</td><td class="num">{{ moeda($loc->valor_previsto) }}</td></tr>
        </tfoot>
    </table>

    <h2>Condições gerais</h2>
    <div class="clausulas">
        @foreach ($clausulas as $bloco)
            @if ($bloco['tipo'] === 'clausula')
                <div class="clausula">
                    <p class="clausula-titulo">{{ $bloco['titulo'] }}</p>
                    <p>{{ $bloco['texto'] }}</p>
                    @if ($bloco['reposicao'])
                        <p class="reposicao-titulo">Valores de reposição dos bens locados:</p>
                        <ul class="reposicao">
                            @foreach ($bloco['reposicao'] as $linha)<li>{{ $linha }}</li>@endforeach
                        </ul>
                    @endif
                </div>
            @else
                <div class="clausula"><p>{{ $bloco['texto'] }}</p></div>
            @endif
        @endforeach
    </div>

    <p class="data-contrato">{{ ($empresa->cidade ?: '') . ($empresa->cidade ? ', ' : '') . \App\Support\Documentos::dataExtenso($loc->data_saida) }}.</p>

    <div class="assinaturas">
        <div>
            LOCADORA<br>{{ $empresa->razao_social }}
            @if ($empresa->cnpj !== '')<br><small>CNPJ {{ doc_formatar($empresa->cnpj) }}</small>@endif
        </div>
        <div class="{{ $assinatura ? 'assinado' : '' }}">
            @if ($assinatura)
                <img src="{{ config('locgestor.legado_url') }}/index.php?p=arquivos&amp;a=assinatura&amp;id={{ $assinatura->id }}" alt="Assinatura do locatário">
            @endif
            LOCATÁRIO<br>{{ $loc->cliente_nome }}
            @if ($cliente->cpf_cnpj !== '')
                <br><small>{{ $cliente->tipo === 'PJ' ? 'CNPJ ' : 'CPF ' }}{{ doc_formatar($cliente->cpf_cnpj) }}</small>
            @endif
            @if ($assinatura)
                <small>Assinado eletronicamente por {{ $assinatura->assinante_nome }} em {{ data_br($assinatura->assinado_em, true) }},
                    na vistoria de retirada.</small>
            @endif
        </div>
    </div>
@endsection
