@extends('layouts.plataforma', ['titulo' => 'Recados', 'ativo' => 'recados', 'subtitulo' => 'Avisos no topo do sistema de todas as locadoras, até a data de validade.'])

@section('conteudo')
<div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
    <form method="post" action="{{ route('plataforma.recados.criar') }}" class="cartao self-start">
        @csrf
        <div class="cartao-cabecalho"><h2 class="cartao-titulo">Novo recado</h2></div>
        <div class="grid gap-4 p-5">
            <p class="text-sm text-zinc-500">Aparece no topo do sistema de todas as locadoras até a data de validade (ou até você retirar).</p>
            <label class="block"><span class="text-sm font-medium">Tipo <span class="text-red-500">*</span></span>
                <select name="tipo" required class="campo mt-1.5">
                    @foreach ($tipos as $chave => $rotulo)
                        <option value="{{ $chave }}" @selected(old('tipo', 'aviso') === $chave)>{{ $rotulo }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block"><span class="text-sm font-medium">Título <span class="text-red-500">*</span></span>
                <input type="text" name="titulo" value="{{ old('titulo') }}" required maxlength="120" class="campo mt-1.5">
            </label>
            <label class="block"><span class="text-sm font-medium">Texto <span class="text-red-500">*</span></span>
                <textarea name="texto" required maxlength="600" rows="4" class="campo mt-1.5">{{ old('texto') }}</textarea>
            </label>
            <label class="block"><span class="text-sm font-medium">Válido até <span class="text-red-500">*</span></span>
                <input type="date" name="expira_em" value="{{ old('expira_em', now()->addDays(7)->toDateString()) }}"
                       min="{{ now()->toDateString() }}" required class="campo mt-1.5">
            </label>
            <div><button class="btn-primario">Publicar recado</button></div>
        </div>
    </form>

    <section class="cartao overflow-hidden xl:col-span-2">
        <div class="cartao-cabecalho"><h2 class="cartao-titulo">Recados publicados</h2></div>
        <div class="overflow-x-auto">
            <table class="tabela">
                <thead><tr><th>Recado</th><th>Válido até</th><th>Situação</th><th></th></tr></thead>
                <tbody>
                    @forelse ($recados as $r)
                        @php $vigente = $r->ativo && $r->expira_em >= now()->toDateString(); @endphp
                        <tr>
                            <td>
                                <div class="font-medium">{{ $r->titulo }} <span class="selo-azul ml-1">{{ $tipos[$r->tipo] ?? $r->tipo }}</span></div>
                                <div class="text-zinc-600 dark:text-zinc-400">{{ $r->texto }}</div>
                                <div class="text-xs text-zinc-500">por {{ $r->usuario }} em {{ data_br($r->criado_em, true) }}</div>
                            </td>
                            <td class="whitespace-nowrap tabular-nums">{{ data_br($r->expira_em) }}</td>
                            <td>
                                @if ($vigente)<span class="selo-verde">no ar</span>
                                @elseif ($r->ativo)<span class="selo-cinza">vencido</span>
                                @else<span class="selo-vermelho">desativado</span>@endif
                            </td>
                            <td>
                                @if ($r->ativo)
                                    <form method="post" action="{{ route('plataforma.recados.desativar', $r->id) }}"
                                          data-confirmar="Retirar este recado do sistema das locadoras?">
                                        @csrf
                                        <button class="btn-secundario">Desativar</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-10 text-center text-zinc-500">Nenhum recado publicado.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
@endsection
