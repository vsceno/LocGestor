@php
    $titulo = $v->tipo === 'retirada' ? 'Vistoria de retirada' : 'Vistoria de devolução';
@endphp
@extends('layouts.impressao', ['titulo' => $titulo . ' · locação nº ' . $v->numero, 'voltar' => route('locacoes.show', $v->numero)])

@section('folha')
    @include('impressao._cabecalho', ['titulo' => $titulo, 'destaque' => 'da locação nº ' . $v->numero, 'info' => 'Feita em ' . data_br($v->criado_em, true)])

    <p><strong>Locatário:</strong> {{ $v->cliente }} · <strong>Responsável pela vistoria:</strong> {{ $v->usuario ?? '' }}</p>

    <h2>Estado dos equipamentos</h2>
    <table>
        <thead><tr><th>Código</th><th>Descrição</th><th class="num">Qtd.</th><th>Estado</th><th>Observação</th></tr></thead>
        <tbody>
            @foreach ($itens as $it)
                <tr>
                    <td>{{ $it->codigo }}</td>
                    <td>{{ $it->descricao }}</td>
                    <td class="num">{{ numero($it->quantidade) }} {{ $it->unidade }}</td>
                    <td><strong>{{ $estados[$it->estado] ?? $it->estado }}</strong></td>
                    <td>{{ $it->obs }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($v->obs !== '')
        <h2>Observações</h2>
        <p>{!! nl2br(e($v->obs)) !!}</p>
    @endif

    @if ($fotos)
        <h2>Fotos ({{ numero(count($fotos)) }})</h2>
        <div class="fotos">
            @foreach ($fotos as $i => $f)
                <figure>
                    <img src="{{ route('arquivos.foto', $f->id) }}" alt="Foto {{ $i + 1 }} da vistoria" loading="lazy">
                    <figcaption>Foto {{ $i + 1 }}</figcaption>
                </figure>
            @endforeach
        </div>
    @endif

    <h2>Assinatura</h2>
    @if ($v->assinatura_arquivo !== '')
        <div class="assinatura-impressa">
            <img src="{{ route('arquivos.assinatura', $v->id) }}" alt="Assinatura de {{ $v->assinante_nome }}">
            <p>{{ $v->assinante_nome }}<br><small class="muted">Assinado eletronicamente em {{ data_br($v->assinado_em, true) }},
                na tela do sistema, concordando com o estado registrado acima.</small></p>
        </div>
    @else
        <p class="muted">Vistoria sem assinatura do cliente.</p>
    @endif
@endsection
