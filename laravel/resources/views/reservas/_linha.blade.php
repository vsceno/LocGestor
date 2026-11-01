<tr data-linha>
    <td>
        <select name="produto_id[]" aria-label="Produto" class="campo min-w-60" data-produto>
            <option value="">— escolha —</option>
            @foreach ($produtos as $p)<option value="{{ $p->id }}" @selected($p->id === $ln['produto_id'])>{{ $p->descricao }} ({{ $p->codigo }})</option>@endforeach
        </select>
    </td>
    <td>
        <select name="periodo[]" aria-label="Período" class="campo min-w-28">
            @foreach (\App\Http\Controllers\NovaLocacaoController::PERIODOS as $valor => $rotulo)<option value="{{ $valor }}" @selected(($ln['periodo'] ?? 'diaria') === $valor)>{{ $rotulo }}</option>@endforeach
        </select>
    </td>
    <td class="num"><input type="number" name="quantidade[]" value="{{ $ln['quantidade'] }}" min="1" step="1" aria-label="Quantidade" class="campo ml-auto w-24 text-right tabular-nums" data-qtd></td>
    <td class="num" data-disp><span class="text-zinc-400">—</span></td>
    <td><button type="button" class="btn-icone hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-500/10" title="Remover item" aria-label="Remover item" data-remover><x-icone nome="fechar" class="size-[18px]" /></button></td>
</tr>
