{{-- Acréscimo, fretes e desconto numa linha só, com o valor ao lado de cada um, também quando zerados (ajustes_linha()).
     $v: a locação ou o orçamento; $colunas: quantas colunas a tabela tem, para a linha ocupar a largura toda. --}}
<tr>
    <td colspan="{{ $colunas }}" class="ajustes-celula">
        <div class="ajustes">
            <span>Acréscimo (montagem etc.) <b>{{ moeda($v->acrescimo) }}</b></span>
            <span>Frete <b>{{ moeda($v->frete_entrega) }}</b></span>
            <span>Frete de recolhimento <b>{{ moeda($v->frete_recolhimento) }}</b></span>
            <span>Desconto <b>{{ (float) $v->desconto > 0 ? '− ' : '' }}{{ moeda($v->desconto) }}</b></span>
        </div>
    </td>
</tr>
