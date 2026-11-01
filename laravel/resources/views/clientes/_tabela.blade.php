@if ($clientes->total() === 0)
    <div class="px-6 py-16 text-center">
        <span class="mx-auto grid size-12 place-items-center rounded-full bg-marca-50 text-marca-600 dark:bg-marca-500/10"><x-icone nome="clientes" /></span>
        @if ($q !== '')
            <p class="mt-3 font-medium">Nenhum cliente para “{{ $q }}”</p>
            <p class="mt-1 text-sm text-zinc-500">Confira a grafia, busque pelo CPF/CNPJ ou telefone, ou veja todas as situações.</p>
        @else
            <p class="mt-3 font-medium">Nenhum cliente nesta situação</p>
            <p class="mt-1 text-sm text-zinc-500">Os clientes cadastrados aparecem aqui.</p>
        @endif
        <a class="btn-primario mt-5" href="{{ route('clientes.create') }}"><x-icone nome="mais" class="size-4" /> Cadastrar cliente</a>
    </div>
@else
    <div class="overflow-x-auto">
        <table class="tabela">
            <thead><tr><th>Cliente</th><th>CPF/CNPJ</th><th>Telefone</th><th>Cidade</th><th>Situação</th><th class="num">Em aberto</th><th class="w-px"></th></tr></thead>
            <tbody>
            @foreach ($clientes as $c)
                @php $wa = whatsapp_numero($c); @endphp
                <tr data-href="{{ route('clientes.show', $c->id) }}">
                    <td>
                        <a href="{{ route('clientes.show', $c->id) }}" class="flex items-center gap-3">
                            <span class="avatar size-9 text-xs {{ $c->tipo === 'PJ' ? 'from-sky-500 to-indigo-600' : 'from-marca-400 to-marca-700' }}">{{ iniciais($c->nome) }}</span>
                            <span class="min-w-0">
                                <span class="block truncate font-medium text-zinc-900 dark:text-zinc-100">{{ $c->nome }}</span>
                                <span class="block text-xs text-zinc-500">{{ $c->tipo === 'PJ' ? 'Pessoa jurídica' : 'Pessoa física' }}</span>
                            </span>
                        </a>
                    </td>
                    <td class="tabular-nums whitespace-nowrap text-zinc-600 dark:text-zinc-400">{{ doc_formatar($c->cpf_cnpj) }}</td>
                    <td class="tabular-nums whitespace-nowrap text-zinc-600 dark:text-zinc-400">{{ $c->celular ?: $c->telefone }}</td>
                    <td class="text-zinc-600 dark:text-zinc-400">{{ $c->cidade }}{{ $c->uf !== '' ? '/' . $c->uf : '' }}</td>
                    <td>@include('clientes._selos', ['c' => $c])</td>
                    <td class="num">@if ($c->abertas > 0)<span class="selo-azul">{{ numero($c->abertas) }}</span>@else<span class="text-zinc-400">—</span>@endif</td>
                    <td>
                        <div class="flex items-center justify-end gap-1">
                            @if ($wa)
                                <a class="btn-icone hover:bg-emerald-50 hover:text-emerald-600 dark:hover:bg-emerald-500/10" href="https://wa.me/{{ $wa }}" target="_blank" rel="noopener"
                                   title="WhatsApp" aria-label="WhatsApp de {{ $c->nome }}"><x-icone nome="whatsapp" class="size-[18px]" /></a>
                            @endif
                            <a class="btn-icone" href="{{ route('clientes.edit', $c->id) }}" title="Editar" aria-label="Editar {{ $c->nome }}">
                                <x-icone nome="editar" class="size-[18px]" /></a>
                        </div>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    @include('partials.paginacao', ['p' => $clientes])
@endif
