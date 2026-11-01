@extends('layouts.app', ['titulo' => 'Exportação para o contador', 'ativo' => 'relatorios', 'migalhas' => [['Relatórios', route('relatorios.index')]]])

@section('subtitulo', 'Arquivos do mês em CSV (abrem no Excel), no regime de caixa: o que entrou e saiu pelo fluxo de caixa.')

@section('conteudo')
<section class="cartao overflow-hidden">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-100 p-5 dark:border-zinc-800">
        <p class="max-w-2xl text-sm text-zinc-500 dark:text-zinc-400">Os recebimentos de locação trazem o CPF/CNPJ do cliente: envie só ao seu contador.</p>
        <form method="get" action="{{ route('relatorios.contador') }}" class="flex items-center gap-2">
            <select name="mes" aria-label="Mês" class="campo">
                @foreach ($meses as $valor => $rotulo)<option value="{{ $valor }}" @selected($mes === $valor)>{{ $rotulo }}</option>@endforeach
            </select>
            <button class="btn-secundario">Ver</button>
        </form>
    </div>
    <div class="overflow-x-auto">
        <table class="tabela">
            <thead><tr><th>Arquivo</th><th>Conteúdo</th><th class="num">Linhas</th><th class="w-px"></th></tr></thead>
            <tbody>
            @foreach ($arquivos as $nome => [$titulo, , $linhas])
                <tr>
                    <td><code class="font-mono text-xs">{{ $nome }}</code></td>
                    <td class="text-zinc-900 dark:text-zinc-100">{{ $titulo }}</td>
                    <td class="num">{{ numero(count($linhas)) }}</td>
                    <td><a class="btn-secundario" href="{{ route('relatorios.contador.baixar', ['mes' => $mes, 'arquivo' => $nome]) }}">Baixar</a></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    @if ($zip)
        <div class="border-t border-zinc-100 p-5 dark:border-zinc-800">
            <a class="btn-primario" href="{{ route('relatorios.contador.baixar', ['mes' => $mes, 'arquivo' => 'zip']) }}"><x-icone nome="relatorios" class="size-4" /> Baixar tudo (.zip)</a>
        </div>
    @endif
</section>
@endsection
