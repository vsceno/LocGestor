@extends('layouts.impressao', ['titulo' => 'Recibo nº ' . $r->numero, 'voltar' => route('recibos.show', $r->id)])

@section('folha')
    @include('impressao._cabecalho', ['titulo' => 'Recibo', 'destaque' => 'Nº ' . $r->numero])
    <p style="font-size:1.1em; line-height:1.7">
        Recebemos de <strong>{{ $r->recebido_de }}</strong>
        @if ($r->documento !== '')({{ doc_formatar($r->documento) }})@endif
        a importância de <strong>{{ moeda($r->valor) }}</strong>
        ({{ $r->forma }}), referente a {{ $r->referente }}.
    </p>
    <p>Data: {{ data_br($r->emissao) }}</p>
    <p style="margin-top:60px">______________________________________________<br>Assinatura do responsável</p>
@endsection
