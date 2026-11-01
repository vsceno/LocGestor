@extends('layouts.plataforma', ['titulo' => 'Uso por locadora', 'ativo' => 'uso', 'subtitulo' => 'O que cada locadora faz no sistema. Nenhum dado de cliente aparece aqui.'])

@section('conteudo')
<div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
    <x-kpi rotulo="Locações no mês (todas)" :valor="numero($locacoes)" ico="locacoes"
           cor="bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-400" />
    <x-kpi rotulo="Sem acesso há 30 dias" :valor="numero($semAcesso)" detalhe="locadoras ativas" ico="relogio"
           cor="bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400" />
    <x-kpi rotulo="Espaço das fotos de vistoria" :valor="numero($fotosMb, 1) . ' MB'" ico="painel"
           cor="bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300" />
</div>

<section class="cartao mt-6 overflow-hidden">
    <div class="cartao-cabecalho">
        <h2 class="cartao-titulo">Uso por locadora</h2>
        <span class="text-sm text-zinc-500">"Recursos" são as funções usadas alguma vez (de {{ numero($totalRecursos) }}).</span>
    </div>
    <div class="overflow-x-auto">
        <table class="tabela">
            <thead>
                <tr><th>Locadora</th><th>Status</th><th class="num">Locações no mês</th><th class="num">Usuários (30 dias)</th>
                    <th class="num">Fotos</th><th>Recursos usados</th><th>Sugestão</th></tr>
            </thead>
            <tbody>
                @forelse ($locadoras as $t)
                    <tr>
                        <td>
                            <a class="font-medium text-zinc-900 hover:text-marca-700 dark:text-zinc-100 dark:hover:text-marca-400"
                               href="{{ route('plataforma.locadoras.editar', $t->id) }}">{{ $t->nome }}</a>
                            <div class="text-xs text-zinc-500"><code class="font-mono">{{ $t->slug }}</code></div>
                        </td>
                        <td><span class="{{ $t->status === 'ativa' ? 'selo-verde' : 'selo-vermelho' }}">{{ $t->status }}</span></td>
                        <td class="num">{{ numero($t->locacoes_mes) }}</td>
                        <td class="num">{{ numero($t->usuarios_30d) }} de {{ numero($t->usuarios) }}
                            <span class="text-zinc-500">(limite {{ numero($t->limite_usuarios) }})</span></td>
                        <td class="num">{{ $t->fotos_bytes > 0 ? numero($t->fotos_bytes / 1048576, 1) . ' MB' : '—' }}</td>
                        <td>
                            @if ($t->recursos)
                                {{ implode(' · ', array_map(fn ($k) => \App\Support\Locadoras::RECURSOS_USO[$k], $t->recursos)) }}
                            @else
                                <span class="text-zinc-500">nenhum</span>
                            @endif
                            <span class="text-zinc-500">({{ numero(count($t->recursos)) }} de {{ numero($totalRecursos) }})</span>
                        </td>
                        <td>
                            @if ($t->perto_do_limite)
                                <span class="selo-amarelo">perto do limite de usuários</span>
                                @if ($t->sugestao)<div class="mt-1 text-xs text-zinc-500">sugerir o plano {{ $t->sugestao }}</div>@endif
                            @elseif ($t->status === 'ativa' && $t->usuarios_30d === 0)
                                <span class="selo-cinza">sem acesso recente</span>
                            @else
                                <span class="text-zinc-400">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-zinc-500">Nenhuma locadora cadastrada.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection
