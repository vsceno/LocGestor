@extends('layouts.plataforma', ['titulo' => 'Planos', 'ativo' => 'planos', 'subtitulo' => 'Preço e limite de usuários. Mudar o preço vale para as próximas mensalidades.'])

@section('conteudo')
<div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
    <section class="cartao overflow-hidden xl:col-span-2">
        <div class="cartao-cabecalho"><h2 class="cartao-titulo">Planos</h2></div>
        <div class="overflow-x-auto">
            <table class="tabela">
                <thead>
                    <tr><th>Plano</th><th class="num">Preço/mês</th><th class="num">Usuários</th><th class="num">Locadoras</th>
                        <th class="num">Receita mensal</th><th></th></tr>
                </thead>
                <tbody>
                    @forelse ($planos as $p)
                        <tr>
                            <td class="font-medium">{{ $p->nome }}@unless ($p->ativo) <span class="selo-cinza ml-1">inativo</span>@endunless</td>
                            <td class="num">{{ moeda($p->preco_mensal) }}</td>
                            <td class="num">{{ numero($p->limite_usuarios) }}</td>
                            <td class="num">{{ numero($p->locadoras) }}</td>
                            <td class="num">{{ moeda($p->preco_mensal * $p->pagantes) }}</td>
                            <td class="whitespace-nowrap">
                                <div class="flex justify-end gap-2">
                                    <a class="btn-secundario" href="{{ route('plataforma.planos.index', ['id' => $p->id]) }}">Editar</a>
                                    @if ((int) $p->locadoras === 0)
                                        <form method="post" action="{{ route('plataforma.planos.excluir', $p->id) }}" data-confirmar="Excluir o plano {{ $p->nome }}?">
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn bg-red-600 text-white shadow-sm hover:bg-red-700">Excluir</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-10 text-center text-zinc-500">Nenhum plano cadastrado.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="cartao self-start">
        <div class="cartao-cabecalho"><h2 class="cartao-titulo">{{ $editar ? 'Editar ' . $editar->nome : 'Novo plano' }}</h2></div>
        <form method="post" action="{{ $editar ? route('plataforma.planos.atualizar', $editar->id) : route('plataforma.planos.criar') }}"
              class="grid gap-4 p-5">
            @csrf
            @if ($editar) @method('PUT') @endif
            <label class="block"><span class="text-sm font-medium">Nome <span class="text-red-500">*</span></span>
                <input type="text" name="nome" value="{{ old('nome', $editar->nome ?? '') }}" required maxlength="40" class="campo mt-1.5">
            </label>
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block"><span class="text-sm font-medium">Preço mensal (R$) <span class="text-red-500">*</span></span>
                    <input type="text" name="preco_mensal" inputmode="decimal" required
                           value="{{ old('preco_mensal', $editar ? numero($editar->preco_mensal, 2) : '') }}" class="campo mt-1.5">
                </label>
                <label class="block"><span class="text-sm font-medium">Limite de usuários <span class="text-red-500">*</span></span>
                    <input type="number" name="limite_usuarios" min="1" max="1000" required
                           value="{{ old('limite_usuarios', $editar->limite_usuarios ?? 3) }}" class="campo mt-1.5">
                </label>
            </div>
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="ativo" value="1" @checked(old('ativo', $editar->ativo ?? true))>
                Ativo (aparece para novas locadoras)
            </label>
            <div class="flex flex-wrap gap-2">
                <button class="btn-primario">Salvar</button>
                @if ($editar)<a class="btn-secundario" href="{{ route('plataforma.planos.index') }}">Novo plano</a>@endif
            </div>
        </form>
    </section>
</div>
@endsection
