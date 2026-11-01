<?php

namespace App\Http\Controllers;

use App\Support\Documentos;
use App\Support\Formulario;
use App\Support\Locacoes;
use App\Support\Recusa;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Orçamentos: cotação com validade. Não bloqueia estoque e não é cobrança; ao virar locação ou reserva fica
 * "convertido" e não pode mais ser excluído. Mesmas regras de app/orcamento.php do sistema antigo.
 */
class OrcamentosController extends Controller
{
    public const VALIDADE_MAX_DIAS = 90;
    public const SITUACOES = ['aberto' => 'Em aberto', 'convertido' => 'Convertidos', 'todos' => 'Todos'];

    /** Valor Total de cada orçamento: itens pelo período (funções de preço do banco) + acréscimos e fretes − desconto. */
    private const PREVISTO = "(SELECT COALESCE(SUM(oi.quantidade * CASE WHEN oi.periodo = 'automatico'
                                   THEN preco_periodo(o.data_prev_devolucao - o.data_saida + 1, oi.valor_diaria, oi.valor_semanal, oi.valor_mensal)
                                   ELSE preco_plano(o.data_prev_devolucao - o.data_saida + 1, oi.periodo, oi.valor_diaria, oi.valor_semanal, oi.valor_quinzenal, oi.valor_mensal) END), 0)
                                FROM orcamento_itens oi WHERE oi.orcamento_id = o.id) + o.acrescimo + o.frete_entrega + o.frete_recolhimento - o.desconto";

    public function index(Request $request): View
    {
        $situacao = (string) $request->query('situacao', 'aberto');
        $situacao = array_key_exists($situacao, self::SITUACOES) ? $situacao : 'aberto';
        $where = $situacao === 'todos' ? 'TRUE' : 'o.status = ?';
        $params = $situacao === 'todos' ? [] : [$situacao];
        $contagem = DB::selectOne("SELECT count(*) FILTER (WHERE status = 'aberto') AS aberto, count(*) FILTER (WHERE status = 'convertido') AS convertido, count(*) AS todos FROM orcamentos");
        $porPagina = config('locgestor.por_pagina');
        $pagina = max(1, (int) $request->query('page', 1));
        $linhas = DB::select("SELECT o.id, o.numero, o.emissao, o.validade, o.data_saida, o.data_prev_devolucao, o.status, o.locacao_id, o.reserva_id,
                                     c.nome, l.numero AS locacao_numero, r.numero AS reserva_numero, " . self::PREVISTO . " AS previsto
                                FROM orcamentos o JOIN clientes c ON c.id = o.cliente_id
                                LEFT JOIN locacoes l ON l.id = o.locacao_id LEFT JOIN reservas r ON r.id = o.reserva_id
                               WHERE $where ORDER BY o.numero DESC LIMIT ? OFFSET ?", [...$params, $porPagina, ($pagina - 1) * $porPagina]);
        $orcamentos = new LengthAwarePaginator($linhas, (int) $contagem->$situacao, $porPagina, $pagina, ['path' => route('orcamentos.index'), 'query' => ['situacao' => $situacao]]);
        return view('orcamentos.index', ['orcamentos' => $orcamentos, 'situacao' => $situacao, 'situacoes' => self::SITUACOES, 'contagem' => $contagem]);
    }

    public function show(string $id): View
    {
        $o = $this->orcamento($id);
        $itens = DB::select('SELECT oi.quantidade, oi.periodo, oi.valor_diaria, oi.valor_semanal, oi.valor_quinzenal, oi.valor_mensal, p.codigo, p.descricao, p.unidade
                               FROM orcamento_itens oi JOIN produtos p ON p.id = oi.produto_id WHERE oi.orcamento_id = ? ORDER BY p.descricao', [$id]);
        $empresa = DB::selectOne('SELECT nome_fantasia, razao_social FROM empresa');
        $whatsapp = null;
        if ($num = whatsapp_numero((object) ['celular' => $o->celular, 'telefone' => $o->telefone])) {
            $nome = trim($o->cliente_nome);
            $chamar = $o->cliente_tipo === 'PJ' ? $nome : (explode(' ', $nome)[0] ?? $nome);
            // Mesmo texto do sistema antigo
            $msg = "Olá, $chamar! Aqui é da *" . ($empresa->nome_fantasia ?: $empresa->razao_social) . '*.'
                 . " Segue o orçamento nº {$o->numero}: " . moeda($o->previsto) . ' até ' . data_br($o->validade) . '.';
            $whatsapp = 'https://wa.me/' . $num . '?text=' . rawurlencode($msg);
        }
        return view('orcamentos.show', compact('o', 'itens', 'whatsapp'));
    }

    public function create(): View
    {
        return view('orcamentos.form', [
            'clientes' => DB::select('SELECT nome, cpf_cnpj FROM clientes WHERE ativo AND NOT bloqueado ORDER BY lower(nome)'),
            'produtos' => DB::select('SELECT id, codigo, descricao, unidade, valor_diaria, valor_semanal, valor_quinzenal, valor_mensal FROM produtos WHERE ativo ORDER BY descricao'),
            'admin'    => session('usuario.perfil') === 'admin',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        try {
            $r = $this->salvar($request);
        } catch (Recusa $e) {
            return back()->withErrors(['orcamento' => $e->getMessage()])->withInput($request->except('senha_admin'));
        }
        return redirect()->route('orcamentos.show', $r['id'])->with('flash', [['tipo' => 'ok', 'msg' => "Orçamento nº {$r['numero']} salvo."]]);
    }

    /** Vira locação (saída já feita) ou reserva (saída futura), com as regras da nova locação e da reserva. */
    public function converter(Request $request, string $id): RedirectResponse
    {
        $this->orcamento($id);
        $tipo = (string) $request->input('tipo', '');
        try {
            $r = DB::transaction(function () use ($id, $tipo) {
                if (!in_array($tipo, ['locacao', 'reserva'], true)) {
                    throw new Recusa('Escolha se o orçamento vira locação ou reserva.');
                }
                $o = DB::selectOne('SELECT o.*, c.nome, c.cpf_cnpj FROM orcamentos o JOIN clientes c ON c.id = o.cliente_id WHERE o.id = ? FOR UPDATE OF o', [$id]);
                if ($o->status !== 'aberto') {
                    throw new Recusa('Este orçamento já foi convertido.');
                }
                if ($o->validade < now()->toDateString()) {
                    throw new Recusa('Orçamento vencido em ' . data_br($o->validade) . '. Faça um novo orçamento.');
                }
                $itens = DB::select('SELECT produto_id, quantidade, periodo, valor_diaria, valor_semanal, valor_quinzenal, valor_mensal FROM orcamento_itens WHERE orcamento_id = ?', [$id]);
                $linhas = [
                    'cliente'    => $o->nome . ($o->cpf_cnpj !== '' ? ' · ' . $o->cpf_cnpj : ''),
                    'obs'        => trim('Do orçamento nº ' . $o->numero . '. ' . $o->obs),
                    'produto_id' => array_column($itens, 'produto_id'),
                    'quantidade' => array_map(fn ($i) => (string) $i->quantidade, $itens),
                    'periodo'    => array_column($itens, 'periodo'),
                ];
                if ($tipo === 'locacao') {
                    if ($o->data_saida > now()->toDateString()) {
                        throw new Recusa('A saída é no futuro: converta em reserva (a locação só registra o que já saiu).');
                    }
                    $dados = $linhas + [
                        'data_saida' => $o->data_saida, 'data_prev_devolucao' => $o->data_prev_devolucao, 'local_uso' => $o->local_uso,
                        'acrescimo' => $o->acrescimo, 'desconto' => $o->desconto, 'frete_entrega' => $o->frete_entrega, 'frete_recolhimento' => $o->frete_recolhimento,
                        // O preço negociado vai como o valor do período escolhido
                        'diaria' => array_map(fn ($i) => in_array($i->periodo, ['semanal', 'quinzenal', 'mensal'], true) ? $i->{'valor_' . $i->periodo} : $i->valor_diaria, $itens),
                    ];
                    $l = app(NovaLocacaoController::class)->criar(Request::create('', 'POST', $dados), $o->desconto_autorizado_por ?: null);
                    DB::update("UPDATE orcamentos SET status = 'convertido', locacao_id = ? WHERE id = ?", [$l['id'], $id]);
                    return ['tipo' => 'locacao', 'numero' => $l['numero']];
                }
                // Reserva: não guarda preço (o valor é calculado na retirada)
                $numero = app(ReservasController::class)->salvar(Request::create('', 'POST', $linhas + ['data_inicio' => $o->data_saida, 'data_fim' => $o->data_prev_devolucao]), null);
                DB::update("UPDATE orcamentos SET status = 'convertido', reserva_id = (SELECT id FROM reservas WHERE numero = ?) WHERE id = ?", [$numero, $id]);
                return ['tipo' => 'reserva', 'numero' => $numero];
            });
        } catch (Recusa $e) {
            return redirect()->route('orcamentos.show', $id)->with('flash', [['tipo' => 'erro', 'msg' => $e->getMessage()]]);
        }
        return $r['tipo'] === 'locacao'
            ? redirect()->route('locacoes.show', $r['numero'])->with('flash', [['tipo' => 'ok', 'msg' => "Orçamento convertido em locação nº {$r['numero']}."]])
            : redirect()->route('reservas.show', $r['numero'])->with('flash', [['tipo' => 'ok', 'msg' => "Orçamento convertido em reserva nº {$r['numero']}."]]);
    }

    /** Impressão A4 do orçamento: mesmos itens e valores da ficha (orcamentos_imprimir() do sistema antigo). */
    public function imprimir(string $id): View
    {
        $o = $this->orcamento($id);
        $dias = Locacoes::diasCobranca($o->data_saida, $o->data_prev_devolucao);
        $itens = DB::select("SELECT oi.quantidade, oi.periodo, oi.valor_diaria, oi.valor_semanal, oi.valor_quinzenal, oi.valor_mensal,
                                    p.descricao, p.unidade,
                                    CASE WHEN oi.periodo = 'automatico'
                                         THEN preco_periodo(CAST(? AS integer), oi.valor_diaria, oi.valor_semanal, oi.valor_mensal)
                                         ELSE preco_plano(CAST(? AS integer), oi.periodo, oi.valor_diaria, oi.valor_semanal, oi.valor_quinzenal, oi.valor_mensal)
                                    END AS preco_total
                               FROM orcamento_itens oi JOIN produtos p ON p.id = oi.produto_id
                              WHERE oi.orcamento_id = ? ORDER BY p.descricao", [$dias, $dias, $o->id]);
        return view('impressao.orcamento', [
            'o'        => $o,
            'dias'     => $dias,
            'itens'    => Documentos::preparar($itens),
            'subtotal' => array_sum(array_map(fn ($i) => $i->quantidade * (float) $i->preco_total, $itens)),
            'empresa'  => DB::selectOne('SELECT * FROM empresa'),
        ]);
    }

    public function destroy(string $id): RedirectResponse
    {
        $this->orcamento($id);
        if (DB::delete("DELETE FROM orcamentos WHERE id = ? AND status = 'aberto'", [$id]) === 0) {
            return redirect()->route('orcamentos.show', $id)->with('flash', [['tipo' => 'erro', 'msg' => 'Só é possível excluir orçamento que ainda não foi convertido.']]);
        }
        return redirect()->route('orcamentos.index')->with('flash', [['tipo' => 'ok', 'msg' => 'Orçamento excluído.']]);
    }

    /** @return array{id: string, numero: int} Mesmas regras de orcamento_salvar(). */
    private function salvar(Request $request): array
    {
        $cliente = Formulario::cliente((string) $request->input('cliente', ''));
        if ($cliente->bloqueado) {
            throw new Recusa("O cliente {$cliente->nome} está bloqueado e não pode receber orçamento.");
        }
        $saida = (string) $request->input('data_saida', '');
        $prev = (string) $request->input('data_prev_devolucao', '');
        $validade = (string) $request->input('validade', '');
        if (!Formulario::dataValida($saida) || !Formulario::dataValida($prev) || !Formulario::dataValida($validade)) {
            throw new Recusa('Informe as datas de saída, de devolução e a validade do orçamento.');
        }
        if ($prev < $saida) {
            throw new Recusa('A devolução prevista não pode ser antes da saída.');
        }
        if ($validade < now()->toDateString() || $validade > now()->addDays(self::VALIDADE_MAX_DIAS)->toDateString()) {
            throw new Recusa('A validade deve ser de hoje até ' . self::VALIDADE_MAX_DIAS . ' dias.');
        }
        $v = [];
        foreach (['acrescimo', 'desconto', 'frete_entrega', 'frete_recolhimento'] as $campo) {
            $v[$campo] = Formulario::reais($request->input($campo));
            if ($v[$campo] < 0) {
                throw new Recusa('Acréscimos, descontos e fretes não podem ser negativos.');
            }
        }
        $local = trim((string) $request->input('local_uso', ''));
        $obs = trim((string) $request->input('obs', ''));
        if (mb_strlen($local) > 200 || mb_strlen($obs) > 2000) {
            throw new Recusa('Local de uso (até 200) ou observações (até 2.000) longos demais.');
        }
        $itens = Formulario::itens($request, 'no orçamento');
        $cadastro = [];
        foreach (DB::select('SELECT id, descricao, valor_diaria, valor_semanal, valor_quinzenal, valor_mensal FROM produtos WHERE id = ANY (CAST(? AS uuid[]))',
                            ['{' . implode(',', array_keys($itens)) . '}']) as $p) {
            $cadastro[$p->id] = $p;
        }
        foreach ($itens as $pid => &$it) {
            if (!isset($cadastro[$pid])) {
                throw new Recusa('Produto não encontrado.');
            }
            $pr = $it['periodo'];
            if (in_array($pr, ['semanal', 'quinzenal', 'mensal'], true)) {
                $precoPlano = (float) ($it['diaria'] ?? $cadastro[$pid]->{'valor_' . $pr});
                if ($precoPlano <= 0) {
                    throw new Recusa("Informe o valor {$pr} de {$cadastro[$pid]->descricao} ou escolha outro período.");
                }
                $it['valores'] = ['valor_' . $pr => $precoPlano];
                $it['diaria'] = (float) $cadastro[$pid]->valor_diaria;
            }
            $it['diaria'] ??= (float) $cadastro[$pid]->valor_diaria;
            if ($it['diaria'] < 0) {
                throw new Recusa('A diária não pode ser negativa.');
            }
        }
        unset($it);
        $autorizado = Formulario::autorizador($v['acrescimo'], $v['desconto'], (string) $request->input('senha_admin', ''));

        return DB::transaction(function () use ($cliente, $saida, $prev, $validade, $v, $local, $obs, $itens, $cadastro, $autorizado) {
            $numero = (int) DB::selectOne("SELECT proximo_numero('orcamento') AS n")->n;
            $id = DB::selectOne('INSERT INTO orcamentos (numero, cliente_id, validade, data_saida, data_prev_devolucao, local_uso, acrescimo, desconto,
                                                        frete_entrega, frete_recolhimento, obs, usuario_id, desconto_autorizado_por)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
                                [$numero, $cliente->id, $validade, $saida, $prev, $local, $v['acrescimo'], $v['desconto'], $v['frete_entrega'],
                                 $v['frete_recolhimento'], $obs, session('usuario.id'), $autorizado])->id;
            foreach ($itens as $pid => $it) {
                $val = $it['valores'] ?? [];
                $p = $cadastro[$pid];
                DB::insert('INSERT INTO orcamento_itens (orcamento_id, produto_id, quantidade, valor_diaria, valor_semanal, valor_quinzenal, valor_mensal, periodo)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                           [$id, $pid, $it['qtd'], $it['diaria'], $val['valor_semanal'] ?? $p->valor_semanal, $val['valor_quinzenal'] ?? $p->valor_quinzenal,
                            $val['valor_mensal'] ?? $p->valor_mensal, $it['periodo']]);
            }
            return ['id' => $id, 'numero' => $numero];
        });
    }

    private function orcamento(string $id): object
    {
        abort_unless(Str::isUuid($id), 404);
        $o = DB::selectOne('SELECT o.*, c.nome AS cliente_nome, c.cpf_cnpj AS cliente_doc, c.tipo AS cliente_tipo, c.celular, c.telefone,
                                   l.numero AS locacao_numero, r.numero AS reserva_numero, ' . self::PREVISTO . ' AS previsto
                              FROM orcamentos o JOIN clientes c ON c.id = o.cliente_id
                              LEFT JOIN locacoes l ON l.id = o.locacao_id LEFT JOIN reservas r ON r.id = o.reserva_id WHERE o.id = ?', [$id]);
        abort_if($o === null, 404, 'Orçamento não encontrado.');
        return $o;
    }
}
