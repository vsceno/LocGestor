@php
    use App\Http\Controllers\NovaLocacaoController;
    $hoje = now()->toDateString();
    $voltou = (bool) old('_enviado');
    if ($voltou) {
        $clienteTxt = old('cliente');
        $inicio = old('data_inicio', $inicio);
        $fim = old('data_fim', $fim);
        $linhas = [];
        foreach ((array) old('produto_id', []) as $i => $pid) {
            $linhas[] = ['produto_id' => (string) $pid, 'quantidade' => old('quantidade', [])[$i] ?? '', 'periodo' => old('periodo', [])[$i] ?? 'diaria'];
        }
        $linhas = $linhas ?: [['produto_id' => '', 'quantidade' => '1', 'periodo' => 'diaria']];
    }
    $novo = $r === null;
    $migalhas = [['Reservas', route('reservas.index')]];
    if (!$novo) {
        $migalhas[] = ['Nº ' . $r->numero, route('reservas.show', $r->numero)];
    }
@endphp
@extends('layouts.app', ['titulo' => $novo ? 'Nova reserva' : 'Editar reserva nº ' . $r->numero, 'ativo' => 'reservas', 'migalhas' => $migalhas])

@section('subtitulo', 'A reserva bloqueia o estoque no período. Para equipamento que já saiu, registre uma locação.')

@section('conteudo')
<form method="post" action="{{ $novo ? route('reservas.store') : route('reservas.update', $r->numero) }}" autocomplete="off" novalidate data-reserva-form
      data-disponivel="{{ route('locacoes.disponivel') }}" data-reserva-id="{{ $r->id ?? '' }}">
    @csrf
    @unless ($novo) @method('PUT') @endunless
    <input type="hidden" name="_enviado" value="1">

    @error('reserva')
        <div role="alert" class="mb-6 flex gap-3 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/50 dark:text-red-300">
            <x-icone nome="alerta" class="mt-0.5 size-5" /><div><p class="font-medium">A reserva não foi salva.</p><p class="mt-0.5">{{ $message }}</p></div>
        </div>
    @enderror

    <section class="cartao">
        <div class="cartao-cabecalho"><h2 class="cartao-titulo">Cliente e período</h2></div>
        <div class="grid grid-cols-1 gap-5 p-5 md:grid-cols-[1fr_11rem_11rem]">
            <label class="block">
                <span class="text-sm font-medium">Cliente <span class="text-red-500">*</span></span>
                <input type="text" name="cliente" value="{{ $clienteTxt }}" list="lista-clientes" required placeholder="Digite o nome e escolha na lista" class="campo mt-1.5" @if ($novo) autofocus @endif>
                <datalist id="lista-clientes">@foreach ($clientes as $c)<option value="{{ NovaLocacaoController::rotuloCliente($c) }}"></option>@endforeach</datalist>
            </label>
            <label class="block"><span class="text-sm font-medium">Retirada <span class="text-red-500">*</span></span>
                <input type="date" name="data_inicio" value="{{ $inicio }}" min="{{ $hoje }}" required class="campo mt-1.5" data-inicio></label>
            <label class="block"><span class="text-sm font-medium">Devolução <span class="text-red-500">*</span></span>
                <input type="date" name="data_fim" value="{{ $fim }}" min="{{ $hoje }}" required class="campo mt-1.5" data-fim>
                <span class="mt-1 block text-xs text-zinc-500" data-info-dias></span></label>
        </div>
    </section>

    <section class="cartao mt-6">
        <div class="cartao-cabecalho">
            <h2 class="cartao-titulo">Itens reservados</h2>
            @if ($kits)
                <div class="flex flex-wrap items-center gap-2">
                    <select class="campo w-64" aria-label="Kit" data-kit>
                        <option value="">— kit —</option>
                        @foreach ($kits as $id => $k)<option value="{{ $id }}" data-itens="{{ json_encode($k['itens']) }}">{{ $k['nome'] }} ({{ $k['codigo'] }})</option>@endforeach
                    </select>
                    <input type="number" value="1" min="1" max="1000" step="1" class="campo w-20 text-right" aria-label="Quantidade de kits" data-kit-qtd>
                    <button type="button" class="btn-secundario" data-kit-adicionar><x-icone nome="mais" class="size-4" /> Adicionar kit</button>
                </div>
            @endif
        </div>
        <div class="overflow-x-auto">
            <table class="tabela">
                <thead><tr><th>Produto</th><th>Período</th><th class="num">Qtd.</th><th class="num">Disponível no período</th><th class="w-px"></th></tr></thead>
                <tbody data-corpo>
                    @foreach ($linhas as $ln) @include('reservas._linha', ['ln' => $ln]) @endforeach
                </tbody>
            </table>
        </div>
        <template data-modelo>@include('reservas._linha', ['ln' => ['produto_id' => '', 'quantidade' => '1', 'periodo' => 'diaria']])</template>
        <div class="border-t border-zinc-100 px-5 py-3 dark:border-zinc-800">
            <button type="button" class="btn-secundario" data-adicionar><x-icone nome="mais" class="size-4" /> Adicionar item</button>
        </div>
    </section>

    <section class="cartao mt-6">
        <div class="cartao-cabecalho"><h2 class="cartao-titulo">Observações</h2></div>
        <div class="p-5"><textarea name="obs" rows="3" maxlength="2000" class="campo resize-y">{{ $voltou ? old('obs') : ($r->obs ?? '') }}</textarea></div>
    </section>

    <div class="sticky bottom-0 z-10 -mx-4 mt-6 border-t border-zinc-200 bg-white/85 px-4 py-3 backdrop-blur-xl sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8 dark:border-zinc-800 dark:bg-zinc-950/85">
        <div class="flex items-center justify-end gap-2">
            <a class="btn-secundario" href="{{ $novo ? route('reservas.index') : route('reservas.show', $r->numero) }}">Cancelar</a>
            <button type="submit" class="btn-primario"><x-icone nome="ok" class="size-4" /> {{ $novo ? 'Reservar' : 'Salvar alterações' }}</button>
        </div>
    </div>
</form>
@endsection
