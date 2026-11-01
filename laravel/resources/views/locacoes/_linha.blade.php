{{-- Uma linha de item da nova locação. O JavaScript calcula dias, devolução, subtotal e consulta o disponível; o servidor confere tudo ao salvar. --}}
<tr data-linha>
    <td>
        <select name="produto_id[]" aria-label="Produto" class="campo min-w-52" data-produto>
            <option value="">— escolha —</option>
            @foreach ($produtos as $p)
                <option value="{{ $p->id }}" data-diaria="{{ $p->valor_diaria }}" data-semanal="{{ $p->valor_semanal }}" data-quinzenal="{{ $p->valor_quinzenal }}"
                        data-mensal="{{ $p->valor_mensal }}" @selected($p->id === $ln['produto_id'])>{{ $p->descricao }} ({{ $p->codigo }})</option>
            @endforeach
        </select>
    </td>
    <td>
        <select name="periodo[]" aria-label="Período" class="campo min-w-28" data-periodo>
            @foreach (\App\Http\Controllers\NovaLocacaoController::PERIODOS as $valor => $rotulo)<option value="{{ $valor }}" @selected(($ln['periodo'] ?? 'diaria') === $valor)>{{ $rotulo }}</option>@endforeach
        </select>
    </td>
    <td><input type="number" min="1" step="1" aria-label="Dias de locação" class="campo min-w-16 tabular-nums" data-dias></td>
    <td class="num"><input type="number" name="quantidade[]" value="{{ $ln['quantidade'] }}" min="1" step="1" aria-label="Quantidade" class="campo min-w-16 text-right tabular-nums" data-qtd></td>
    <td class="num">
        <span class="relative block"><span class="pointer-events-none absolute top-1/2 left-2.5 -translate-y-1/2 text-xs text-zinc-400">R$</span>
            <input type="text" name="diaria[]" value="{{ $ln['diaria'] }}" inputmode="decimal" aria-label="Valor do período" class="campo min-w-24 pl-8 text-right tabular-nums" data-valor-item></span>
    </td>
    <td class="num" data-disp><span class="text-zinc-400">—</span></td>
    <td><input type="date" name="prev[]" value="{{ $ln['prev'] ?? '' }}" aria-label="Devolução prevista do item" class="campo tabular-nums" data-prev></td>
    <td class="num whitespace-nowrap font-medium" data-subtotal>—</td>
    <td><button type="button" class="btn-icone hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-500/10" title="Remover item" aria-label="Remover item" data-remover><x-icone nome="fechar" class="size-[18px]" /></button></td>
</tr>
