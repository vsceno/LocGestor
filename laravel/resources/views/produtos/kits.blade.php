@extends('layouts.app', ['titulo' => 'Kits', 'ativo' => 'produtos', 'migalhas' => [['Produtos', route('produtos.index')]]])

@section('subtitulo', 'Produtos que costumam sair juntos. Na locação, o kit entra com todos os itens de uma vez.')

@section('acoes')
    <a class="btn-primario" href="{{ route('kits.create') }}"><x-icone nome="mais" class="size-4" /> Novo kit</a>
@endsection

@section('conteudo')
<section class="cartao overflow-hidden">
    @if (!$kits)
        <div class="px-6 py-16 text-center">
            <span class="mx-auto grid size-12 place-items-center rounded-full bg-marca-50 text-marca-600 dark:bg-marca-500/10"><x-icone nome="produtos" /></span>
            <p class="mt-3 font-medium">Nenhum kit cadastrado</p>
            <p class="mt-1 text-sm text-zinc-500">Ex.: “Andaime completo” com painéis, travessas e sapatas. Estoque e preço continuam sendo os de cada produto.</p>
            <a class="btn-primario mt-5" href="{{ route('kits.create') }}"><x-icone nome="mais" class="size-4" /> Cadastrar kit</a>
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="tabela">
                <thead><tr><th>Kit</th><th>Composição</th><th class="num" title="Soma das diárias dos itens">Diária dos itens</th><th class="w-px"></th></tr></thead>
                <tbody>
                @foreach ($kits as $k)
                    <tr data-href="{{ route('kits.edit', $k->id) }}">
                        <td>
                            <a href="{{ route('kits.edit', $k->id) }}" class="block">
                                <span class="flex items-center gap-2">
                                    <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $k->nome }}</span>
                                    @unless ($k->ativo)<span class="selo-cinza">inativo</span>@endunless
                                </span>
                                <span class="block font-mono text-xs text-zinc-500">{{ $k->codigo }}</span>
                            </a>
                        </td>
                        <td class="max-w-xl text-sm text-zinc-600 dark:text-zinc-400">{{ $k->composicao ?? '—' }}</td>
                        <td class="num whitespace-nowrap">{{ moeda($k->diaria) }}</td>
                        <td>
                            <a class="btn-icone" href="{{ route('kits.edit', $k->id) }}" title="Editar" aria-label="Editar {{ $k->nome }}">
                                <x-icone nome="editar" class="size-[18px]" /></a>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
@endsection
