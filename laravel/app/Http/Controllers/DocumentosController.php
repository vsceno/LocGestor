<?php

namespace App\Http\Controllers;

use App\Support\Documentos;
use App\Support\Locacoes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Documentos impressos da locação: contrato, fatura e romaneio. Só imprime: não grava nada nem mexe no estoque.
 * Os cálculos são os do sistema antigo (modulos/locacoes.php); os preços saem das funções do banco.
 */
class DocumentosController extends Controller
{
    /** Preço total de um item pelos dias: preco_item() do sistema antigo, nas funções do banco (são 2 parâmetros de dias). */
    public const PRECO_TOTAL = "CASE WHEN li.periodo = 'automatico'
        THEN preco_periodo(CAST(? AS integer), li.valor_diaria, li.valor_semanal, li.valor_mensal)
        ELSE preco_plano(CAST(? AS integer), li.periodo, li.valor_diaria, li.valor_semanal, li.valor_quinzenal, li.valor_mensal) END";

    public function contrato(int $numero): View
    {
        $loc = $this->locacao($numero);
        $dias = Locacoes::diasCobranca($loc->data_saida, $loc->data_prev_devolucao);
        $itens = Documentos::preparar(DB::select("SELECT li.quantidade, li.periodo, li.valor_diaria, li.valor_semanal, li.valor_quinzenal, li.valor_mensal,
                                                         p.descricao, p.valor_reposicao,
                                                         (SELECT string_agg(u.patrimonio, ', ' ORDER BY u.patrimonio) FROM locacao_unidades lu
                                                            JOIN unidades u ON u.id = lu.unidade_id WHERE lu.locacao_item_id = li.id) AS patrimonios,
                                                         " . self::PRECO_TOTAL . " AS preco_total
                                                    FROM locacao_itens li JOIN produtos p ON p.id = li.produto_id
                                                   WHERE li.locacao_id = ? ORDER BY p.descricao", [$dias, $dias, $loc->id]));
        $subtotal = array_sum(array_map(fn ($it) => $it->quantidade * (float) $it->preco_total, $itens));
        $empresa = DB::selectOne('SELECT * FROM empresa');
        // Valor de reposição por unidade: vai na cláusula de avarias, não na tabela de bens
        $reposicao = array_map(fn ($it) => $it->descricao . ': ' . moeda($it->valor_reposicao) . ' por unidade', $itens);
        // Assinatura feita na tela, na vistoria de retirada (a vistoria ainda abre no sistema antigo)
        $assinatura = DB::selectOne("SELECT id, assinante_nome, assinado_em FROM vistorias
                                      WHERE locacao_id = ? AND tipo = 'retirada' AND assinatura_arquivo <> ''", [$loc->id]);

        return view('documentos.contrato', [
            'loc'        => $loc,
            'dias'       => $dias,
            'itens'      => $itens,
            'subtotal'   => $subtotal,
            'empresa'    => $empresa,
            'cliente'    => $this->cliente($loc->cliente_id),
            'clausulas'  => Documentos::clausulas($empresa->termos_contrato, $reposicao),
            'assinatura' => $assinatura,
        ]);
    }

    public function fatura(int $numero): View
    {
        $loc = $this->locacao($numero);
        $aberta = $loc->status === 'aberta';
        $hoje = now()->toDateString();
        $diasHoje = Locacoes::diasCobranca($loc->data_saida, $hoje);
        // Itens já devolvidos (com os dias efetivos) e pendentes cobrados até hoje
        $devolvidos = DB::select("SELECT d.data, di.quantidade, di.dias, di.valor, li.periodo, li.valor_diaria, li.valor_semanal, li.valor_quinzenal,
                                         li.valor_mensal, p.descricao, p.unidade
                                    FROM devolucao_itens di
                                    JOIN devolucoes d ON d.id = di.devolucao_id
                                    JOIN locacao_itens li ON li.id = di.locacao_item_id
                                    JOIN produtos p ON p.id = li.produto_id
                                   WHERE d.locacao_id = ? ORDER BY d.data, p.descricao", [$loc->id]);
        $pendentes = $aberta ? Documentos::preparar(DB::select("SELECT li.quantidade - li.qtd_devolvida AS quantidade, li.periodo, li.valor_diaria,
                                                                       li.valor_semanal, li.valor_quinzenal, li.valor_mensal, p.descricao, p.unidade,
                                                                       " . self::PRECO_TOTAL . " AS preco_total
                                                                  FROM locacao_itens li JOIN produtos p ON p.id = li.produto_id
                                                                 WHERE li.locacao_id = ? AND li.quantidade > li.qtd_devolvida ORDER BY p.descricao",
                                                               [$diasHoje, $diasHoje, $loc->id])) : [];
        $devolvidos = Documentos::preparar($devolvidos);
        $extras = DB::selectOne('SELECT COALESCE(SUM(multa), 0) AS multa, COALESCE(SUM(avaria), 0) AS avaria FROM devolucoes WHERE locacao_id = ?', [$loc->id]);
        // Subtotal = o que as linhas somam (devolvidas e pendentes até hoje); o total soma também ajustes, multa e avaria
        $subtotal = array_sum(array_map(fn ($l) => (float) $l->valor, $devolvidos))
                  + array_sum(array_map(fn ($l) => $l->quantidade * (float) $l->preco_total, $pendentes));
        $empresa = DB::selectOne('SELECT * FROM empresa');

        return view('documentos.fatura', [
            'loc'        => $loc,
            'aberta'     => $aberta,
            'hoje'       => $hoje,
            'diasHoje'   => $diasHoje,
            'devolvidos' => $devolvidos,
            'pendentes'  => $pendentes,
            'extras'     => $extras,
            'subtotal'   => $subtotal,
            'saldo'      => $loc->valor_atual - $loc->valor_pago,
            'empresa'    => $empresa,
            'cliente'    => $this->cliente($loc->cliente_id),
            'whats'      => Locacoes::whatsapp($loc, $empresa)['links'],
            'pix'        => Locacoes::pix($loc, $empresa),
        ]);
    }

    public function romaneio(Request $request, int $numero): View
    {
        $loc = $this->locacao($numero);
        $itens = DB::select("SELECT li.quantidade, p.codigo, p.descricao, p.unidade,
                                    (SELECT string_agg(u.patrimonio, ', ' ORDER BY u.patrimonio) FROM locacao_unidades lu
                                       JOIN unidades u ON u.id = lu.unidade_id WHERE lu.locacao_item_id = li.id) AS patrimonios
                               FROM locacao_itens li JOIN produtos p ON p.id = li.produto_id
                              WHERE li.locacao_id = ? ORDER BY p.descricao", [$loc->id]);

        return view('documentos.romaneio', [
            'loc'     => $loc,
            'troca'   => $request->query('tipo') === 'troca',
            'itens'   => $itens,
            'cliente' => $this->cliente($loc->cliente_id),
        ]);
    }

    /** Locação da locadora logada ou 404 (o RLS esconde as das outras). */
    private function locacao(int $numero): object
    {
        abort_if($numero < 1 || $numero > 999999999, 404);
        $loc = Locacoes::porNumero($numero);
        abort_if($loc === null, 404, 'Locação não encontrada.');
        return $loc;
    }

    private function cliente(string $id): object
    {
        return DB::selectOne('SELECT * FROM clientes WHERE id = ?', [$id]);
    }
}
