@php
    use App\Support\Locacoes;
    use App\Support\Estoque;
    $aberta = $loc->status === 'aberta';
    $cancelada = $loc->status === 'cancelada';
    $hoje = now()->toDateString();
    $diasHoje = Locacoes::diasCobranca($loc->data_saida, $hoje);
    $atraso = $aberta ? Locacoes::atraso($loc) : 0;
    $saldo = Locacoes::saldo($loc);
    [$rotulo, $selo] = Locacoes::situacao($loc);
    $temRetirada = (bool) array_filter($vistorias, fn ($v) => $v->tipo === 'retirada');
    $novaData = date('Y-m-d', strtotime(max($loc->data_prev_devolucao, $hoje) . ' +1 day'));
    $periodos = ['diaria' => ['Diária', 'dia'], 'semanal' => ['Semanal', 'semana'], 'quinzenal' => ['Quinzenal', 'quinzena'], 'mensal' => ['Mensal', 'mês']];
    $erroEm = fn (string $onde) => $errors->has($onde)
        ? '<p class="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950/50 dark:text-red-300" role="alert">' . e($errors->first($onde)) . '</p>' : '';
    $documento = fn (string $acao, array $extra = []) => legado('locacoes', ['a' => $acao, 'n' => $loc->numero] + $extra);
@endphp
@extends('layouts.app', ['titulo' => 'Locação nº ' . $loc->numero, 'ativo' => 'locacoes', 'migalhas' => [['Locações', route('locacoes.index')]]])

@section('subtitulo')
    <span class="inline-flex flex-wrap items-center gap-2"><span class="{{ $selo }}">{{ $rotulo }}</span>
        <a class="font-medium text-zinc-700 hover:underline dark:text-zinc-300" href="{{ route('clientes.show', $loc->cliente_id) }}">{{ $loc->cliente_nome }}</a>
        @if ($loc->cliente_celular || $loc->cliente_telefone)<span class="text-zinc-400">·</span><span class="tabular-nums">{{ $loc->cliente_celular ?: $loc->cliente_telefone }}</span>@endif
    </span>
@endsection

@section('acoes')
    @if ($aberta)
        <a class="btn-primario" href="{{ $documento('devolucao') }}"><x-icone nome="devolucao" class="size-4" /> Registrar devolução</a>
    @endif
    <a class="btn-secundario" href="{{ route('locacoes.create', ['copiar' => $loc->id]) }}">Repetir locação</a>
@endsection

@section('conteudo')
{{-- Valores: vêm da view vw_locacao_valores do banco (a mesma do sistema antigo) --}}
<div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
    <x-kpi rotulo="Valor Total" :valor="moeda($loc->valor_previsto)" ico="locacoes" cor="bg-sky-50 text-sky-600 dark:bg-sky-500/10 dark:text-sky-400"
           :detalhe="numero(Locacoes::diasCobranca($loc->data_saida, $loc->data_prev_devolucao)) . ' dia(s) combinados'" />
    <x-kpi :rotulo="$aberta ? 'Até hoje' : ($cancelada ? 'Valor (cancelada)' : 'Valor final')" :valor="moeda($loc->valor_atual)" ico="relogio"
           cor="bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300" :detalhe="$aberta ? numero($diasHoje) . ' dia(s) até hoje' : ''" />
    <x-kpi rotulo="Pago" :valor="moeda($loc->valor_pago)" ico="dinheiro" cor="bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400" />
    <x-kpi rotulo="Saldo" :valor="moeda($saldo)" ico="caixa"
           :cor="$saldo > 0.004 ? 'bg-red-50 text-red-600 dark:bg-red-500/10 dark:text-red-400' : 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400'" />
</div>

<div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-3">
    <div class="space-y-6 xl:col-span-2">
        {{-- Itens --}}
        <section class="cartao overflow-hidden">
            <div class="cartao-cabecalho"><h2 class="cartao-titulo">Itens</h2>
                @if ($atraso > 0)<span class="selo-vermelho">{{ numero($atraso) }} dia(s) de atraso</span>@endif</div>
            <div class="overflow-x-auto">
                <table class="tabela">
                    <thead><tr><th>Produto</th><th class="num">Qtd.</th><th class="num">Devolvida</th><th class="num">Pendente</th>
                        <th>Período</th><th class="num">{{ $aberta ? 'Valor até hoje' : 'Valor' }}</th></tr></thead>
                    <tbody>
                    @foreach ($itens as $it)
                        @php
                            $pend = $it->quantidade - $it->qtd_devolvida;
                            $valor = $it->valor_devolvido + ($aberta ? $it->pendente_hoje : 0);
                            $per = $periodos[$it->periodo] ?? null;
                            $preco = $it->periodo === 'diaria' || !$per ? $it->valor_diaria : $it->{'valor_' . $it->periodo};
                        @endphp
                        <tr>
                            <td><span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $it->descricao }}</span>
                                <span class="block font-mono text-xs text-zinc-500">{{ $it->codigo }}</span></td>
                            <td class="num">{{ numero($it->quantidade) }} <span class="text-xs text-zinc-400">{{ $it->unidade }}</span></td>
                            <td class="num {{ $it->qtd_devolvida > 0 ? '' : 'text-zinc-400' }}">{{ numero($it->qtd_devolvida) }}</td>
                            <td class="num">@if ($cancelada)<span class="text-zinc-400">—</span>@elseif ($pend > 0)<span class="{{ $atraso > 0 ? 'selo-vermelho' : 'selo-amarelo' }}">{{ numero($pend) }}</span>@else<span class="text-zinc-400">0</span>@endif</td>
                            <td class="whitespace-nowrap">
                                @if ($per){{ $per[0] }}<span class="block text-xs text-zinc-500">{{ moeda($preco) }}/{{ $per[1] }}</span>
                                @else Automático<span class="block text-xs text-zinc-500">{{ moeda($it->valor_diaria) }}/dia</span>@endif
                            </td>
                            <td class="num whitespace-nowrap font-medium">{{ $cancelada ? '—' : moeda($valor) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        {{-- Patrimônio --}}
        @if ($patrimonio)
            <section class="cartao">
                <div class="cartao-cabecalho"><h2 class="cartao-titulo">Patrimônio (unidades que saíram)</h2>
                    @if ($aberta)<span class="text-xs text-zinc-500">Aparece no contrato. Na devolução, marque quais voltaram.</span>@endif</div>
                <form method="post" action="{{ route('locacoes.unidades', $loc->numero) }}" class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @csrf
                    <div class="px-5 pt-4">{!! $erroEm('patrimonio') !!}</div>
                    @foreach ($patrimonio as $pt)
                        @php $pend = $pt->item->quantidade - $pt->item->qtd_devolvida; @endphp
                        <div class="px-5 py-4">
                            <p class="text-sm font-medium">{{ $pt->item->descricao }}
                                @if ($aberta)<span class="ml-1 text-xs font-normal text-zinc-500">{{ numero($pend) }} na rua · escolha até {{ numero($pend) }}</span>@endif</p>
                            <div class="mt-2 flex flex-wrap gap-2">
                                @if ($aberta)
                                    <input type="hidden" name="unidades[{{ $pt->item->id }}][]" value="">
                                    @foreach ($pt->opcoes as $u)
                                        <label class="cursor-pointer rounded-lg border border-zinc-200 px-2.5 py-1 font-mono text-xs transition has-checked:border-marca-500 has-checked:bg-marca-50 has-checked:text-marca-800
                                                      dark:border-zinc-700 dark:has-checked:border-marca-500 dark:has-checked:bg-marca-500/10 dark:has-checked:text-marca-300">
                                            <input type="checkbox" class="sr-only" name="unidades[{{ $pt->item->id }}][]" value="{{ $u->id }}" @checked($u->marcada)>{{ $u->patrimonio }}
                                        </label>
                                    @endforeach
                                @else
                                    <span class="text-sm text-zinc-600 dark:text-zinc-400">{{ implode(', ', array_column($pt->naRua, 'patrimonio')) ?: '—' }}</span>
                                @endif
                                @foreach ($pt->voltaram as $v)
                                    <span class="selo-verde">{{ $v->patrimonio }} voltou em {{ data_br($v->voltou) }}</span>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                    @if ($aberta)
                        <div class="flex justify-end px-5 py-3"><button class="btn-secundario">Salvar patrimônio</button></div>
                    @endif
                </form>
            </section>
        @endif

        {{-- Dados --}}
        <section class="cartao">
            <div class="cartao-cabecalho"><h2 class="cartao-titulo">Dados da locação</h2></div>
            <dl class="grid grid-cols-1 gap-x-6 gap-y-4 p-5 text-sm sm:grid-cols-2">
                @foreach (array_filter([
                    ['Saída', data_br($loc->data_saida)],
                    ['Devolução prevista', data_br($loc->data_prev_devolucao)],
                    $loc->local_uso !== '' ? ['Local de uso', $loc->local_uso] : null,
                    $loc->endereco_entrega !== '' ? ['Endereço de entrega', $loc->endereco_entrega] : null,
                    ['Acréscimo (montagem etc.)', moeda($loc->acrescimo)],
                    ['Frete', moeda($loc->frete_entrega)],
                    ['Frete de recolhimento', moeda($loc->frete_recolhimento)],
                    ['Desconto', ($loc->desconto > 0 ? '− ' : '') . moeda($loc->desconto)],
                    $reserva ? ['Origem', 'Reserva nº ' . $reserva] : null,
                    ['Registrada em', data_br($loc->criado_em, true)],
                ]) as [$rot, $val])
                    <div><dt class="text-xs text-zinc-500">{{ $rot }}</dt><dd class="mt-0.5 font-medium tabular-nums">{{ $val }}</dd></div>
                @endforeach
                @if ($loc->obs !== '')
                    <div class="sm:col-span-2"><dt class="text-xs text-zinc-500">Observações e histórico</dt>
                        <dd class="mt-1 rounded-lg bg-zinc-50 p-3 text-sm leading-relaxed whitespace-pre-line text-zinc-700 dark:bg-zinc-950 dark:text-zinc-300">{{ $loc->obs }}</dd></div>
                @endif
            </dl>
        </section>

        {{-- Devoluções e pagamentos --}}
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <section class="cartao overflow-hidden">
                <div class="cartao-cabecalho"><h2 class="cartao-titulo">Devoluções</h2></div>
                @if (!$devolucoes)
                    <p class="px-5 py-6 text-sm text-zinc-500">Nenhuma devolução.</p>
                @else
                    <div class="overflow-x-auto"><table class="tabela">
                        <thead><tr><th>Data</th><th>Itens</th><th class="num">Dias</th><th class="num">Valor</th><th class="num">Multa + avaria</th></tr></thead>
                        <tbody>@foreach ($devolucoes as $d)
                            <tr><td class="tabular-nums">{{ data_br($d->data) }}</td>
                                <td>{{ $d->itens }}@if ($d->obs !== '')<span class="block text-xs text-zinc-500">{{ $d->obs }}</span>@endif</td>
                                <td class="num">{{ numero($d->dias) }}</td><td class="num whitespace-nowrap">{{ moeda($d->valor) }}</td>
                                <td class="num whitespace-nowrap">{{ moeda($d->multa + $d->avaria) }}</td></tr>
                        @endforeach</tbody>
                    </table></div>
                @endif
            </section>
            <section class="cartao overflow-hidden">
                <div class="cartao-cabecalho"><h2 class="cartao-titulo">Pagamentos</h2></div>
                @if (!$pagamentos)
                    <p class="px-5 py-6 text-sm text-zinc-500">Nenhum pagamento.</p>
                @else
                    <div class="overflow-x-auto"><table class="tabela">
                        <thead><tr><th>Data</th><th>Descrição</th><th>Forma</th><th class="num">Valor</th></tr></thead>
                        <tbody>@foreach ($pagamentos as $pg)
                            <tr><td class="tabular-nums">{{ data_br($pg->data) }}</td><td>{{ $pg->descricao }}</td><td>{{ $pg->forma }}</td>
                                <td class="num whitespace-nowrap {{ $pg->tipo === 'S' ? 'text-red-600 dark:text-red-400' : '' }}">{{ ($pg->tipo === 'S' ? '− ' : '') . moeda($pg->valor) }}</td></tr>
                        @endforeach</tbody>
                    </table></div>
                @endif
            </section>
        </div>

        {{-- Vistorias --}}
        <section class="cartao overflow-hidden">
            <div class="cartao-cabecalho">
                <h2 class="cartao-titulo">Vistorias (checklist, fotos e assinatura)</h2>
                @unless ($cancelada)
                    <div class="flex gap-2">
                        @unless ($temRetirada)<a class="btn-primario" href="{{ $documento('vistoria', ['tipo' => 'retirada']) }}">Vistoria de retirada</a>@endunless
                        <a class="btn-secundario" href="{{ $documento('vistoria', ['tipo' => 'devolucao']) }}">Vistoria de devolução</a>
                    </div>
                @endunless
            </div>
            @if (!$temRetirada && $aberta)
                <p class="mx-5 mt-4 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-950/40 dark:text-amber-300">Ainda não há vistoria de retirada.
                    Registre o estado dos equipamentos com fotos e a assinatura do cliente: é a sua prova em caso de avaria.</p>
            @endif
            @if (!$vistorias)
                <p class="px-5 py-6 text-sm text-zinc-500">Nenhuma vistoria registrada.</p>
            @else
                <div class="overflow-x-auto"><table class="tabela">
                    <thead><tr><th>Tipo</th><th>Data</th><th>Feita por</th><th class="num">Fotos</th><th>Problemas</th><th>Assinatura</th><th class="w-px"></th></tr></thead>
                    <tbody>@foreach ($vistorias as $v)
                        <tr><td>{{ $v->tipo === 'retirada' ? 'Retirada' : 'Devolução' }}</td>
                            <td class="tabular-nums whitespace-nowrap">{{ data_br($v->criado_em, true) }}</td><td>{{ $v->usuario }}</td>
                            <td class="num">{{ numero($v->fotos) }}</td>
                            <td>@if ($v->problemas > 0)<span class="selo-vermelho">{{ numero($v->problemas) }}</span>@else<span class="selo-verde">nenhum</span>@endif</td>
                            <td>@if ($v->assinada)<span class="selo-verde">{{ $v->assinante_nome }}</span>@else<span class="selo-cinza">sem assinatura</span>@endif</td>
                            <td><a class="btn-secundario whitespace-nowrap" href="{{ legado('locacoes', ['a' => 'vistoria_ver', 'id' => $v->id]) }}" target="_blank" rel="noopener">Ver / imprimir</a></td></tr>
                    @endforeach</tbody>
                </table></div>
            @endif
        </section>
    </div>

    {{-- Coluna de ações --}}
    <div class="space-y-6">
        <section class="cartao p-5">
            <h2 class="cartao-titulo">Documentos</h2>
            <div class="mt-3 grid grid-cols-2 gap-2">
                @foreach ([['Contrato', $documento('contrato')], ['Fatura', $documento('fatura')], ['Romaneio', $documento('romaneio')],
                           ['Romaneio de troca', $documento('romaneio', ['tipo' => 'troca'])]] as [$doc, $url])
                    <a class="btn-secundario justify-center" href="{{ $url }}" target="_blank" rel="noopener">{{ $doc }}</a>
                @endforeach
                <a class="btn-secundario col-span-2 justify-center" href="{{ legado('recibos', ['a' => 'novo', 'locacao' => $loc->id]) }}"><x-icone nome="recibos" class="size-4" /> Emitir recibo</a>
            </div>
        </section>

        @unless ($cancelada)
            <section class="cartao p-5">
                <h2 class="cartao-titulo">WhatsApp do cliente</h2>
                @if ($whats['numero'] === null)
                    <p class="mt-2 text-sm text-zinc-500">Cadastre o celular do cliente para usar o WhatsApp.</p>
                @else
                    <div class="mt-3 flex flex-col gap-2">
                        @foreach (['resumo' => 'Enviar resumo', 'lembrete' => 'Lembrete de devolução', 'saldo' => 'Cobrar saldo'] as $k => $rot)
                            @if (isset($whats['links'][$k]))
                                <a class="btn border border-emerald-200 bg-emerald-50 text-emerald-800 hover:bg-emerald-100 dark:border-emerald-900 dark:bg-emerald-500/10 dark:text-emerald-300"
                                   href="{{ $whats['links'][$k] }}" target="_blank" rel="noopener"><x-icone nome="whatsapp" class="size-4" /> {{ $rot }}</a>
                            @endif
                        @endforeach
                    </div>
                    <p class="mt-2 text-xs text-zinc-500">Abre o WhatsApp com a mensagem pronta; confira e toque em enviar.</p>
                @endif
            </section>

            @if ($pix)
                <section class="cartao p-5" data-pix="{{ $pix['payload'] }}">
                    <h2 class="cartao-titulo">Cobrar por PIX: {{ moeda($pix['valor']) }}</h2>
                    <div class="mt-3 flex flex-col items-center gap-3 sm:flex-row xl:flex-col">
                        <div class="w-40 shrink-0 rounded-xl bg-white p-2" data-pix-qr role="img" aria-label="QR Code PIX de {{ moeda($pix['valor']) }}"></div>
                        <div class="w-full min-w-0">
                            <textarea readonly rows="3" class="campo resize-none font-mono text-xs" aria-label="Código PIX copia e cola" data-pix-codigo>{{ $pix['payload'] }}</textarea>
                            <button type="button" class="btn-secundario mt-2 w-full justify-center" data-pix-copiar>Copiar código</button>
                        </div>
                    </div>
                    <p class="mt-3 text-xs text-zinc-500">Quando o pagamento cair na conta, registre em “Receber pagamento” (forma PIX).</p>
                </section>
            @elseif ($saldo > 0.004 && $semChavePix && session('usuario.perfil') === 'admin')
                <section class="cartao p-5">
                    <h2 class="cartao-titulo">Cobrar por PIX</h2>
                    <p class="mt-2 text-sm text-zinc-500">Cadastre a chave PIX da locadora em <a class="text-marca-700 hover:underline dark:text-marca-400" href="{{ legado('config') }}">Configurações</a>
                        para mostrar aqui o QR Code com o valor do saldo.</p>
                </section>
            @endif

            <section class="cartao p-5" id="receber">
                <h2 class="cartao-titulo">Receber pagamento</h2>
                <form method="post" action="{{ route('locacoes.receber', $loc->numero) }}" class="mt-3 space-y-3">
                    @csrf
                    {!! $erroEm('receber') !!}
                    <label class="block"><span class="text-sm font-medium">Valor</span>
                        <span class="relative mt-1.5 block"><span class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-zinc-400">R$</span>
                            <input type="text" name="valor" value="{{ $errors->has('receber') ? old('valor') : ($saldo > 0 ? numero($saldo, 2) : '') }}" inputmode="decimal" required class="campo pl-9 text-right tabular-nums"></span></label>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="block"><span class="text-sm font-medium">Forma</span>
                            <select name="forma" required class="campo mt-1.5">@foreach (Estoque::FORMAS_PGTO as $f)<option @selected(old('forma', 'PIX') === $f)>{{ $f }}</option>@endforeach</select></label>
                        <label class="block"><span class="text-sm font-medium">Data</span>
                            <input type="date" name="data" value="{{ $errors->has('receber') ? old('data') : $hoje }}" max="{{ $hoje }}" required class="campo mt-1.5"></label>
                    </div>
                    <button class="btn-primario w-full justify-center"><x-icone nome="dinheiro" class="size-4" /> Registrar recebimento</button>
                </form>
            </section>
        @endunless

        @if ($aberta)
            <section class="cartao p-5" id="prorrogar">
                <h2 class="cartao-titulo">Prorrogar</h2>
                <form method="post" action="{{ route('locacoes.prorrogar', $loc->numero) }}" class="mt-3 space-y-3">
                    @csrf
                    {!! $erroEm('prorrogar') !!}
                    <label class="block"><span class="text-sm font-medium">Nova devolução prevista</span>
                        <input type="date" name="data" value="{{ $errors->has('prorrogar') ? old('data') : $novaData }}" min="{{ $loc->data_saida }}" required class="campo mt-1.5"></label>
                    <label class="block"><span class="text-sm font-medium">Motivo</span>
                        <input type="text" name="motivo" value="{{ $errors->has('prorrogar') ? old('motivo') : '' }}" maxlength="200" class="campo mt-1.5"></label>
                    <button class="btn-secundario w-full justify-center">Prorrogar</button>
                </form>
            </section>

            <section class="cartao p-5" id="entrega">
                <h2 class="cartao-titulo">Entrega e recolhimento</h2>
                <form method="post" action="{{ route('locacoes.entrega', $loc->numero) }}" class="mt-3 space-y-3">
                    @csrf
                    {!! $erroEm('entrega') !!}
                    <div class="grid grid-cols-2 gap-3">
                        @foreach (['frete_entrega' => 'Frete', 'frete_recolhimento' => 'Recolhimento'] as $campo => $rot)
                            <label class="block"><span class="text-sm font-medium">{{ $rot }}</span>
                                <span class="relative mt-1.5 block"><span class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-zinc-400">R$</span>
                                    <input type="text" name="{{ $campo }}" value="{{ $errors->has('entrega') ? old($campo) : numero($loc->$campo, 2) }}" inputmode="decimal" class="campo pl-9 text-right tabular-nums"></span></label>
                        @endforeach
                    </div>
                    <label class="block"><span class="text-sm font-medium">Endereço de entrega e recolhimento</span>
                        <input type="text" name="endereco_entrega" value="{{ $errors->has('entrega') ? old('endereco_entrega') : $loc->endereco_entrega }}" maxlength="200" class="campo mt-1.5"></label>
                    <button class="btn-secundario w-full justify-center">Salvar entrega</button>
                </form>
            </section>

            @if ((int) $loc->devolucoes === 0)
                <section class="cartao border-red-200 p-5 dark:border-red-900/60" id="cancelar">
                    <h2 class="text-sm font-semibold text-red-700 dark:text-red-400">Cancelar locação</h2>
                    <form method="post" action="{{ route('locacoes.cancelar', $loc->numero) }}" class="mt-3 space-y-3"
                          data-confirmar="Cancelar a locação nº {{ $loc->numero }}? Os itens voltam ao estoque{{ $loc->valor_pago > 0 ? ' e o valor pago será estornado' : '' }}.">
                        @csrf
                        {!! $erroEm('cancelar') !!}
                        <label class="block"><span class="text-sm font-medium">Motivo</span>
                            <input type="text" name="motivo" maxlength="200" class="campo mt-1.5"></label>
                        @if ($loc->valor_pago > 0)
                            <label class="block"><span class="text-sm font-medium">Estornar {{ moeda($loc->valor_pago) }} via</span>
                                <select name="forma" required class="campo mt-1.5">@foreach (Estoque::FORMAS_PGTO as $f)<option @selected($f === 'PIX')>{{ $f }}</option>@endforeach</select></label>
                        @endif
                        <button class="btn w-full justify-center border border-red-200 bg-white text-red-600 hover:bg-red-50 dark:border-red-900 dark:bg-transparent dark:hover:bg-red-950">Cancelar locação</button>
                    </form>
                </section>
            @endif
        @endif
    </div>
</div>
@endsection
