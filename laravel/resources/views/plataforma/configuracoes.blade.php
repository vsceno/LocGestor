@extends('layouts.plataforma', ['titulo' => 'Configurações da LocGestor', 'ativo' => 'configuracoes', 'subtitulo' => 'Dados da empresa, identidade, documentos, cadastro público e suporte. Cada seção salva só o que está nela, e toda alteração fica na auditoria.'])

@section('conteudo')
<div class="grid grid-cols-1 gap-6 xl:grid-cols-2">

    {{-- 1. Dados da empresa: aparecem nos recibos --}}
    <section class="cartao xl:col-span-1">
        <div class="cartao-cabecalho"><h2 class="cartao-titulo">Dados da empresa</h2></div>
        <form method="post" action="{{ route('plataforma.configuracoes.empresa') }}" class="space-y-4 p-5">
            @csrf
            @method('PUT')
            <p class="text-sm text-zinc-500">
                Aparecem nos recibos da plataforma. Sem razão social e CNPJ, nenhum recibo é emitido.
                @if (\App\Support\Configuracoes::emitenteCompleto())
                    <span class="selo-verde ml-1">dados completos</span>
                @else
                    <span class="selo-amarelo ml-1">incompleto</span>
                @endif
            </p>
            <label class="block">
                <span class="text-sm font-medium">Razão social</span>
                <input type="text" name="emitente_razao" value="{{ old('emitente_razao', $c->emitente_razao) }}" maxlength="150" required class="campo mt-1.5">
                @error('emitente_razao', 'empresa')<span class="mt-1 block text-sm text-red-600">{{ $message }}</span>@enderror
            </label>
            <label class="block">
                <span class="text-sm font-medium">CNPJ</span>
                <input type="text" name="emitente_cnpj" value="{{ old('emitente_cnpj', $c->emitente_cnpj !== '' ? doc_formatar($c->emitente_cnpj) : '') }}"
                       maxlength="18" inputmode="numeric" placeholder="00.000.000/0000-00" class="campo mt-1.5 font-mono">
                @error('emitente_cnpj', 'empresa')<span class="mt-1 block text-sm text-red-600">{{ $message }}</span>@enderror
            </label>
            <label class="block">
                <span class="text-sm font-medium">Endereço</span>
                <input type="text" name="emitente_endereco" value="{{ old('emitente_endereco', $c->emitente_endereco) }}" maxlength="200" class="campo mt-1.5">
                @error('emitente_endereco', 'empresa')<span class="mt-1 block text-sm text-red-600">{{ $message }}</span>@enderror
            </label>
            <label class="block">
                <span class="text-sm font-medium">Contato (telefone e e-mail)</span>
                <input type="text" name="emitente_contato" value="{{ old('emitente_contato', $c->emitente_contato) }}" maxlength="200" class="campo mt-1.5">
                @error('emitente_contato', 'empresa')<span class="mt-1 block text-sm text-red-600">{{ $message }}</span>@enderror
            </label>
            <button class="btn-primario">Salvar dados da empresa</button>
        </form>
    </section>

    {{-- 2. Identidade visual --}}
    <section class="cartao">
        <div class="cartao-cabecalho"><h2 class="cartao-titulo">Identidade visual</h2></div>
        <form method="post" action="{{ route('plataforma.configuracoes.identidade') }}" enctype="multipart/form-data" class="space-y-4 p-5">
            @csrf
            @method('PUT')
            <div class="flex items-center gap-4">
                <img src="{{ \App\Support\Configuracoes::logoUrl() }}" alt="Logomarca atual" class="size-20 rounded-xl border border-zinc-200 bg-white object-contain p-1 dark:border-zinc-700">
                <p class="text-sm text-zinc-500">
                    Logomarca atual. {{ \App\Support\Configuracoes::logoEnviada() ? 'Enviada por você.' : 'Padrão do sistema (nenhuma enviada).' }}
                </p>
            </div>
            <label class="block">
                <span class="text-sm font-medium">Nome do sistema</span>
                <input type="text" name="nome_sistema" value="{{ old('nome_sistema', $c->nome_sistema) }}" maxlength="40" required class="campo mt-1.5">
                @error('nome_sistema', 'identidade')<span class="mt-1 block text-sm text-red-600">{{ $message }}</span>@enderror
            </label>
            <label class="block">
                <span class="text-sm font-medium">Nova logomarca</span>
                <input type="file" name="logo" accept="image/png,image/jpeg,image/webp" class="mt-1.5 block w-full text-sm">
                <span class="mt-1 block text-xs text-zinc-500">PNG, JPG ou WEBP, até 2 MB. A imagem é redesenhada em PNG de até 512 px, sem dados escondidos.</span>
                @error('logo', 'identidade')<span class="mt-1 block text-sm text-red-600">{{ $message }}</span>@enderror
            </label>
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="remover_logo" value="1" class="rounded">
                Voltar para a logomarca padrão
            </label>
            <button class="btn-primario">Salvar identidade</button>
        </form>
    </section>

    {{-- 3. Documentos --}}
    <section class="cartao">
        <div class="cartao-cabecalho"><h2 class="cartao-titulo">Documentos</h2></div>
        <form method="post" action="{{ route('plataforma.configuracoes.documentos') }}" class="space-y-4 p-5">
            @csrf
            @method('PUT')
            <label class="block">
                <span class="text-sm font-medium">Termos de uso (endereço https)</span>
                <input type="url" name="termos_url" value="{{ old('termos_url', $c->termos_url) }}" maxlength="300" placeholder="https://..." class="campo mt-1.5">
                <span class="mt-1 block text-xs text-zinc-500">Sem este endereço, o cadastro público não abre.</span>
                @error('termos_url', 'documentos')<span class="mt-1 block text-sm text-red-600">{{ $message }}</span>@enderror
            </label>
            <label class="block">
                <span class="text-sm font-medium">Política de privacidade (endereço https)</span>
                <input type="url" name="privacidade_url" value="{{ old('privacidade_url', $c->privacidade_url) }}" maxlength="300" placeholder="https://..." class="campo mt-1.5">
                @error('privacidade_url', 'documentos')<span class="mt-1 block text-sm text-red-600">{{ $message }}</span>@enderror
            </label>
            <label class="block">
                <span class="text-sm font-medium">Observação padrão do recibo</span>
                <textarea name="observacao_recibo" rows="2" maxlength="300" class="campo mt-1.5">{{ old('observacao_recibo', $c->observacao_recibo) }}</textarea>
                @error('observacao_recibo', 'documentos')<span class="mt-1 block text-sm text-red-600">{{ $message }}</span>@enderror
            </label>
            <p class="text-sm text-zinc-500">Próximo número de recibo: <strong class="tabular-nums">{{ str_pad((string) $proximoRecibo, 6, '0', STR_PAD_LEFT) }}</strong> (automático, sem buracos; não é editável).</p>
            <button class="btn-primario">Salvar documentos</button>
        </form>
    </section>

    {{-- 4. Cadastro público --}}
    <section class="cartao">
        <div class="cartao-cabecalho"><h2 class="cartao-titulo">Cadastro público ("Teste grátis")</h2></div>
        <form method="post" action="{{ route('plataforma.configuracoes.cadastro') }}" class="space-y-4 p-5">
            @csrf
            @method('PUT')
            <p class="text-sm text-zinc-500">
                Estado atual:
                @if (\App\Support\Configuracoes::cadastroAberto())
                    <span class="selo-verde">aberto</span>
                @else
                    <span class="selo-cinza">fechado</span>
                @endif
            </p>
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="cadastro_publico" value="1" class="rounded" @checked(old('cadastro_publico', $c->cadastro_publico))>
                Abrir o cadastro público (exige os termos de uso publicados)
            </label>
            @error('cadastro_publico', 'cadastro')<span class="block text-sm text-red-600">{{ $message }}</span>@enderror
            <label class="block">
                <span class="text-sm font-medium">Dias de teste grátis</span>
                <input type="number" name="cadastro_dias_teste" value="{{ old('cadastro_dias_teste', $c->cadastro_dias_teste) }}" min="1" max="90" required class="campo mt-1.5 w-32">
                @error('cadastro_dias_teste', 'cadastro')<span class="mt-1 block text-sm text-red-600">{{ $message }}</span>@enderror
            </label>
            <label class="block">
                <span class="text-sm font-medium">Plano do teste</span>
                <select name="cadastro_plano_id" class="campo mt-1.5">
                    <option value="">O mais barato ativo</option>
                    @foreach ($planos as $plano)
                        <option value="{{ $plano->id }}" @selected((string) old('cadastro_plano_id', $c->cadastro_plano_id) === (string) $plano->id)>
                            {{ $plano->nome }} · {{ moeda($plano->preco_mensal) }}/mês
                        </option>
                    @endforeach
                </select>
                @error('cadastro_plano_id', 'cadastro')<span class="mt-1 block text-sm text-red-600">{{ $message }}</span>@enderror
            </label>
            <button class="btn-primario">Salvar cadastro público</button>
        </form>
    </section>

    {{-- 5. Suporte --}}
    <section class="cartao">
        <div class="cartao-cabecalho"><h2 class="cartao-titulo">Suporte</h2></div>
        <form method="post" action="{{ route('plataforma.configuracoes.suporte') }}" class="space-y-4 p-5">
            @csrf
            @method('PUT')
            <label class="block">
                <span class="text-sm font-medium">Contato mostrado às locadoras no aviso de mensalidade</span>
                <input type="text" name="suporte_contato" value="{{ old('suporte_contato', $c->suporte_contato) }}" maxlength="200" class="campo mt-1.5">
                @error('suporte_contato', 'suporte')<span class="mt-1 block text-sm text-red-600">{{ $message }}</span>@enderror
            </label>
            <button class="btn-primario">Salvar suporte</button>
        </form>
    </section>

    {{-- 6. Integrações: só leitura. Chaves e segredos ficam no servidor, nunca na tela. --}}
    <section class="cartao xl:col-span-2">
        <div class="cartao-cabecalho"><h2 class="cartao-titulo">Integrações (somente leitura)</h2></div>
        <div class="grid grid-cols-1 gap-4 p-5 md:grid-cols-3">
            <div>
                <p class="text-sm font-medium">Asaas (cobrança)</p>
                <p class="text-sm text-zinc-500">
                    {{ $asaas['configurado'] ? 'Chave configurada' : 'Chave não configurada' }} · ambiente {{ $asaas['ambiente'] }}
                </p>
            </div>
            <div>
                <p class="text-sm font-medium">Token do webhook</p>
                <p class="text-sm text-zinc-500">{{ $asaas['webhook_token'] ? 'Configurado' : 'Não configurado (o webhook recusa todos os avisos)' }}</p>
            </div>
            <div>
                <p class="text-sm font-medium">Proxies confiáveis</p>
                <p class="text-sm text-zinc-500">{{ $proxies > 0 ? $proxies.' configurado(s)' : 'Nenhum (padrão seguro)' }}</p>
            </div>
            <p class="text-xs text-zinc-500 md:col-span-3">
                Chaves, tokens e proxies são definidos no arquivo de ambiente do servidor (laravel/.env), por segurança.
            </p>
        </div>
    </section>
</div>
@endsection
