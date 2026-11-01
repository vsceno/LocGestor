<?php

namespace App\Http\Controllers;

use App\Support\Estoque;
use App\Support\Locacoes;
use App\Support\Recusa;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Devolução total ou parcial. Mesmas regras de devolucao_registrar() do sistema antigo: data entre a saída e hoje,
 * cada item vale qtd x preço do período pelos dias (contando o dia da saída), perdas cobradas pelo valor de reposição
 * (entram na avaria e saem do estoque), unidades de patrimônio que voltaram e recebimento no caixa, numa transação.
 */
class DevolucaoController extends Controller
{
    /** Preço de 1 unidade do item por N dias: as funções de preço do banco (as mesmas da view vw_locacao_valores). */
    private const PRECO = "CASE WHEN li.periodo = 'automatico'
                                THEN preco_periodo(CAST(? AS integer), li.valor_diaria, li.valor_semanal, li.valor_mensal)
                                ELSE preco_plano(CAST(? AS integer), li.periodo, li.valor_diaria, li.valor_semanal, li.valor_quinzenal, li.valor_mensal) END";

    public function create(int $numero): View|RedirectResponse
    {
        $loc = $this->locacao($numero);
        if ($loc->status !== 'aberta') {
            return redirect()->route('locacoes.show', $numero)->with('flash', [['tipo' => 'aviso', 'msg' => 'Esta locação não está em aberto.']]);
        }
        $hoje = now()->toDateString();
        $dias = Locacoes::diasCobranca($loc->data_saida, $hoje);
        $itens = DB::select('SELECT li.id, li.quantidade, li.qtd_devolvida, li.valor_diaria, li.valor_semanal, li.valor_quinzenal, li.valor_mensal, li.periodo,
                                    p.descricao, p.unidade, p.valor_reposicao, ' . self::PRECO . ' AS preco_hoje
                               FROM locacao_itens li JOIN produtos p ON p.id = li.produto_id
                              WHERE li.locacao_id = ? AND li.quantidade > li.qtd_devolvida ORDER BY p.descricao', [$dias, $dias, $loc->id]);
        // O que já foi apurado em devoluções anteriores (itens + multas + avarias)
        $apurado = (float) DB::selectOne('SELECT COALESCE((SELECT SUM(di.valor) FROM devolucao_itens di JOIN devolucoes d ON d.id = di.devolucao_id WHERE d.locacao_id = CAST(? AS uuid)), 0)
                                               + COALESCE((SELECT SUM(multa + avaria) FROM devolucoes WHERE locacao_id = CAST(? AS uuid)), 0) AS v', [$loc->id, $loc->id])->v;
        $naRua = [];
        foreach (DB::select('SELECT lu.locacao_item_id, lu.unidade_id, u.patrimonio FROM locacao_unidades lu
                               JOIN unidades u ON u.id = lu.unidade_id JOIN locacao_itens li ON li.id = lu.locacao_item_id
                              WHERE li.locacao_id = ? AND lu.devolucao_id IS NULL ORDER BY u.patrimonio', [$loc->id]) as $r) {
            $naRua[$r->locacao_item_id][] = $r;
        }
        $desta = array_sum(array_map(fn ($it) => ($it->quantidade - $it->qtd_devolvida) * $it->preco_hoje, $itens));
        $ajuste = $loc->acrescimo + $loc->frete_entrega + $loc->frete_recolhimento - $loc->desconto;

        return view('locacoes.devolucao', [
            'loc'     => $loc,
            'itens'   => $itens,
            'apurado' => $apurado,
            'ajuste'  => $ajuste,
            'naRua'   => $naRua,
            'dias'    => $dias,
            'sugerido'=> max(0, $apurado + $desta + $ajuste - $loc->valor_pago),
        ]);
    }

    public function store(Request $request, int $numero): RedirectResponse
    {
        $this->locacao($numero);
        try {
            $total = $this->registrar($request, $numero);
        } catch (Recusa $e) {
            return back()->withErrors(['devolucao' => $e->getMessage()])->withInput();
        }
        return redirect()->route('locacoes.show', $numero)->with('flash', [['tipo' => 'ok',
            'msg' => $total ? "Devolução registrada. Locação nº $numero encerrada." : 'Devolução parcial registrada. Ainda há itens pendentes.']]);
    }

    /** Devolve true se a locação ficou encerrada (nada mais pendente). */
    private function registrar(Request $request, int $numero): bool
    {
        $data = (string) $request->input('data', '');
        $multa = $this->reais($request->input('multa'));
        $avaria = $this->reais($request->input('avaria'));
        $receber = $this->reais($request->input('receber'));
        $obs = trim((string) $request->input('obs', ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) || !checkdate((int) substr($data, 5, 2), (int) substr($data, 8, 2), (int) substr($data, 0, 4))) {
            throw new Recusa('Informe a data da devolução.');
        }
        if ($multa < 0 || $avaria < 0 || $receber < 0) {
            throw new Recusa('Multa, avaria e valor recebido não podem ser negativos.');
        }
        if (mb_strlen($obs) > 2000) {
            throw new Recusa('Observações muito longas (máximo de 2.000 caracteres).');
        }
        $forma = '';
        if ($receber > 0) {
            $forma = (string) $request->input('forma', '');
            if (!in_array($forma, Estoque::FORMAS_PGTO, true)) {
                throw new Recusa('Escolha a forma de pagamento.');
            }
        }
        $qtds = (array) $request->input('qtd', []);
        $perdaQtds = (array) $request->input('perda', []);
        $marcadas = array_values(array_filter(array_map('strval', (array) $request->input('unidades', [])), fn ($u) => Str::isUuid($u)));

        return DB::transaction(function () use ($numero, $data, $multa, $avaria, $receber, $forma, $obs, $qtds, $perdaQtds, $marcadas) {
            $loc = DB::selectOne('SELECT l.*, c.nome AS cliente_nome FROM locacoes l JOIN clientes c ON c.id = l.cliente_id WHERE l.numero = ? FOR UPDATE OF l', [$numero]);
            if ($loc->status !== 'aberta') {
                throw new Recusa('Só é possível registrar devolução de locação em aberto.');
            }
            if ($data < $loc->data_saida || $data > now()->toDateString()) {
                throw new Recusa('A data da devolução deve estar entre a saída (' . data_br($loc->data_saida) . ') e hoje.');
            }
            $dias = Locacoes::diasCobranca($loc->data_saida, $data);
            $itens = DB::select('SELECT li.id, li.quantidade, li.qtd_devolvida, p.descricao, p.id AS produto_id, p.valor_reposicao, ' . self::PRECO . ' AS preco
                                   FROM locacao_itens li JOIN produtos p ON p.id = li.produto_id
                                  WHERE li.locacao_id = ? ORDER BY p.descricao FOR UPDATE OF li', [$dias, $dias, $loc->id]);
            $devolver = [];
            $perdas = [];
            foreach ($itens as $it) {
                $pendente = $it->quantidade - $it->qtd_devolvida;
                $txt = trim((string) ($qtds[$it->id] ?? '0'));
                $q = $txt === '' ? 0 : filter_var($txt, FILTER_VALIDATE_INT);
                if ($q === false || $q < 0) {
                    throw new Recusa("Quantidade inválida para {$it->descricao}.");
                }
                if ($q > $pendente) {
                    throw new Recusa("Não é possível devolver $q de {$it->descricao}: só há $pendente pendente(s).");
                }
                // Perdido ou extraviado: não volta, sai do estoque e é cobrado pelo valor de reposição
                $txtPerda = trim((string) ($perdaQtds[$it->id] ?? '0'));
                $p = $txtPerda === '' ? 0 : filter_var($txtPerda, FILTER_VALIDATE_INT);
                if ($p === false || $p < 0) {
                    throw new Recusa("Quantidade perdida inválida para {$it->descricao}.");
                }
                if ($q + $p > $pendente) {
                    throw new Recusa("Devolvidos e perdidos de {$it->descricao} somam mais que o pendente ($pendente).");
                }
                if ($p > 0) {
                    if (DB::selectOne('SELECT 1 AS ok FROM locacao_unidades WHERE locacao_item_id = ? AND devolucao_id IS NULL', [$it->id])) {
                        throw new Recusa("{$it->descricao} tem patrimônio: a perda de unidade não é registrada aqui.");
                    }
                    $perdas[] = ['item' => $it, 'qtd' => $p, 'reposicao' => (float) $it->valor_reposicao, 'valor' => round($p * (float) $it->valor_reposicao, 2)];
                }
                if ($q > 0) {
                    $devolver[] = ['item' => $it, 'qtd' => $q, 'valor' => round($q * (float) $it->preco, 2)];
                }
            }
            // Itens que não pertencem a esta locação não são aceitos
            $validos = array_column($itens, 'id');
            foreach ($qtds as $k => $v) {
                if (!in_array((string) $k, $validos, true) && (int) $v !== 0) {
                    throw new Recusa('Item inválido na devolução.');
                }
            }
            if (!$devolver && !$perdas) {
                throw new Recusa('Informe a quantidade de pelo menos um item a devolver.');
            }
            // A reposição das perdas entra na avaria (já vai para o total e para o saldo da locação)
            $perdaTotal = array_sum(array_column($perdas, 'valor'));
            $linhasPerda = array_map(fn ($x) => "Perda/extravio: {$x['qtd']} x {$x['item']->descricao} = " . moeda($x['valor']) . ' (valor de reposição).', $perdas);
            $obsDev = trim(implode("\n", array_filter([$obs, ...$linhasPerda])));

            $devId = DB::selectOne('INSERT INTO devolucoes (locacao_id, data, multa, avaria, obs, usuario_id) VALUES (?, ?, ?, ?, ?, ?) RETURNING id',
                                   [$loc->id, $data, $multa, $avaria + $perdaTotal, $obsDev, session('usuario.id')])->id;
            foreach ($devolver as $dv) {
                DB::insert('INSERT INTO devolucao_itens (devolucao_id, locacao_item_id, quantidade, dias, valor) VALUES (?, ?, ?, ?, ?)',
                           [$devId, $dv['item']->id, $dv['qtd'], $dias, $dv['valor']]);
                DB::update('UPDATE locacao_itens SET qtd_devolvida = qtd_devolvida + ? WHERE id = ?', [$dv['qtd'], $dv['item']->id]);
            }
            foreach ($perdas as $pd) {
                DB::insert('INSERT INTO devolucao_perdas (devolucao_id, locacao_item_id, quantidade, valor_reposicao, valor) VALUES (?, ?, ?, ?, ?)',
                           [$devId, $pd['item']->id, $pd['qtd'], $pd['reposicao'], $pd['valor']]);
                DB::update('UPDATE locacao_itens SET qtd_devolvida = qtd_devolvida + ? WHERE id = ?', [$pd['qtd'], $pd['item']->id]);
                DB::update('UPDATE produtos SET qtd_total = qtd_total - ? WHERE id = ?', [$pd['qtd'], $pd['item']->produto_id]);
            }
            $this->unidades($devId, $devolver, $marcadas);

            // Nada mais pendente: a locação está encerrada
            $pendentes = (int) DB::selectOne('SELECT COALESCE(SUM(quantidade - qtd_devolvida), 0) AS n FROM locacao_itens WHERE locacao_id = ?', [$loc->id])->n;
            if ($pendentes === 0) {
                DB::update("UPDATE locacoes SET status = 'devolvida' WHERE id = ?", [$loc->id]);
            }
            if ($receber > 0) {
                Locacoes::lancarCaixa($loc, 'E', $receber, $forma, now()->toDateString(), 'Recebimento');
            }
            return $pendentes === 0;
        });
    }

    /** Unidades que voltaram. Item devolvido por inteiro libera todas; em parcial voltam as marcadas. Igual a devolucao_unidades(). */
    private function unidades(string $devId, array $devolver, array $marcadas): void
    {
        foreach ($devolver as $dv) {
            $it = $dv['item'];
            $naRua = array_column(DB::select('SELECT unidade_id FROM locacao_unidades WHERE locacao_item_id = ? AND devolucao_id IS NULL FOR UPDATE', [$it->id]), 'unidade_id');
            if (!$naRua) {
                continue;
            }
            $pendenteDepois = $it->quantidade - $it->qtd_devolvida - $dv['qtd'];
            $voltam = $pendenteDepois === 0 ? $naRua : array_values(array_intersect($naRua, $marcadas));
            if (count($voltam) > $dv['qtd']) {
                throw new Recusa("Foram marcadas mais unidades de {$it->descricao} do que a quantidade devolvida ({$dv['qtd']}).");
            }
            if (count($naRua) - count($voltam) > $pendenteDepois) {
                throw new Recusa("Marque quais unidades (patrimônio) de {$it->descricao} voltaram.");
            }
            if ($voltam) {
                DB::update('UPDATE locacao_unidades SET devolucao_id = ? WHERE locacao_item_id = ? AND devolucao_id IS NULL AND unidade_id = ANY (CAST(? AS uuid[]))',
                           [$devId, $it->id, '{' . implode(',', $voltam) . '}']);
            }
        }
    }

    private function reais($v): float
    {
        $n = valor_br($v ?? '');
        if ($n === null) {
            throw new Recusa('Valor inválido: ' . trim(str_replace(['R$', ' '], '', (string) $v)));
        }
        return $n;
    }

    private function locacao(int $numero): object
    {
        abort_if($numero < 1 || $numero > 999999999, 404);
        $loc = Locacoes::porNumero($numero);
        abort_if($loc === null, 404, 'Locação não encontrada.');
        return $loc;
    }
}
