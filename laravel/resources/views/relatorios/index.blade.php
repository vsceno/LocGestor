@extends('layouts.app', ['titulo' => 'Relatórios', 'ativo' => 'relatorios'])

@section('subtitulo', 'Resumos do período e da situação atual. Todos podem ser impressos e exportados para o Excel.')

@section('conteudo')
<div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
    @foreach ($relatorios as $chave => $rel)
        <a href="{{ route('relatorios.ver', $chave) }}" class="cartao block p-5 transition hover:-translate-y-0.5 hover:shadow-md">
            <h2 class="cartao-titulo">{{ $rel['titulo'] }}</h2>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ $rel['descricao'] }}</p>
            <span class="{{ $rel['periodo'] ? 'selo-azul' : 'selo-cinza' }} mt-3">{{ $rel['periodo'] ? 'por período' : 'situação atual' }}</span>
        </a>
    @endforeach
    @if ($admin)
        <a href="{{ route('relatorios.contador') }}" class="cartao block p-5 transition hover:-translate-y-0.5 hover:shadow-md">
            <h2 class="cartao-titulo">Exportação para o contador</h2>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Lançamentos do caixa, recebimentos de locação com CPF/CNPJ, contas pagas e resumo do mês, em CSV ou .zip.</p>
            <span class="selo-azul mt-3">por mês</span>
        </a>
    @endif
</div>
@endsection
