@php
    use App\Http\Controllers\NovaLocacaoController;
    use App\Support\Estoque;
    $hoje = now()->toDateString();
    $voltou = (bool) old('_enviado');
    // Depois de um erro, volta exatamente o que foi digitado
    if ($voltou) {
        $clienteTxt = old('cliente');
        $saida = old('data_saida', $saida);
        $prev = old('data_prev_devolucao', $prev);
        $linhas = [];
        foreach ((array) old('produto_id', []) as $i => $pid) {
            $linhas[] = ['produto_id' => (string) $pid, 'quantidade' => old('quantidade', [])[$i] ?? '', 'diaria' => old('diaria', [])[$i] ?? '',
                         'prev' => old('prev', [])[$i] ?? '', 'periodo' => old('periodo', [])[$i] ?? 'diaria'];
        }
        $linhas = $linhas ?: [['produto_id' => '', 'quantidade' => '1', 'diaria' => '', 'prev' => $prev, 'periodo' => 'diaria']];
    }
    $v = fn (string $campo, string $padrao = '') => $voltou ? (string) old($campo) : $padrao;
    $reais = 'campo pl-9 text-right tabular-nums';
@endphp
@extends('layouts.app', ['titulo' => 'Nova locação', 'ativo' => 'locacoes', 'migalhas' => [['Locações', route('locacoes.index')]]])

@section('subtitulo', 'Registre o que está saindo agora. Para garantir equipamentos para outra data, faça uma reserva.')

@section('conteudo')
<form method="post" action="{{ route('locacoes.store') }}" autocomplete="off" novalidate data-nova-locacao
      data-disponivel="{{ route('locacoes.disponivel') }}">
    @csrf
    <input type="hidden" name="_enviado" value="1">
    <input type="hidden" name="reserva_id" value="{{ $voltou ? old('reserva_id') : ($reserva->id ?? '') }}" data-reserva>
    <input type="hidden" name="data_prev_devolucao" value="{{ $prev }}" data-prev-geral>

    @error('locacao')
        <div role="alert" class="mb-6 flex gap-3 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/50 dark:text-red-300">
            <x-icone nome="alerta" class="mt-0.5 size-5" /><div><p class="font-medium">A locação não foi registrada.</p><p class="mt-0.5">{{ $message }}</p></div>
        </div>
    @enderror
    @if ($reserva)
        <div class="mb-6 rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-800 dark:border-sky-900 dark:bg-sky-950/40 dark:text-sky-300">
            Retirada da <strong>reserva nº {{ $reserva->numero }}</strong>: ao registrar, a reserva será marcada como convertida.
        </div>
    @endif

    {{-- Cliente --}}
    <section class="cartao">
        <div class="cartao-cabecalho"><h2 class="cartao-titulo">Cliente</h2></div>
        <div class="grid grid-cols-1 gap-5 p-5 md:grid-cols-[1fr_1fr_11rem]">
            <label class="block">
                <span class="text-sm font-medium">Cliente <span class="text-red-500">*</span></span>
                <input type="text" name="cliente" value="{{ $clienteTxt }}" list="lista-clientes" required placeholder="Digite o nome e escolha na lista" class="campo mt-1.5" @if ($clienteTxt === '') autofocus @endif>
                <datalist id="lista-clientes">@foreach ($clientes as $c)<option value="{{ NovaLocacaoController::rotuloCliente($c) }}"></option>@endforeach</datalist>
                <span class="mt-1 block text-xs text-zinc-500">Clientes bloqueados não aparecem. <a class="text-marca-700 hover:underline dark:text-marca-400" href="{{ route('clientes.create') }}">Cadastrar cliente</a></span>
            </label>
            <label class="block">
                <span class="text-sm font-medium">Local de uso (obra/evento)</span>
                <input type="text" name="local_uso" value="{{ $v('local_uso', $copia['local_uso']) }}" maxlength="200" class="campo mt-1.5">
                <span class="mt-1 block text-xs text-zinc-500">É também o endereço da entrega.</span>
            </label>
            <label class="block">
                <span class="text-sm font-medium">Data de saída <span class="text-red-500">*</span></span>
                <input type="date" name="data_saida" value="{{ $saida }}" max="{{ $hoje }}" required class="campo mt-1.5" data-saida>
            </label>
        </div>
    </section>

    {{-- Itens --}}
    <section class="cartao mt-6">
        <div class="cartao-cabecalho">
            <h2 class="cartao-titulo">Itens</h2>
            <span class="text-xs text-zinc-500">O valor é o do período escolhido; os dias que sobram são cobrados na diária.</span>
        </div>
        <div class="overflow-x-auto">
            <table class="tabela min-w-[60rem]">
                <thead><tr>
                    <th>Produto</th><th>Período</th><th>Dias</th><th class="num">Qtd.</th><th class="num">Valor</th>
                    <th class="num">Disponível</th><th>Devolução</th><th class="num">Subtotal</th><th class="w-px"></th>
                </tr></thead>
                <tbody data-itens>
                    @foreach ($linhas as $ln)
                        @include('locacoes._linha', ['ln' => $ln])
                    @endforeach
                </tbody>
            </table>
        </div>
        <template data-modelo>@include('locacoes._linha', ['ln' => ['produto_id' => '', 'quantidade' => '1', 'diaria' => '', 'prev' => '', 'periodo' => 'diaria']])</template>
        <div class="border-t border-zinc-100 px-5 py-3 dark:border-zinc-800">
            <button type="button" class="btn-secundario" data-adicionar><x-icone nome="mais" class="size-4" /> Adicionar item</button>
        </div>
    </section>

    <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-3">
        {{-- Valores --}}
        <section class="cartao xl:col-span-2">
            <div class="cartao-cabecalho"><h2 class="cartao-titulo">Valores e pagamento</h2></div>
            <div class="grid grid-cols-2 gap-5 p-5 md:grid-cols-3">
                @foreach (['acrescimo' => 'Acréscimo (montagem etc.)', 'frete_entrega' => 'Frete', 'desconto' => 'Desconto'] as $campo => $rot)
                    <label class="block"><span class="text-sm font-medium">{{ $rot }}</span>
                        <span class="relative mt-1.5 block"><span class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-zinc-400">R$</span>
                            <input type="text" name="{{ $campo }}" value="{{ $v($campo, $campo === 'frete_entrega' ? $copia['frete_entrega'] : '') }}" inputmode="decimal" placeholder="0,00" class="{{ $reais }}" data-valor="{{ $campo }}"></span></label>
                @endforeach
                @unless ($admin)
                    <label class="col-span-2 block md:col-span-3"><span class="text-sm font-medium">Senha do administrador</span>
                        <input type="password" name="senha_admin" autocomplete="new-password" placeholder="Só com desconto ou acréscimo" class="campo mt-1.5 max-w-sm">
                        <span class="mt-1 block text-xs text-zinc-500">Quem não é administrador precisa dela para dar desconto ou acréscimo.</span></label>
                @endunless
                <label class="block"><span class="text-sm font-medium">Adiantamento recebido</span>
                    <span class="relative mt-1.5 block"><span class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-zinc-400">R$</span>
                        <input type="text" name="adiantamento" value="{{ $v('adiantamento') }}" inputmode="decimal" placeholder="0,00" class="{{ $reais }}"></span></label>
                <label class="block"><span class="text-sm font-medium">Forma de pagamento</span>
                    <select name="forma" class="campo mt-1.5"><option value="">—</option>@foreach (Estoque::FORMAS_PGTO as $f)<option @selected(($voltou ? old('forma') : 'PIX') === $f)>{{ $f }}</option>@endforeach</select></label>
            </div>
        </section>
        <section class="cartao">
            <div class="cartao-cabecalho"><h2 class="cartao-titulo">Observações</h2></div>
            <div class="p-5"><textarea name="obs" rows="5" maxlength="2000" class="campo resize-y">{{ $v('obs', $copia['obs']) }}</textarea></div>
        </section>
    </div>

    {{-- Total e salvar, sempre à vista --}}
    <div class="sticky bottom-0 z-10 -mx-4 mt-6 border-t border-zinc-200 bg-white/85 px-4 py-3 backdrop-blur-xl sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8 dark:border-zinc-800 dark:bg-zinc-950/85">
        <div class="flex flex-wrap items-center justify-end gap-x-6 gap-y-2">
            <p class="mr-auto text-sm text-zinc-500">Valor Total <strong class="ml-1 text-xl font-semibold tabular-nums text-zinc-900 dark:text-zinc-100" data-total>R$ 0,00</strong>
                <span class="ml-1" data-total-detalhe></span></p>
            <a class="btn-secundario" href="{{ route('locacoes.index') }}">Cancelar</a>
            <button type="submit" class="btn-primario"><x-icone nome="ok" class="size-4" /> Registrar locação</button>
        </div>
    </div>
</form>
@endsection
