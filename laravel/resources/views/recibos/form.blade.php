@php
    use App\Support\Estoque;
    $campo = fn (string $nome) => 'campo mt-1.5' . ($errors->has($nome) ? ' border-red-400 focus:border-red-500 focus:ring-red-500/15 dark:border-red-700' : '');
@endphp
@extends('layouts.app', ['titulo' => 'Novo recibo', 'ativo' => 'recibos', 'migalhas' => [['Recibos', route('recibos.index')]]])

@section('subtitulo', 'Número fixo: depois de emitido, o recibo não se altera nem se apaga. O pagamento continua sendo lançado no caixa.')

@section('conteudo')
<form method="post" action="{{ route('recibos.store') }}" autocomplete="off" novalidate class="mx-auto max-w-3xl">
    @csrf
    <input type="hidden" name="locacao_id" value="{{ old('locacao_id', $pre['locacao_id']) }}">

    @if ($errors->any())
        <div role="alert" class="mb-6 flex gap-3 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/50 dark:text-red-300">
            <x-icone nome="alerta" class="mt-0.5 size-5" />
            <div><p class="font-medium">O recibo não foi emitido.</p><p class="mt-0.5">{{ $errors->first() }}</p></div>
        </div>
    @endif

    <section class="cartao">
        <div class="cartao-cabecalho">
            <h2 class="cartao-titulo">Recibo</h2>
            @if ($loc)<span class="text-xs text-zinc-500">Ligado à locação nº {{ $loc->numero }}</span>@endif
        </div>
        <div class="grid grid-cols-1 gap-5 p-5 md:grid-cols-2">
            <label class="block md:col-span-2">
                <span class="text-sm font-medium">Recebido de <span class="text-red-500">*</span></span>
                <input type="text" name="recebido_de" value="{{ old('recebido_de', $pre['recebido_de']) }}" required maxlength="150"
                       class="{{ $campo('recebido_de') }}" @if ($pre['recebido_de'] === '') autofocus @endif>
                @error('recebido_de')<span class="mt-1 block text-xs text-red-600 dark:text-red-400">{{ $message }}</span>@enderror
            </label>
            <label class="block">
                <span class="text-sm font-medium">CPF ou CNPJ</span>
                <input type="text" name="documento" value="{{ old('documento', $pre['documento'] !== '' ? doc_formatar($pre['documento']) : '') }}" inputmode="numeric"
                       maxlength="18" data-mascara="doc" placeholder="Opcional" class="{{ $campo('documento') }}">
                @error('documento')<span class="mt-1 block text-xs text-red-600 dark:text-red-400">{{ $message }}</span>@enderror
            </label>
            <label class="block">
                <span class="text-sm font-medium">Valor <span class="text-red-500">*</span></span>
                <span class="relative mt-1.5 block">
                    <span class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-zinc-400">R$</span>
                    <input type="text" name="valor" value="{{ old('valor') }}" required inputmode="decimal" placeholder="0,00" class="{{ $campo('valor') }} mt-0 pl-9 text-right tabular-nums">
                </span>
                @error('valor')<span class="mt-1 block text-xs text-red-600 dark:text-red-400">{{ $message }}</span>@enderror
            </label>
            <label class="block">
                <span class="text-sm font-medium">Forma <span class="text-red-500">*</span></span>
                <select name="forma" required class="{{ $campo('forma') }}">
                    @foreach (Estoque::FORMAS_PGTO as $f)<option @selected(old('forma', 'PIX') === $f)>{{ $f }}</option>@endforeach
                </select>
                @error('forma')<span class="mt-1 block text-xs text-red-600 dark:text-red-400">{{ $message }}</span>@enderror
            </label>
            <label class="block md:col-span-2">
                <span class="text-sm font-medium">Referente a <span class="text-red-500">*</span></span>
                <input type="text" name="referente" value="{{ old('referente', $pre['referente']) }}" required maxlength="200" class="{{ $campo('referente') }}">
                @error('referente')<span class="mt-1 block text-xs text-red-600 dark:text-red-400">{{ $message }}</span>@enderror
            </label>
        </div>
    </section>

    <div class="mt-6 flex justify-end gap-2">
        <a class="btn-secundario" href="{{ route('recibos.index') }}">Cancelar</a>
        <button type="submit" class="btn-primario"><x-icone nome="ok" class="size-4" /> Emitir recibo</button>
    </div>
</form>
@endsection
