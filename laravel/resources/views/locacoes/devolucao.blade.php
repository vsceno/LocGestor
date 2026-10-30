@php
    use App\Support\Estoque;
    $hoje = now()->toDateString();
    $voltou = (bool) old('_enviado');
    $qtdOld = (array) old('qtd', []);
    $perdaOld = (array) old('perda', []);
    $unidOld = $voltou ? array_map('strval', (array) old('unidades', [])) : null;
    $periodos = ['diaria' => ['Diária', 'dia'], 'semanal' => ['Semanal', 'semana'], 'quinzenal' => ['Quinzenal', 'quinzena'], 'mensal' => ['Mensal', 'mês']];
@endphp
@extends('layouts.app', ['titulo' => 'Devolução', 'ativo' => 'locacoes',
    'migalhas' => [['Locações', route('locacoes.index')], ['Nº ' . $loc->numero, route('locacoes.show', $loc->numero)]]])

@section('subtitulo', 'Locação nº ' . $loc->numero . ' · ' . $loc->cliente_nome)

@section('conteudo')
<form method="post" action="{{ route('locacoes.devolver', $loc->numero) }}" autocomplete="off" novalidate data-devolucao
      data-saida="{{ $loc->data_saida }}" data-prev="{{ $loc->data_prev_devolucao }}" data-apurado="{{ $apurado }}" data-ajuste="{{ $ajuste }}" data-pago="{{ $loc->valor_pago }}">
    @csrf
    <input type="hidden" name="_enviado" value="1">

    @error('devolucao')
        <div role="alert" class="mb-6 flex gap-3 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/50 dark:text-red-300">
            <x-icone nome="alerta" class="mt-0.5 size-5" /><div><p class="font-medium">A devolução não foi registrada.</p><p class="mt-0.5">{{ $message }}</p></div>
        </div>
    @enderror

    <section class="cartao">
        <div class="grid grid-cols-2 gap-5 p-5 sm:grid-cols-4">
            <label class="col-span-2 block sm:col-span-1">
                <span class="text-sm font-medium">Data da devolução <span class="text-red-500">*</span></span>
                <input type="date" name="data" value="{{ old('data', $hoje) }}" min="{{ $loc->data_saida }}" max="{{ $hoje }}" required class="campo mt-1.5" data-data>
            </label>
            <div><p class="text-xs text-zinc-500">Saída</p><p class="mt-1.5 font-medium tabular-nums">{{ data_br($loc->data_saida) }}</p></div>
            <div><p class="text-xs text-zinc-500">Prevista</p><p class="mt-1.5 font-medium tabular-nums">{{ data_br($loc->data_prev_devolucao) }}</p></div>
            <div><p class="text-xs text-zinc-500">Dias cobrados</p><p class="mt-1.5 font-medium tabular-nums"><span data-dias>{{ $dias }}</span>
                <span class="ml-1 text-sm text-red-600 dark:text-red-400" data-atraso></span></p></div>
        </div>
    </section>

    <section class="cartao mt-6 overflow-hidden">
        <div class="cartao-cabecalho"><h2 class="cartao-titulo">Itens a devolver</h2>
            <span class="text-xs text-zinc-500">“Perdido” não volta: sai do estoque e é cobrado pelo valor de reposição.</span></div>
        <div class="overflow-x-auto">
            <table class="tabela">
                <thead><tr><th>Produto</th><th class="num">Pendente</th><th class="num">Devolver agora</th><th class="num">Perdido</th><th>Período</th><th class="num">Valor</th></tr></thead>
                <tbody>
                @foreach ($itens as $it)
                    @php
                        $pend = $it->quantidade - $it->qtd_devolvida;
                        $per = $periodos[$it->periodo] ?? null;
                        $preco = $it->periodo === 'diaria' || !$per ? $it->valor_diaria : $it->{'valor_' . $it->periodo};
                    @endphp
                    <tr data-linha data-diaria="{{ $it->valor_diaria }}" data-semanal="{{ $it->valor_semanal }}" data-quinzenal="{{ $it->valor_quinzenal }}"
                        data-mensal="{{ $it->valor_mensal }}" data-periodo="{{ $it->periodo }}" data-reposicao="{{ $it->valor_reposicao }}">
                        <td>
                            <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $it->descricao }}</span>
                            @if (!empty($naRua[$it->id]))
                                <div class="mt-2 flex flex-wrap items-center gap-2"><span class="text-xs text-zinc-500">Voltaram:</span>
                                    @foreach ($naRua[$it->id] as $u)
                                        <label class="cursor-pointer rounded-lg border border-zinc-200 px-2 py-0.5 font-mono text-xs has-checked:border-marca-500 has-checked:bg-marca-50 has-checked:text-marca-800
                                                      dark:border-zinc-700 dark:has-checked:bg-marca-500/10 dark:has-checked:text-marca-300">
                                            <input type="checkbox" class="sr-only" name="unidades[]" value="{{ $u->unidade_id }}" @checked($unidOld === null || in_array($u->unidade_id, $unidOld, true))>{{ $u->patrimonio }}
                                        </label>
                                    @endforeach
                                </div>
                            @endif
                        </td>
                        <td class="num">{{ numero($pend) }} <span class="text-xs text-zinc-400">{{ $it->unidade }}</span></td>
                        <td class="num"><input type="number" name="qtd[{{ $it->id }}]" value="{{ $qtdOld[$it->id] ?? $pend }}" min="0" max="{{ $pend }}" step="1"
                                               aria-label="Quantidade a devolver de {{ $it->descricao }}" class="campo ml-auto w-24 text-right tabular-nums" data-qtd></td>
                        <td class="num"><input type="number" name="perda[{{ $it->id }}]" value="{{ $perdaOld[$it->id] ?? '' }}" min="0" max="{{ $pend }}" step="1"
                                               aria-label="Quantidade perdida de {{ $it->descricao }}" title="Não voltou: cobra o valor de reposição ({{ moeda($it->valor_reposicao) }})"
                                               class="campo ml-auto w-24 text-right tabular-nums" data-perda></td>
                        <td class="whitespace-nowrap">@if ($per){{ $per[0] }}<span class="block text-xs text-zinc-500">{{ moeda($preco) }}/{{ $per[1] }}</span>
                            @else Automático<span class="block text-xs text-zinc-500">{{ moeda($it->valor_diaria) }}/dia</span>@endif</td>
                        <td class="num whitespace-nowrap font-medium" data-valor>{{ moeda($pend * $it->preco_hoje) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-3">
        <section class="cartao xl:col-span-2">
            <div class="cartao-cabecalho"><h2 class="cartao-titulo">Multa, avaria e pagamento</h2></div>
            <div class="grid grid-cols-2 gap-5 p-5">
                @foreach (['multa' => 'Multa', 'avaria' => 'Avaria'] as $campo => $rot)
                    <label class="block"><span class="text-sm font-medium">{{ $rot }}</span>
                        <span class="relative mt-1.5 block"><span class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-zinc-400">R$</span>
                            <input type="text" name="{{ $campo }}" value="{{ old($campo) }}" inputmode="decimal" placeholder="0,00" class="campo pl-9 text-right tabular-nums" data-{{ $campo }}></span></label>
                @endforeach
                <label class="col-span-2 block"><span class="text-sm font-medium">Observação (estado dos equipamentos)</span>
                    <textarea name="obs" rows="3" maxlength="2000" class="campo mt-1.5 resize-y">{{ old('obs') }}</textarea></label>
                <label class="block"><span class="text-sm font-medium">Receber agora</span>
                    <span class="relative mt-1.5 block"><span class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-zinc-400">R$</span>
                        <input type="text" name="receber" value="{{ $voltou ? old('receber') : ($sugerido > 0 ? numero($sugerido, 2) : '') }}" inputmode="decimal" class="campo pl-9 text-right tabular-nums" data-receber></span></label>
                <label class="block"><span class="text-sm font-medium">Forma</span>
                    <select name="forma" class="campo mt-1.5"><option value="">—</option>@foreach (Estoque::FORMAS_PGTO as $f)<option @selected(old('forma', 'PIX') === $f)>{{ $f }}</option>@endforeach</select></label>
            </div>
        </section>
        <section class="cartao">
            <div class="cartao-cabecalho"><h2 class="cartao-titulo">Resumo</h2></div>
            <dl class="space-y-2 p-5 text-sm">
                @foreach ([['Desta devolução', 'desta', '—'], ['Já apurado antes', null, moeda($apurado)], ['Acréscimos e fretes − desconto', null, moeda($ajuste)],
                           ['Total apurado', 'total', '—'], ['Já pago', null, moeda($loc->valor_pago)]] as [$rot, $chave, $val])
                    <div class="flex justify-between gap-3"><dt class="text-zinc-500">{{ $rot }}</dt><dd class="tabular-nums" @if ($chave) data-r-{{ $chave }} @endif>{{ $val }}</dd></div>
                @endforeach
                <div class="flex justify-between gap-3 border-t border-zinc-100 pt-3 text-base dark:border-zinc-800"><dt class="font-semibold">Saldo</dt><dd class="font-semibold tabular-nums" data-r-saldo>—</dd></div>
            </dl>
            <p class="px-5 pb-5 text-xs text-amber-700 dark:text-amber-400" data-r-aviso></p>
        </section>
    </div>

    <div class="sticky bottom-0 z-10 -mx-4 mt-6 border-t border-zinc-200 bg-white/85 px-4 py-3 backdrop-blur-xl sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8 dark:border-zinc-800 dark:bg-zinc-950/85">
        <div class="flex items-center justify-end gap-2">
            <a class="btn-secundario" href="{{ route('locacoes.show', $loc->numero) }}">Voltar</a>
            <button type="submit" class="btn-primario"><x-icone nome="devolucao" class="size-4" /> Confirmar devolução</button>
        </div>
    </div>
</form>
@endsection
