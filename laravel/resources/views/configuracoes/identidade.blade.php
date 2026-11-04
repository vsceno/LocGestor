@php
    // Após erro, o que foi digitado; senão, o que vale hoje (padrão neutro onde a locadora não escolheu)
    $valor = fn (string $campo) => old($campo, $v[$campo]);
    $dicas = [
        'cor_principal' => 'Botões, campos em foco, menu ativo e links.',
        'cor_destaque' => 'Ações de destaque.',
        'cor_texto_escuro' => 'Texto das telas e dos documentos.',
        'cor_texto_claro' => 'Texto sobre os botões da cor principal.',
        'cor_fundo' => 'Fundo das telas (no modo claro).',
    ];
    $fontes = ['inter' => 'Inter (padrão)', 'sistema' => 'Fonte do sistema (sem download)'];
    $fontesCss = \App\Support\Identidade::FONTES;
@endphp
@extends('layouts.app', ['titulo' => 'Configurações', 'ativo' => 'config'])

@section('subtitulo', 'Cores, fontes e logotipo da locadora. Sem escolha, o sistema usa o padrão neutro.')

@section('conteudo')
@include('configuracoes._abas', ['ativa' => 'config.identidade'])

@error('identidade')
    <div role="alert" class="mb-6 flex gap-3 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/50 dark:text-red-300">
        <x-icone nome="alerta" class="mt-0.5 size-5" /><div><p class="font-medium">Confira a identidade visual.</p><p class="mt-0.5">{{ $message }}</p></div>
    </div>
@enderror

<div class="grid grid-cols-1 gap-6 xl:grid-cols-[1fr_22rem]">
    <form method="post" action="{{ route('config.identidade.salvar') }}" enctype="multipart/form-data" class="space-y-6">
        @csrf
        @method('PUT')

        <section class="cartao">
            <div class="cartao-cabecalho"><div><h2 class="cartao-titulo">Cores</h2>
                <p class="text-xs text-zinc-500">Trocar a cor não muda botões, campos ou menus: só o que vem da cor escolhida.</p></div></div>
            <div class="grid grid-cols-1 gap-5 p-5 sm:grid-cols-2">
                @foreach (\App\Support\Identidade::CORES as $campo => $rotulo)
                    <label class="block">
                        <span class="text-sm font-medium">{{ $rotulo }}</span>
                        <div class="mt-1.5 flex items-center gap-3">
                            <input type="color" name="{{ $campo }}" value="{{ $valor($campo) }}" data-cor="{{ $campo }}"
                                   class="h-10 w-14 cursor-pointer rounded-lg border border-zinc-200 bg-white p-1 dark:border-zinc-800 dark:bg-zinc-900">
                            <code class="text-xs text-zinc-500" data-valor="{{ $campo }}">{{ $valor($campo) }}</code>
                        </div>
                        <span class="mt-1 block text-xs text-zinc-500">{{ $dicas[$campo] }}</span>
                    </label>
                @endforeach
            </div>
        </section>

        <section class="cartao">
            <div class="cartao-cabecalho"><div><h2 class="cartao-titulo">Fontes</h2>
                <p class="text-xs text-zinc-500">As duas fontes disponíveis já vêm com o sistema.</p></div></div>
            <div class="grid grid-cols-1 gap-5 p-5 sm:grid-cols-2">
                @foreach (['fonte_titulos' => 'Fonte dos títulos', 'fonte_textos' => 'Fonte dos textos'] as $campo => $rotulo)
                    <label class="block"><span class="text-sm font-medium">{{ $rotulo }}</span>
                        <select name="{{ $campo }}" data-fonte="{{ $campo }}" class="campo mt-1.5">
                            @foreach ($fontes as $chave => $nome)
                                <option value="{{ $chave }}" @selected($valor($campo) === $chave)>{{ $nome }}</option>
                            @endforeach
                        </select></label>
                @endforeach
            </div>
        </section>

        <section class="cartao">
            <div class="cartao-cabecalho"><div><h2 class="cartao-titulo">Logotipos</h2>
                <p class="text-xs text-zinc-500">PNG, JPG ou WebP, até 5 MB. A imagem é redesenhada em PNG (sem metadados).</p></div></div>
            <div class="grid grid-cols-1 gap-6 p-5 sm:grid-cols-2">
                <div>
                    <p class="text-sm font-medium">Horizontal</p>
                    <p class="mt-0.5 text-xs text-zinc-500">Menu aberto e documentos impressos. Troque em <a href="{{ route('config.index') }}" class="font-medium text-marca-700 hover:underline dark:text-marca-400">Dados da locadora</a>.</p>
                    <div class="mt-3 flex h-20 items-center justify-center overflow-hidden rounded-xl border border-dashed border-zinc-300 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-900">
                        @if ($e->logo_arquivo !== '')
                            <img src="{{ route('arquivos.logo', ['v' => substr(md5($e->logo_arquivo), 0, 8)]) }}" alt="Logotipo horizontal atual" class="h-14 w-auto max-w-full object-contain">
                        @else
                            <span class="text-xs text-zinc-400">Nenhum enviado</span>
                        @endif
                    </div>
                </div>
                <div>
                    <label class="block"><span class="text-sm font-medium">Quadrado</span>
                        <span class="mt-0.5 block text-xs text-zinc-500">Menu recolhido (só ícones). Sem ele, aparece o ícone neutro.</span></label>
                    <div class="mt-3 flex h-20 items-center justify-center overflow-hidden rounded-xl border border-dashed border-zinc-300 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-900">
                        @if ($e->logo_quadrada_arquivo !== '')
                            <img src="{{ route('arquivos.logo_quadrada', ['v' => substr(md5($e->logo_quadrada_arquivo), 0, 8)]) }}" alt="Logotipo quadrado atual" class="h-14 w-14 object-contain">
                        @else
                            <span class="text-xs text-zinc-400">Nenhum enviado</span>
                        @endif
                    </div>
                    <input type="file" name="logo_quadrada" accept="image/png,image/jpeg,image/webp" class="mt-3 block text-sm">
                    @if ($e->logo_quadrada_arquivo !== '')
                        <label class="mt-2 flex items-center gap-2 text-sm"><input type="checkbox" name="logo_quadrada_remover" value="1" @checked(old('logo_quadrada_remover'))>
                            Remover o logotipo quadrado</label>
                    @endif
                </div>
            </div>
        </section>

        <div class="flex justify-end">
            <button type="submit" class="btn-primario">Salvar identidade visual</button>
        </div>
    </form>

    <aside class="space-y-6 xl:sticky xl:top-6 xl:self-start">
        <section class="cartao">
            <div class="cartao-cabecalho"><div><h2 class="cartao-titulo">Prévia</h2>
                <p class="text-xs text-zinc-500">Como fica com as cores escolhidas.</p></div></div>
            <div id="previa" class="m-5 space-y-4 rounded-xl border border-zinc-200 p-5"
                 style="background-color: {{ $valor('cor_fundo') }}; color: {{ $valor('cor_texto_escuro') }}; font-family: {{ $fontesCss[$valor('fonte_textos')] }};">
                <p data-previa="titulo" class="text-lg font-semibold"
                   style="font-family: {{ $fontesCss[$valor('fonte_titulos')] }};">Título da tela</p>
                <p class="text-sm">Texto de exemplo, como nas telas e nos documentos.</p>
                <div class="flex flex-wrap items-center gap-2">
                    <span data-previa="botao" class="inline-flex items-center rounded-lg px-3.5 py-2 text-sm font-medium shadow-sm"
                          style="background-color: {{ $valor('cor_principal') }}; color: {{ $valor('cor_texto_claro') }};">Botão principal</span>
                    <span data-previa="destaque" class="inline-flex items-center rounded-lg px-3.5 py-2 text-sm font-medium"
                          style="background-color: {{ $valor('cor_destaque') }}; color: {{ \App\Support\Identidade::textoSobre($valor('cor_destaque')) }};">Destaque</span>
                </div>
            </div>
        </section>

        <form method="post" action="{{ route('config.identidade.salvar') }}" onsubmit="return confirm('Voltar as cores e as fontes ao padrão neutro?')" class="cartao p-5">
            @csrf
            @method('PUT')
            <input type="hidden" name="restaurar_padrao" value="1">
            <p class="text-sm font-medium">Padrão neutro</p>
            <p class="mt-0.5 text-xs text-zinc-500">Volta as cores e as fontes ao padrão do sistema. Os logotipos não mudam.</p>
            <button type="submit" class="btn-secundario mt-4 w-full">Voltar ao padrão neutro</button>
        </form>
    </aside>
</div>

<script>
    // Prévia ao vivo: só mexe nas cores e nas fontes da tela; quem confere a regra de contraste é o servidor, ao salvar
    (function () {
        var previa = document.getElementById('previa');
        var fontes = @json($fontesCss);

        // Mesma regra do servidor (App\Support\Identidade::textoSobre): branco ou escuro, o que lê melhor
        function luminancia(hex) {
            var c = [1, 3, 5].map(function (i) {
                var v = parseInt(hex.substr(i, 2), 16) / 255;
                return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
            });
            return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
        }
        function contraste(a, b) {
            var la = luminancia(a), lb = luminancia(b);
            return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
        }
        function textoSobre(hex) {
            return contraste(hex, '#FFFFFF') >= contraste(hex, '#111827') ? '#FFFFFF' : '#111827';
        }
        var mapa = {
            cor_fundo: function (v) { previa.style.backgroundColor = v; },
            cor_texto_escuro: function (v) { previa.style.color = v; },
            cor_principal: function (v) { previa.querySelector('[data-previa="botao"]').style.backgroundColor = v; },
            cor_destaque: function (v) {
                var destaque = previa.querySelector('[data-previa="destaque"]');
                destaque.style.backgroundColor = v;
                destaque.style.color = textoSobre(v);
            },
            cor_texto_claro: function (v) { previa.querySelector('[data-previa="botao"]').style.color = v; }
        };
        document.querySelectorAll('[data-cor]').forEach(function (input) {
            input.addEventListener('input', function () {
                var campo = input.dataset.cor;
                document.querySelector('[data-valor="' + campo + '"]').textContent = input.value.toUpperCase();
                if (mapa[campo]) { mapa[campo](input.value); }
            });
        });
        document.querySelectorAll('[data-fonte]').forEach(function (select) {
            select.addEventListener('change', function () {
                var pilha = fontes[select.value];
                if (select.dataset.fonte === 'fonte_textos') { previa.style.fontFamily = pilha; }
                else { previa.querySelector('[data-previa="titulo"]').style.fontFamily = pilha; }
            });
        });
    })();
</script>
@endsection
