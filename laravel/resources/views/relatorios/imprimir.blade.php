@extends('layouts.impressao', ['titulo' => $rel['titulo'], 'voltar' => $voltar])

@section('folha')
    @include('impressao._cabecalho', ['titulo' => $rel['titulo'], 'info' => $subtitulo])
    <p class="muted">{{ $rel['descricao'] }}</p>
    @include('relatorios._tabela', ['rel' => $rel, 'linhas' => $linhas, 'totais' => $totais])
@endsection
