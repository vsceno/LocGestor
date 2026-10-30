@php
    use App\Http\Controllers\VistoriasController;
    use App\Support\Arquivos;
    $titulo = ($tipo === 'retirada' ? 'Vistoria de retirada' : 'Vistoria de devolução') . ' · locação nº ' . $loc->numero;
    $estadoOld = (array) old('estado', []);
    $obsOld = (array) old('obs_item', []);
@endphp
@extends('layouts.app', ['titulo' => $titulo, 'ativo' => 'locacoes',
    'migalhas' => [['Locações', route('locacoes.index')], ['Nº ' . $loc->numero, route('locacoes.show', $loc->numero)]]])

@section('subtitulo', 'Confira cada equipamento junto com o cliente, fotografe o que for importante e peça a assinatura no fim. Depois de salva, a vistoria não pode ser alterada.')

@section('conteudo')
<form method="post" action="{{ route('vistorias.store', $loc->numero) }}" enctype="multipart/form-data" novalidate data-vistoria class="space-y-6">
    @csrf
    <input type="hidden" name="tipo" value="{{ $tipo }}">

    @error('vistoria')
        <div role="alert" class="flex gap-3 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/50 dark:text-red-300">
            <x-icone nome="alerta" class="mt-0.5 size-5" />
            <div><p class="font-medium">A vistoria não foi salva.</p><p class="mt-0.5">{{ $message }}</p></div>
        </div>
    @enderror

    <section class="cartao p-5">
        <p class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ $loc->cliente_nome }}</p>
        <p class="mt-1 text-xs text-zinc-500">Locação nº {{ $loc->numero }} · {{ $tipo === 'retirada' ? 'retirada dos equipamentos' : 'devolução dos equipamentos' }}</p>
    </section>

    <section class="cartao overflow-hidden">
        <div class="cartao-cabecalho"><h2 class="cartao-titulo">Estado dos equipamentos</h2></div>
        <div class="overflow-x-auto">
            <table class="tabela">
                <thead>
                    <tr>
                        <th>Equipamento</th>
                        <th class="num">{{ $tipo === 'retirada' ? 'Qtd.' : 'Devolvida / total' }}</th>
                        <th>Estado</th>
                        <th>Observação</th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($itens as $it)
                    <tr>
                        <td><span class="font-mono text-xs text-zinc-500">{{ $it->codigo }}</span> <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $it->descricao }}</span></td>
                        <td class="num whitespace-nowrap tabular-nums">
                            {{ $tipo === 'retirada' ? numero($it->quantidade) : numero($it->qtd_devolvida) . ' / ' . numero($it->quantidade) }}
                            <span class="text-xs text-zinc-400">{{ $it->unidade ?? '' }}</span>
                        </td>
                        <td>
                            <select name="estado[{{ $it->id }}]" aria-label="Estado de {{ $it->descricao }}" class="campo min-w-32">
                                @foreach (VistoriasController::ESTADOS as $valor => $rotulo)
                                    <option value="{{ $valor }}" @selected(($estadoOld[$it->id] ?? 'ok') === $valor)>{{ $rotulo }}</option>
                                @endforeach
                            </select>
                        </td>
                        <td>
                            <input type="text" name="obs_item[{{ $it->id }}]" value="{{ $obsOld[$it->id] ?? '' }}" maxlength="200"
                                   placeholder="ex.: risco na lateral" aria-label="Observação de {{ $it->descricao }}" class="campo min-w-48">
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="p-5">
            <label class="block">
                <span class="text-sm font-medium">Observações gerais</span>
                <textarea name="obs" rows="3" maxlength="2000" class="campo mt-1.5 resize-y">{{ old('obs') }}</textarea>
            </label>
        </div>
    </section>

    <section class="cartao p-5">
        <h2 class="cartao-titulo">Fotos</h2>
        <label class="mt-3 block">
            <span class="text-sm font-medium">Tirar ou escolher fotos (até {{ Arquivos::FOTOS_POR_VISTORIA }})</span>
            <input type="file" name="fotos[]" accept="image/jpeg,image/png,image/webp" multiple data-fotos
                   class="mt-1.5 block w-full text-sm text-zinc-600 file:mr-3 file:rounded-lg file:border-0 file:bg-marca-50 file:px-3 file:py-2 file:font-medium file:text-marca-700 dark:text-zinc-400 dark:file:bg-marca-500/10 dark:file:text-marca-400">
            <span class="mt-1 block text-xs text-zinc-500">No celular, abre a câmera. As fotos são reduzidas antes do envio e os dados de localização são removidos.</span>
        </label>
        <div class="mt-3 grid grid-cols-3 gap-2 sm:grid-cols-6" data-fotos-previa></div>
    </section>

    <section class="cartao p-5">
        <h2 class="cartao-titulo">Assinatura do cliente</h2>
        <label class="mt-3 block">
            <span class="text-sm font-medium">Nome de quem assina</span>
            <input type="text" name="assinante_nome" maxlength="100" value="{{ old('assinante_nome', $tipo === 'retirada' ? $loc->cliente_nome : '') }}" class="campo mt-1.5 max-w-md">
        </label>
        <div class="relative mt-4">
            <canvas data-assinatura-pad width="900" height="260" aria-label="Área de assinatura: assine com o dedo ou o mouse"
                    class="block h-56 w-full touch-none rounded-xl border border-dashed border-zinc-300 bg-white dark:border-zinc-700"></canvas>
            <span class="pointer-events-none absolute inset-x-6 bottom-6 border-t border-zinc-300 pt-1 text-center text-xs text-zinc-400">Assine acima da linha</span>
        </div>
        <input type="hidden" name="assinatura" data-assinatura-campo>
        <div class="mt-3 flex flex-wrap items-center gap-3">
            <button type="button" class="btn-secundario" data-assinatura-limpar>Limpar assinatura</button>
            <span class="text-xs text-zinc-500">Opcional, mas recomendada: é a concordância do cliente com o estado registrado.</span>
        </div>
    </section>

    <div class="sticky bottom-0 z-10 -mx-4 border-t border-zinc-200 bg-white/85 px-4 py-3 backdrop-blur-xl sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8 dark:border-zinc-800 dark:bg-zinc-950/85">
        <div class="flex items-center justify-end gap-2">
            <a class="btn-secundario" href="{{ route('locacoes.show', $loc->numero) }}">Cancelar</a>
            <button type="submit" class="btn-primario" data-salvar><x-icone nome="ok" class="size-4" /> Salvar vistoria</button>
        </div>
    </div>
</form>
@endsection
