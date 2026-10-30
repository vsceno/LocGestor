@extends('layouts.impressao', ['titulo' => 'Fatura do contrato de locação nº ' . $loc->numero, 'voltar' => route('locacoes.show', $loc->numero)])

@section('barra')
    @php $linkWhats = $whats['saldo'] ?? $whats['resumo'] ?? null; $rotuloWhats = isset($whats['saldo']) ? 'Cobrar saldo no WhatsApp' : 'Enviar resumo no WhatsApp'; @endphp
    @if ($linkWhats)
        <a class="btn-whats" href="{{ $linkWhats }}" target="_blank" rel="noopener">{{ $rotuloWhats }}</a>
    @else
        <span class="btn-whats desativado" title="Cliente sem celular ou telefone no cadastro">{{ $rotuloWhats }}</span>
    @endif
@endsection

@section('folha')
    @include('impressao._cabecalho', ['titulo' => 'Fatura do contrato de locação', 'destaque' => 'Nº ' . $loc->numero])
    @include('impressao._locatario', ['c' => $cliente])

    <h2>Período</h2>
    <p>Saída em {{ data_br($loc->data_saida) }} · devolução prevista {{ data_br($loc->data_prev_devolucao) }}
        · situação: <strong>{{ $aberta ? 'em aberto (valores até ' . data_br($hoje) . ')' : $loc->status }}</strong></p>

    <h2>Discriminação</h2>
    <table>
        <thead>
            <tr>
                <th>Devolução</th><th>Descrição</th><th>Período</th><th class="num">Qtd.</th><th class="num">Dias</th>
                <th class="num">Preço (un.)</th><th class="num">Valor</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($devolvidos as $l)
                <tr>
                    <td>{{ data_br($l->data) }}</td><td>{{ $l->descricao }}</td><td>{{ $l->periodo_nome }}</td>
                    <td class="num">{{ numero($l->quantidade) }}</td><td class="num">{{ numero($l->dias) }}</td>
                    <td class="num">{{ moeda($l->preco_un) }}</td><td class="num">{{ moeda($l->valor) }}</td>
                </tr>
            @endforeach
            @foreach ($pendentes as $l)
                <tr>
                    <td><em>pendente</em></td><td>{{ $l->descricao }}</td><td>{{ $l->periodo_nome }}</td>
                    <td class="num">{{ numero($l->quantidade) }}</td><td class="num">{{ numero($diasHoje) }}</td>
                    <td class="num">{{ moeda($l->preco_un) }}</td><td class="num">{{ moeda($l->quantidade * (float) $l->preco_total) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr><td colspan="6" class="num">Subtotal</td><td class="num">{{ moeda($subtotal) }}</td></tr>
            @include('impressao._ajustes', ['v' => $loc, 'colunas' => 7])
            @if ((float) $extras->multa > 0)
                <tr><td colspan="6" class="num">Multa</td><td class="num">{{ moeda($extras->multa) }}</td></tr>
            @endif
            @if ((float) $extras->avaria > 0)
                <tr><td colspan="6" class="num">Avaria</td><td class="num">{{ moeda($extras->avaria) }}</td></tr>
            @endif
            <tr><td colspan="6" class="num">Total da locação</td><td class="num">{{ moeda($loc->valor_atual) }}</td></tr>
            <tr><td colspan="6" class="num">Valor pago</td><td class="num">{{ moeda($loc->valor_pago) }}</td></tr>
            <tr><td colspan="6" class="num">Saldo a pagar</td><td class="num">{{ moeda(max(0, $saldo)) }}</td></tr>
        </tfoot>
    </table>

    @if ($pix)
        @include('impressao._pix', ['pix' => $pix])
    @endif

    <div class="obs-fiscal">{!! nl2br(e($empresa->obs_fatura)) !!}</div>
    <p class="muted" style="margin-top:4mm">Este documento é uma fatura de locação de bens móveis e não substitui nota fiscal quando exigida.</p>
@endsection

@section('scripts')
    @if ($pix)
        <script src="{{ asset_legado('assets/vendor/qrcode.js') }}"></script>
        <script src="{{ asset_legado('assets/js/pix.js') }}"></script>
    @endif
@endsection
