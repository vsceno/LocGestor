@extends('layouts.app', ['titulo' => 'Roteiro', 'ativo' => 'roteiro'])

@section('subtitulo', 'Entregas e coletas do dia, para o celular do motorista. Registre o que foi feito.')

@section('conteudo')
<section class="cartao mb-6 p-4">
    <form method="get" action="{{ route('roteiro.index') }}" class="flex flex-wrap items-center gap-2">
        <a class="btn-secundario" href="{{ route('roteiro.index', ['data' => $anterior]) }}" aria-label="Dia anterior">‹</a>
        <input type="date" name="data" value="{{ $data }}" aria-label="Dia" class="campo">
        <a class="btn-secundario" href="{{ route('roteiro.index', ['data' => $seguinte]) }}" aria-label="Dia seguinte">›</a>
        <button class="btn-secundario">Ver</button>
    </form>
    <p class="mt-3 text-sm text-zinc-500 dark:text-zinc-400">
        {{ $data === $hoje ? 'Hoje' : data_br($data) }}: {{ numero(count($paradas)) }} parada(s), {{ numero($feitas) }} feita(s).
    </p>
</section>

@forelse ($paradas as $p)
    @php
        $tel = whatsapp_numero((object) ['celular' => $p['celular'], 'telefone' => $p['telefone']]);
        $msg = \App\Support\Roteiro::saudacao($p['nome'], $p['cliente_tipo'], $empresa) . ' '
             . ($p['tipo'] === 'entrega'
                 ? "Estamos a caminho para entregar a locação nº {$p['numero']}: {$p['itens']}."
                 : "Vamos passar para buscar a locação nº {$p['numero']}: {$p['itens']}.");
        $whats = $tel !== null ? 'https://wa.me/' . $tel . '?text=' . rawurlencode($msg) : null;
        $mapa = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($p['endereco_parada']);
    @endphp
    <section class="cartao mb-4 p-5">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="cartao-titulo">
                {{ \App\Support\Roteiro::TIPOS[$p['tipo']] }} ·
                <a class="text-marca-700 hover:underline dark:text-marca-400" href="{{ route('locacoes.show', $p['numero']) }}">nº {{ numero($p['numero']) }}</a>
            </h2>
            @if ($p['feita'])
                <span class="selo-verde">feita {{ data_br($p['feita']['feita_em'], true) }}</span>
            @elseif ($p['atrasada'])
                <span class="selo-vermelho">atrasada desde {{ data_br($p['data_prev_devolucao']) }}</span>
            @else
                <span class="selo-amarelo">pendente</span>
            @endif
        </div>

        <p class="mt-3 font-semibold text-zinc-900 dark:text-zinc-100">{{ $p['nome'] }}</p>
        <p class="mt-1 text-sm text-zinc-700 dark:text-zinc-300">
            @if ($p['endereco_parada'] !== '')
                {{ $p['endereco_parada'] }}
            @else
                <span class="text-red-600 dark:text-red-400">Sem endereço: confira com o cliente.</span>
            @endif
        </p>
        <p class="mt-1 text-sm text-zinc-500">{{ $p['tipo'] === 'entrega' ? 'Levar: ' : 'Buscar: ' }}{{ $p['itens'] }}</p>
        @if ($p['feita'] && $p['feita']['obs'] !== '')
            <p class="mt-1 text-sm text-zinc-500">Obs.: {{ $p['feita']['obs'] }} ({{ $p['feita']['usuario'] ?? '' }})</p>
        @endif

        <div class="mt-4 flex flex-wrap items-center gap-2">
            @if ($p['endereco_parada'] !== '')
                <a class="btn-secundario" href="{{ $mapa }}" target="_blank" rel="noopener noreferrer">Mapa</a>
            @endif
            @if ($tel !== null)
                <a class="btn-secundario" href="tel:+{{ $tel }}"><x-icone nome="telefone" class="size-4" /> Ligar</a>
            @endif
            @if ($whats !== null)
                <a class="btn border border-emerald-200 bg-emerald-50 text-emerald-800 hover:bg-emerald-100 dark:border-emerald-900 dark:bg-emerald-500/10 dark:text-emerald-300"
                   href="{{ $whats }}" target="_blank" rel="noopener noreferrer" title="Abre o WhatsApp com a mensagem pronta">
                    <x-icone nome="whatsapp" class="size-4" /> WhatsApp</a>
            @else
                <span class="btn cursor-not-allowed opacity-60" title="Cliente sem celular ou telefone no cadastro">
                    <x-icone nome="whatsapp" class="size-4" /> WhatsApp</span>
            @endif

            @if (!$p['feita'] && $data <= $hoje)
                <form method="post" action="{{ route('roteiro.marcar') }}" class="flex flex-wrap items-center gap-2">
                    @csrf
                    <input type="hidden" name="id" value="{{ $p['id'] }}">
                    <input type="hidden" name="tipo" value="{{ $p['tipo'] }}">
                    <input type="hidden" name="data" value="{{ $data }}">
                    <input type="text" name="obs" maxlength="200" placeholder="Observação (opcional)" aria-label="Observação" class="campo w-56">
                    <button class="btn-primario">{{ $p['tipo'] === 'entrega' ? 'Entregue' : 'Coletado' }}</button>
                </form>
            @elseif ($p['feita'])
                <form method="post" action="{{ route('roteiro.desmarcar') }}" data-confirmar="Desfazer o registro desta parada?">
                    @csrf
                    <input type="hidden" name="id" value="{{ $p['id'] }}">
                    <input type="hidden" name="tipo" value="{{ $p['tipo'] }}">
                    <input type="hidden" name="data" value="{{ $data }}">
                    <button class="btn-secundario">Desfazer</button>
                </form>
                @if ($p['tipo'] === 'coleta')
                    <a class="btn-primario" href="{{ route('locacoes.devolucao', $p['numero']) }}">Registrar devolução</a>
                @endif
            @endif
        </div>
    </section>
@empty
    <section class="cartao p-6 text-center text-sm text-zinc-500">Nenhuma entrega ou coleta em {{ data_br($data) }}.</section>
@endforelse
@endsection
