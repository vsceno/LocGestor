<?php

namespace App\Http\Controllers;

use App\Support\Contas;
use App\Support\Estoque;
use App\Support\Formulario;
use App\Support\Recusa;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Caixa: contas a pagar (avulsas e recorrentes), pagamento que vira saída no caixa e previsão de caixa.
 * Só o administrador cria recorrente, desfaz pagamento e exclui conta, como no sistema antigo.
 */
class ContasPagarController extends Controller
{
    private const POR_PAGINA = 30;

    public function index(Request $request): View
    {
        Contas::gerarRecorrentes();
        $situacao = array_key_exists((string) $request->query('situacao'), Contas::SITUACOES) ? (string) $request->query('situacao') : 'abertas';
        $where = match ($situacao) {
            'pagas' => 'c.pago_em IS NOT NULL',
            'todas' => 'TRUE',
            default => 'c.pago_em IS NULL',
        };
        $pagina = max(1, (int) $request->query('page', 1));
        $total = (int) DB::selectOne("SELECT count(*) AS n FROM contas_pagar c WHERE $where")->n;
        $linhas = DB::select("SELECT c.*, r.ativa AS recorrente_ativa FROM contas_pagar c LEFT JOIN contas_recorrentes r ON r.id = c.recorrente_id
                               WHERE $where ORDER BY " . ($situacao === 'pagas' ? 'c.pago_em DESC' : 'c.vencimento, c.descricao') . ' LIMIT ? OFFSET ?',
                             [self::POR_PAGINA, ($pagina - 1) * self::POR_PAGINA]);

        $recorrente = (string) $request->query('recorrente', '');

        return view('caixa.contas', [
            'contas' => new LengthAwarePaginator($linhas, $total, self::POR_PAGINA, $pagina, ['path' => route('caixa.contas'), 'query' => ['situacao' => $situacao]]),
            'situacao' => $situacao,
            'situacoes' => Contas::SITUACOES,
            'resumo' => DB::selectOne("SELECT COALESCE(SUM(valor) FILTER (WHERE vencimento < CURRENT_DATE), 0) AS vencidas,
                                              COALESCE(SUM(valor) FILTER (WHERE vencimento BETWEEN CURRENT_DATE AND CURRENT_DATE + 30), 0) AS proximas
                                         FROM contas_pagar WHERE pago_em IS NULL"),
            'recorrentes' => DB::select('SELECT * FROM contas_recorrentes ORDER BY ativa DESC, dia, descricao'),
            'categorias' => array_column(DB::select("SELECT categoria FROM caixa WHERE categoria <> '' UNION SELECT categoria FROM contas_pagar ORDER BY 1"), 'categoria'),
            'editar' => Str::isUuid($recorrente) ? DB::selectOne('SELECT * FROM contas_recorrentes WHERE id = ?', [$recorrente]) : null,
            'admin' => session('usuario.perfil') === 'admin',
        ]);
    }

    /** Conta avulsa. */
    public function store(Request $request): RedirectResponse
    {
        [$c, $e] = Contas::campos($request->all());
        $vencimento = (string) $request->input('vencimento', '');
        if (!Formulario::dataValida($vencimento)) {
            $e['vencimento'] = 'Informe o vencimento.';
        }
        if ($e) {
            return back()->withErrors($e, 'conta')->withInput();
        }
        DB::insert('INSERT INTO contas_pagar (descricao, categoria, valor, vencimento, usuario_id) VALUES (?, ?, ?, ?, ?)',
                   [$c['descricao'], $c['categoria'], $c['valor'], $vencimento, session('usuario.id')]);
        return redirect()->route('caixa.contas')->with('flash', [['tipo' => 'ok', 'msg' => 'Conta incluída.']]);
    }

    /** Conta recorrente (só administrador): gera as parcelas dos próximos 90 dias. */
    public function recorrenteStore(Request $request): RedirectResponse
    {
        $this->soAdmin();
        [$d, $e] = $this->recorrente($request);
        if ($e) {
            return back()->withErrors($e, 'recorrente')->withInput();
        }
        DB::insert('INSERT INTO contas_recorrentes (descricao, categoria, valor, dia, inicio, ativa) VALUES (?, ?, ?, ?, ?, ?)',
                      [$d['descricao'], $d['categoria'], $d['valor'], $d['dia'], $d['inicio'], $d['ativa'] ? 'true' : 'false']);
        Contas::gerarRecorrentes();
        return redirect()->route('caixa.contas')->with('flash', [['tipo' => 'ok', 'msg' => 'Conta recorrente incluída: as parcelas já aparecem na lista.']]);
    }

    /**
     * Alterar o modelo atualiza as parcelas futuras ainda em aberto. Mudar o dia ou o início, ou desativar,
     * refaz essas parcelas (desativada, somem).
     */
    public function recorrenteUpdate(Request $request, string $id): RedirectResponse
    {
        $this->soAdmin();
        abort_unless(Str::isUuid($id), 404);
        [$d, $e] = $this->recorrente($request);
        if ($e) {
            return back()->withErrors($e, 'recorrente')->withInput();
        }
        try {
            DB::transaction(function () use ($d, $id) {
                $antes = DB::selectOne('SELECT dia, inicio FROM contas_recorrentes WHERE id = ? FOR UPDATE', [$id]);
                if ($antes === null) {
                    throw new Recusa('Conta recorrente não encontrada.');
                }
                $refazer = !$d['ativa'] || (int) $antes->dia !== (int) $d['dia'] || $antes->inicio !== $d['inicio'];
                DB::update('UPDATE contas_recorrentes SET descricao = ?, categoria = ?, valor = ?, dia = ?, inicio = ?, ativa = ?'
                           . ($refazer ? ', gerado_ate = NULL' : '') . ' WHERE id = ?',
                           [$d['descricao'], $d['categoria'], $d['valor'], $d['dia'], $d['inicio'], $d['ativa'] ? 'true' : 'false', $id]);
                if ($refazer) {
                    DB::delete('DELETE FROM contas_pagar WHERE recorrente_id = ? AND pago_em IS NULL AND vencimento >= CURRENT_DATE', [$id]);
                    // Não regera o que já passou: continua do último vencimento mantido
                    DB::update('UPDATE contas_recorrentes SET gerado_ate = (SELECT max(vencimento) FROM contas_pagar WHERE recorrente_id = ?) WHERE id = ?',
                               [$id, $id]);
                } else {
                    DB::update('UPDATE contas_pagar SET descricao = ?, categoria = ?, valor = ?
                                 WHERE recorrente_id = ? AND pago_em IS NULL AND vencimento >= CURRENT_DATE',
                               [$d['descricao'], $d['categoria'], $d['valor'], $id]);
                }
            });
        } catch (Recusa $ex) {
            return redirect()->route('caixa.contas')->with('flash', [['tipo' => 'erro', 'msg' => $ex->getMessage()]]);
        }
        Contas::gerarRecorrentes();
        return redirect()->route('caixa.contas')->with('flash', [['tipo' => 'ok', 'msg' => 'Conta recorrente atualizada.']]);
    }

    /** Paga a conta inteira: lança a saída no caixa e marca como paga. */
    public function pagar(Request $request, string $id): RedirectResponse
    {
        abort_unless(Str::isUuid($id), 404);
        $data = (string) $request->input('data', '');
        $forma = (string) $request->input('forma', '');
        try {
            DB::transaction(function () use ($id, $data, $forma) {
                if (!in_array($forma, Estoque::FORMAS_PGTO, true)) {
                    throw new Recusa('Escolha a forma de pagamento.');
                }
                if (!Formulario::dataValida($data) || $data > now()->toDateString()) {
                    throw new Recusa('Informe a data do pagamento (até hoje).');
                }
                $c = DB::selectOne('SELECT * FROM contas_pagar WHERE id = ? FOR UPDATE', [$id]);
                if ($c === null) {
                    throw new Recusa('Conta não encontrada.');
                }
                if ($c->pago_em !== null) {
                    throw new Recusa('Esta conta já foi paga.');
                }
                $caixa = DB::selectOne("INSERT INTO caixa (data, tipo, categoria, descricao, valor, forma, usuario_id)
                                        VALUES (?, 'S', ?, ?, ?, ?, ?) RETURNING id",
                                       [$data, $c->categoria, $c->descricao, $c->valor, $forma, session('usuario.id')])->id;
                DB::update('UPDATE contas_pagar SET pago_em = ?, caixa_id = ? WHERE id = ?', [$data, $caixa, $id]);
            });
        } catch (Recusa $e) {
            return back()->with('flash', [['tipo' => 'erro', 'msg' => $e->getMessage()]]);
        }
        return redirect()->route('caixa.contas')->with('flash', [['tipo' => 'ok', 'msg' => 'Conta paga e lançada no caixa.']]);
    }

    /** Desfaz o pagamento (só administrador): apaga a saída do caixa e a conta volta a ficar em aberto. */
    public function desfazer(string $id): RedirectResponse
    {
        $this->soAdmin();
        abort_unless(Str::isUuid($id), 404);
        try {
            DB::transaction(function () use ($id) {
                $c = DB::selectOne('SELECT caixa_id FROM contas_pagar WHERE id = ? AND pago_em IS NOT NULL FOR UPDATE', [$id]);
                if ($c === null) {
                    throw new Recusa('Conta paga não encontrada.');
                }
                DB::update('UPDATE contas_pagar SET pago_em = NULL, caixa_id = NULL WHERE id = ?', [$id]);
                DB::delete('DELETE FROM caixa WHERE id = ?', [$c->caixa_id]);
            });
        } catch (Recusa $e) {
            return back()->with('flash', [['tipo' => 'erro', 'msg' => $e->getMessage()]]);
        }
        return redirect()->route('caixa.contas', ['situacao' => 'todas'])
            ->with('flash', [['tipo' => 'ok', 'msg' => 'Pagamento desfeito: a conta voltou a ficar em aberto.']]);
    }

    /** Só conta em aberto se exclui (a paga fica no caixa; para voltar, desfaz o pagamento). */
    public function excluir(string $id): RedirectResponse
    {
        $this->soAdmin();
        abort_unless(Str::isUuid($id), 404);
        if (DB::delete('DELETE FROM contas_pagar WHERE id = ? AND pago_em IS NULL', [$id]) === 0) {
            return back()->with('flash', [['tipo' => 'erro', 'msg' => 'Só é possível excluir conta em aberto.']]);
        }
        return redirect()->route('caixa.contas')->with('flash', [['tipo' => 'ok', 'msg' => 'Conta excluída.']]);
    }

    public function previsao(Request $request): View
    {
        $dias = in_array((int) $request->query('dias'), [30, 60, 90], true) ? (int) $request->query('dias') : 60;
        $p = Contas::previsao($dias);
        $semanas = $p['semanas'];
        $final = $semanas ? end($semanas)['saldo'] : $p['saldo'];
        $menor = min(array_merge([$p['saldo']], array_column($semanas, 'saldo')));   // inclui o ponto de partida

        return view('caixa.previsao', [
            'dias' => $dias,
            'p' => $p,
            'final' => $final,
            'menor' => $menor,
            'grafico' => ['tipo' => 'line', 'moeda' => true,
                          'rotulos' => array_map(fn ($s) => date('d/m', strtotime($s['inicio'])), $semanas),
                          'series' => [['nome' => 'Saldo projetado', 'cor' => '#1a73e8', 'dados' => array_column($semanas, 'saldo')]]],
        ]);
    }

    /** Campos da recorrente (dia de 1 a 28, início e ativa). Devolve [campos, erros]. */
    private function recorrente(Request $request): array
    {
        [$c, $e] = Contas::campos($request->all());
        $dia = filter_var($request->input('dia', ''), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 28]]);
        $inicio = (string) $request->input('inicio', '');
        if ($dia === false) {
            $e['dia'] = 'O dia do vencimento deve ser de 1 a 28 (vale para todos os meses).';
        }
        if (!Formulario::dataValida($inicio)) {
            $e['inicio'] = 'Informe a partir de quando a conta vence.';
        }
        return [$c + ['dia' => $dia, 'inicio' => $inicio, 'ativa' => $request->boolean('ativa')], $e];
    }

    private function soAdmin(): void
    {
        abort_unless(session('usuario.perfil') === 'admin', 403, 'Acesso restrito ao administrador.');
    }
}
