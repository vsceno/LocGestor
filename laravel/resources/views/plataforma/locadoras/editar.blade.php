@extends('layouts.plataforma', ['titulo' => $locadora->nome, 'ativo' => 'tenants', 'subtitulo' => 'Dados, plano e mensalidade desta locadora.'])

@section('conteudo')
<div class="mb-6"><a class="btn-fantasma" href="{{ route('plataforma.locadoras') }}">← Voltar para a lista</a></div>

<div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
    <x-kpi rotulo="Status" :valor="$locadora->status" ico="painel"
           :detalhe="$locadora->motivo_suspensao === 'inadimplencia' ? 'suspensa por inadimplência' : ($locadora->motivo_suspensao === 'manual' ? 'suspensa manualmente' : 'acesso liberado')"
           :cor="$locadora->status === 'ativa' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400' : 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-400'" />
    <x-kpi rotulo="Plano" :valor="$locadora->plano_nome ?? '—'" ico="produtos"
           :detalhe="($locadora->preco_mensal !== null ? moeda($locadora->preco_mensal) . '/mês · ' : '') . 'até ' . numero($locadora->limite_usuarios) . ' usuário(s)'"
           cor="bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-400" />
    <x-kpi rotulo="Mensalidade" :valor="$locadora->vencimento ? data_br($locadora->vencimento) : 'sem cobrança'" ico="caixa"
           :detalhe="$situacao[0]" cor="{{ $situacao[1] === 'selo-vermelho' ? 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-400' : 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400' }}" />
</div>

<div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-3">
    <section class="cartao self-start xl:col-span-2">
        <form method="post" action="{{ route('plataforma.locadoras.salvar', $locadora->id) }}">
            @csrf
            @method('PUT')
            <div class="cartao-cabecalho">
                <h2 class="cartao-titulo">Dados e assinatura</h2>
                <span class="text-sm text-zinc-500">Identificador de acesso: <code class="font-mono">{{ $locadora->slug }}</code></span>
            </div>
            <div class="grid gap-4 p-5">
                <label class="block"><span class="text-sm font-medium">Nome <span class="text-red-500">*</span></span>
                    <input type="text" name="nome" value="{{ old('nome', $locadora->nome) }}" required maxlength="150" class="campo mt-1.5">
                </label>
                <div class="grid gap-4 sm:grid-cols-3">
                    <label class="block"><span class="text-sm font-medium">CNPJ</span>
                        <input type="text" name="cnpj" value="{{ old('cnpj', doc_formatar($locadora->cnpj)) }}" maxlength="18" inputmode="numeric" class="campo mt-1.5">
                    </label>
                    <label class="block"><span class="text-sm font-medium">E-mail</span>
                        <input type="email" name="email_contato" value="{{ old('email_contato', $locadora->email_contato) }}" maxlength="120" class="campo mt-1.5">
                    </label>
                    <label class="block"><span class="text-sm font-medium">Telefone</span>
                        <input type="text" name="telefone" value="{{ old('telefone', $locadora->telefone) }}" maxlength="20" class="campo mt-1.5">
                    </label>
                </div>
                @include('plataforma.locadoras._assinatura', ['valores' => (array) $locadora, 'planos' => $planos])
                <div><button class="btn-primario">Salvar</button></div>
            </div>
        </form>
    </section>

    <div class="space-y-6">
        <section class="cartao">
            <div class="cartao-cabecalho"><h2 class="cartao-titulo">Registrar pagamento</h2></div>
            <form method="post" action="{{ route('plataforma.locadoras.pagamento', $locadora->id) }}" class="grid gap-4 p-5">
                @csrf
                <div class="grid grid-cols-2 gap-4">
                    <label class="block"><span class="text-sm font-medium">Meses pagos <span class="text-red-500">*</span></span>
                        <input type="number" name="meses" value="{{ old('meses', 1) }}" min="1" max="24" required class="campo mt-1.5">
                    </label>
                    <label class="block"><span class="text-sm font-medium">Valor (R$)</span>
                        <input type="text" name="valor" value="{{ old('valor') }}" inputmode="decimal"
                               placeholder="{{ $locadora->preco_mensal !== null ? numero($locadora->preco_mensal, 2) . ' por mês' : '' }}" class="campo mt-1.5">
                        <span class="mt-1 block text-xs text-zinc-500">Vazio: preço do plano × meses.</span>
                    </label>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <label class="block"><span class="text-sm font-medium">Data <span class="text-red-500">*</span></span>
                        <input type="date" name="data" value="{{ old('data', now()->toDateString()) }}" max="{{ now()->toDateString() }}" required class="campo mt-1.5">
                    </label>
                    <label class="block"><span class="text-sm font-medium">Forma <span class="text-red-500">*</span></span>
                        <select name="forma" required class="campo mt-1.5">
                            @foreach (\App\Support\Estoque::FORMAS_PGTO as $forma)
                                <option value="{{ $forma }}" @selected(old('forma', 'PIX') === $forma)>{{ $forma }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>
                <label class="block"><span class="text-sm font-medium">Observação</span>
                    <input type="text" name="obs" value="{{ old('obs') }}" maxlength="200" class="campo mt-1.5">
                </label>
                <p class="text-xs text-zinc-500">
                    Avança o vencimento{{ $locadora->vencimento ? ' (hoje ' . data_br($locadora->vencimento) . ')' : '' }}
                    e reativa a locadora se estiver suspensa por inadimplência.
                </p>
                <div><button class="btn-primario">Registrar pagamento</button></div>
            </form>
        </section>

        <section class="cartao">
            <div class="cartao-cabecalho"><h2 class="cartao-titulo">Acesso</h2></div>
            <div class="grid gap-4 p-5 text-sm">
                @if ($locadora->status === 'cancelada')
                    <p class="text-zinc-600 dark:text-zinc-400">Contrato cancelado em {{ data_br($locadora->cancelada_em) }}. O acesso está bloqueado e não volta pelo painel.</p>
                @elseif ($locadora->status === 'ativa')
                    <p class="text-zinc-600 dark:text-zinc-400">O acesso da locadora está liberado.</p>
                    <form method="post" action="{{ route('plataforma.locadoras.status', $locadora->id) }}"
                          data-confirmar="Suspender {{ $locadora->nome }}? Os usuários dela perdem o acesso imediatamente.">
                        @csrf
                        <input type="hidden" name="status" value="suspensa">
                        <button class="btn bg-red-600 text-white shadow-sm hover:bg-red-700">Suspender acesso</button>
                    </form>
                @else
                    <p class="text-zinc-600 dark:text-zinc-400">
                        Acesso suspenso{{ $locadora->motivo_suspensao === 'inadimplencia' ? ' por falta de pagamento' : '' }}.
                    </p>
                    <form method="post" action="{{ route('plataforma.locadoras.status', $locadora->id) }}"
                          data-confirmar="Reativar {{ $locadora->nome }}?">
                        @csrf
                        <input type="hidden" name="status" value="ativa">
                        <button class="btn-primario">Reativar acesso</button>
                    </form>
                @endif
            </div>
        </section>
    </div>
</div>

<section class="cartao mt-6 overflow-hidden">
    <div class="cartao-cabecalho">
        <h2 class="cartao-titulo">Cobrança automática (Asaas)</h2>
        @if ($asaasAtivo)
            <div class="flex flex-wrap gap-2">
                <form method="post" action="{{ route('plataforma.locadoras.cobranca.sincronizar', $locadora->id) }}">
                    @csrf
                    <button class="btn-secundario">Conferir pagamentos</button>
                </form>
                <form method="post" action="{{ route('plataforma.locadoras.cobranca.gerar', $locadora->id) }}">
                    @csrf
                    <button class="btn-primario">Gerar cobrança do vencimento</button>
                </form>
            </div>
        @endif
    </div>
    <div class="grid gap-4 p-5 text-sm">
        @if (! $asaasAtivo)
            <p class="text-zinc-600 dark:text-zinc-400">
                Asaas não configurado: preencha <code class="font-mono">LOCGESTOR_ASAAS_CHAVE</code> no <code class="font-mono">.env</code>.
                Enquanto isso, registre os pagamentos à mão.
            </p>
        @else
            <form method="post" action="{{ route('plataforma.locadoras.cobranca.config', $locadora->id) }}" class="flex flex-wrap items-center gap-3">
                @csrf
                <label class="flex items-center gap-2">
                    <input type="checkbox" name="cobranca_automatica" value="1" @checked($locadora->cobranca_automatica)>
                    Gerar a cobrança no Asaas {{ numero($diasAntes) }} dias antes de cada vencimento
                </label>
                <button class="btn-secundario">Salvar</button>
            </form>
            <p class="text-xs text-zinc-500">
                A locadora paga por PIX, boleto ou cartão na fatura do Asaas (o administrador dela vê o botão <strong>Pagar agora</strong> no sistema).
                A baixa é automática: avança o vencimento e reativa o acesso.
                Ambiente: <strong>{{ $asaasAmbiente === 'producao' ? 'produção' : 'sandbox (testes)' }}</strong>{{ $locadora->asaas_cliente_id !== '' ? ' · cliente ' . $locadora->asaas_cliente_id : '' }}.
            </p>
        @endif

        @if ($cobrancas)
            <div class="overflow-x-auto">
                <table class="tabela">
                    <thead><tr><th>Vencimento</th><th class="num">Valor</th><th>Situação</th><th>Pago em</th><th>Fatura</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($cobrancas as $c)
                            @php $cor = ['pendente' => 'selo-amarelo', 'paga' => 'selo-verde', 'cancelada' => 'selo-cinza'][$c->status] ?? 'selo-cinza'; @endphp
                            <tr>
                                <td class="whitespace-nowrap tabular-nums">{{ data_br($c->vencimento) }}</td>
                                <td class="num">{{ moeda($c->valor) }}</td>
                                <td><span class="{{ $cor }}">{{ $c->gateway_id === null ? 'criando' : $c->status }}</span></td>
                                <td class="whitespace-nowrap tabular-nums">{{ $c->pago_em ? data_br($c->pago_em) : '' }}</td>
                                <td>
                                    @if (($c->link ?? '') !== '')
                                        <a class="text-marca-700 hover:underline dark:text-marca-400" href="{{ $c->link }}" target="_blank" rel="noopener noreferrer">abrir</a>
                                    @endif
                                </td>
                                <td>
                                    @if ($c->status === 'pendente' && $asaasAtivo)
                                        <form method="post" action="{{ route('plataforma.locadoras.cobranca.cancelar', $locadora->id) }}"
                                              data-confirmar="Cancelar esta cobrança no Asaas? A locadora não poderá mais pagá-la.">
                                            @csrf
                                            <input type="hidden" name="cobranca" value="{{ $c->id }}">
                                            <button class="btn bg-red-600 text-white shadow-sm hover:bg-red-700">Cancelar</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</section>

<section class="cartao mt-6">
    <div class="cartao-cabecalho"><h2 class="cartao-titulo">Acesso de suporte</h2>
        <span class="text-sm text-zinc-500">Somente leitura, por {{ \App\Support\Suporte::SESSAO_MIN }} minutos. Fica registrado na auditoria da locadora.</span>
    </div>
    <div class="grid gap-4 p-5 text-sm">
        @if ($locadora->status === 'ativa')
            <p class="text-zinc-600 dark:text-zinc-400">
                Abre o sistema desta locadora como ela vê, somente leitura. A locadora vê a faixa no topo enquanto o acesso durar.
            </p>
            <form method="post" action="{{ route('plataforma.locadoras.suporte', $locadora->id) }}" class="grid gap-3 sm:max-w-xl"
                  data-confirmar="Abrir o sistema de {{ $locadora->nome }} em modo somente leitura? Fica registrado na auditoria da locadora.">
                @csrf
                <label class="block"><span class="text-sm font-medium">Motivo do acesso <span class="text-red-500">*</span></span>
                    <input type="text" name="motivo" value="{{ old('motivo') }}" required minlength="10" maxlength="300"
                           placeholder="Ex.: cliente relatou erro no contrato nº 123 (chamado 45)" class="campo mt-1.5">
                </label>
                <div><button class="btn-secundario">Entrar como a locadora</button></div>
            </form>
        @else
            <p class="text-zinc-600 dark:text-zinc-400">Só é possível abrir o acesso de suporte em locadora ativa.</p>
        @endif

        @if ($acessos)
            <div class="overflow-x-auto">
                <table class="tabela">
                    <thead><tr><th>Quando</th><th>Suporte</th><th>Motivo</th><th>Situação</th></tr></thead>
                    <tbody>
                        @foreach ($acessos as $a)
                            <tr>
                                <td class="whitespace-nowrap tabular-nums">{{ data_br($a->criado_em, true) }}</td>
                                <td>{{ $a->login_suporte }}</td>
                                <td>{{ $a->motivo }}</td>
                                <td>
                                    @if ($a->usado_em)<span class="selo-cinza">aberto em {{ data_br($a->usado_em, true) }}</span>
                                    @else<span class="text-zinc-500">não usado</span>@endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</section>

<section class="cartao mt-6 overflow-hidden">
    <div class="cartao-cabecalho">
        <h2 class="cartao-titulo">Pagamentos</h2>
        {{-- Só o último pagamento não estornado pode ser estornado --}}
        @php $vigente = collect($pagamentos)->firstWhere('estornado_em', null); @endphp
        @if ($vigente)
            <form method="post" action="{{ route('plataforma.locadoras.estornar', $locadora->id) }}"
                  data-confirmar="Estornar o último pagamento? O vencimento volta para {{ data_br($vigente->vencimento_anterior) }}. O recibo continua registrado, marcado como estornado.">
                @csrf
                <button class="btn-secundario">Estornar o último</button>
            </form>
        @endif
    </div>
    <div class="overflow-x-auto">
        <table class="tabela">
            <thead><tr><th>Data</th><th class="num">Meses</th><th class="num">Valor</th><th>Forma</th><th>Vencimento</th><th>Registrado por</th><th>Obs. e recibo</th></tr></thead>
            <tbody>
                @forelse ($pagamentos as $p)
                    <tr class="{{ $p->estornado_em ? 'text-zinc-400' : '' }}">
                        <td class="whitespace-nowrap tabular-nums">{{ data_br($p->data) }}</td>
                        <td class="num">{{ numero($p->meses) }}</td>
                        <td class="num">{{ moeda($p->valor) }}</td>
                        <td>{{ $p->forma }}</td>
                        <td class="whitespace-nowrap tabular-nums">{{ data_br($p->vencimento_anterior) }} → {{ data_br($p->vencimento_novo) }}</td>
                        <td>{{ $p->usuario ?? '' }}</td>
                        <td>
                            {{ $p->obs }}
                            @if ($p->recibo_numero)
                                <a href="{{ route('plataforma.locadoras.recibo', [$locadora->id, $p->id]) }}" target="_blank" rel="noopener"
                                   class="text-marca-700 underline dark:text-marca-400">Recibo nº {{ $p->recibo_numero }}</a>
                            @endif
                            @if ($p->estornado_em)
                                <span class="selo-cinza">estornado em {{ data_br($p->estornado_em) }}</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-zinc-500">Nenhum pagamento registrado.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

<section class="cartao mt-6 overflow-hidden">
    <div class="cartao-cabecalho">
        <div>
            <h2 class="cartao-titulo">Senhas dos usuários</h2>
            <p class="mt-1 text-xs text-zinc-500">
                Gera uma senha temporária para quem esqueceu. Ao entrar com ela, o usuário troca a senha, e as sessões que ele já tinha deixam de valer.
            </p>
        </div>
    </div>
    @if (session('senhaRedefinida'))
        <div role="status" class="border-b border-amber-200 bg-amber-50 px-5 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-300">
            Senha temporária de <strong>{{ session('senhaRedefinida.login') }}</strong>:
            <code class="font-mono font-semibold">{{ session('senhaRedefinida.senha') }}</code>
            <span class="text-xs">(anote e envie agora; ela não aparece de novo)</span>
        </div>
    @endif
    <div class="overflow-x-auto">
        <table class="tabela">
            <thead><tr><th>Usuário</th><th>Perfil</th><th>Situação</th><th>Último acesso</th><th></th></tr></thead>
            <tbody>
                @forelse ($usuarios as $u)
                    <tr>
                        <td>{{ $u->nome }} <code class="font-mono text-xs">{{ $u->login }}</code></td>
                        <td>{{ $u->perfil === 'admin' ? 'Administrador' : 'Operador' }}</td>
                        <td>@if ($u->ativo)<span class="selo-verde">ativo</span>@else<span class="selo-cinza">inativo</span>@endif</td>
                        <td class="whitespace-nowrap tabular-nums">{{ $u->ultimo_acesso ? data_br($u->ultimo_acesso, true) : 'nunca' }}</td>
                        <td>
                            @if ($u->ativo && $locadora->status !== 'cancelada')
                                <form method="post" action="{{ route('plataforma.locadoras.senha', $locadora->id) }}"
                                      data-confirmar="Gerar uma senha temporária para {{ $u->login }}? As sessões abertas dele deixam de valer.">
                                    @csrf
                                    <input type="hidden" name="usuario" value="{{ $u->id }}">
                                    <button class="btn-secundario">Redefinir senha</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-10 text-center text-zinc-500">Nenhum usuário.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

@if ($locadora->status !== 'cancelada')
    <section class="cartao mt-6">
        <div class="cartao-cabecalho"><h2 class="cartao-titulo">Contrato</h2></div>
        <div class="flex flex-wrap items-center justify-between gap-3 p-5 text-sm">
            <p class="max-w-2xl text-zinc-600 dark:text-zinc-400">
                Cancelar o contrato bloqueia o acesso de forma definitiva pelo painel e cancela as mensalidades em aberto no Asaas.
            </p>
            <form method="post" action="{{ route('plataforma.locadoras.cancelar', $locadora->id) }}"
                  data-confirmar="Cancelar o contrato de {{ $locadora->nome }}? O acesso é bloqueado, as mensalidades em aberto são canceladas e a locadora não volta pelo painel.">
                @csrf
                <button class="btn bg-red-600 text-white shadow-sm hover:bg-red-700">Cancelar contrato</button>
            </form>
        </div>
    </section>
@endif
@endsection
