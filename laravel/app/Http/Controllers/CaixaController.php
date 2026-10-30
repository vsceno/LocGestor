<?php

namespace App\Http\Controllers;

use App\Support\Estoque;
use App\Support\Formulario;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Fluxo de caixa: extrato do período com saldo anterior, entradas, saídas e saldo, e lançamentos manuais.
 * Recebimentos, adiantamentos e estornos de locação entram pelas telas de locação. Mesmas regras de
 * lancamento_registrar() e lancamento_excluir() do sistema antigo.
 */
class CaixaController extends Controller
{
    public function index(Request $request): View
    {
        $de = (string) $request->query('de', now()->format('Y-m-01'));
        $ate = (string) $request->query('ate', now()->toDateString());
        $de = Formulario::dataValida($de) ? $de : now()->format('Y-m-01');
        $ate = Formulario::dataValida($ate) ? $ate : now()->toDateString();
        if ($ate < $de) {
            [$de, $ate] = [$ate, $de];
        }
        $tipo = in_array($request->query('tipo'), ['E', 'S'], true) ? $request->query('tipo') : '';
        $condTipo = $tipo !== '' ? ' AND c.tipo = ?' : '';
        $pt = $tipo !== '' ? [$de, $ate, $tipo] : [$de, $ate];

        $anterior = (float) DB::selectOne("SELECT COALESCE(SUM(CASE WHEN tipo = 'E' THEN valor ELSE -valor END), 0) AS v FROM caixa WHERE data < ?", [$de])->v;
        $tot = DB::selectOne("SELECT COALESCE(SUM(valor) FILTER (WHERE tipo = 'E'), 0) AS entradas, COALESCE(SUM(valor) FILTER (WHERE tipo = 'S'), 0) AS saidas
                                FROM caixa WHERE data BETWEEN ? AND ?", [$de, $ate]);
        $linhas = DB::select("SELECT c.id, c.data, c.tipo, c.categoria, c.descricao, c.valor, c.forma, c.locacao_id, l.numero AS locacao, u.nome AS usuario,
                                     EXISTS (SELECT 1 FROM contas_pagar cp WHERE cp.caixa_id = c.id) AS conta
                                FROM caixa c LEFT JOIN locacoes l ON l.id = c.locacao_id LEFT JOIN usuarios u ON u.id = c.usuario_id
                               WHERE c.data BETWEEN ? AND ? $condTipo ORDER BY c.data, c.criado_em", $pt);
        $agrupar = fn (string $campo) => DB::select("SELECT $campo AS nome, COALESCE(SUM(valor) FILTER (WHERE tipo = 'E'), 0) AS entradas,
                                                            COALESCE(SUM(valor) FILTER (WHERE tipo = 'S'), 0) AS saidas
                                                       FROM caixa c WHERE data BETWEEN ? AND ? $condTipo GROUP BY $campo ORDER BY $campo", $pt);
        return view('caixa.index', [
            'de' => $de, 'ate' => $ate, 'tipo' => $tipo,
            'anterior' => $anterior, 'entradas' => (float) $tot->entradas, 'saidas' => (float) $tot->saidas,
            'linhas' => $linhas, 'porForma' => $agrupar('forma'), 'porCategoria' => $agrupar('categoria'),
            'categorias' => array_column(DB::select("SELECT DISTINCT categoria FROM caixa WHERE categoria <> '' ORDER BY categoria"), 'categoria'),
            'admin' => session('usuario.perfil') === 'admin',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = (string) $request->input('data', '');
        $tipo = (string) $request->input('tipo', '');
        $categoria = trim((string) $request->input('categoria', ''));
        $descricao = trim((string) $request->input('descricao', ''));
        $valor = valor_br($request->input('valor', ''));
        $forma = (string) $request->input('forma', '');
        $e = [];
        if (!Formulario::dataValida($data) || $data > now()->toDateString()) {
            $e['data'] = 'Informe a data (até hoje).';
        }
        if (!in_array($tipo, ['E', 'S'], true)) {
            $e['tipo'] = 'Escolha entrada ou saída.';
        }
        if ($categoria === '' || mb_strlen($categoria) > 60) {
            $e['categoria'] = 'Informe a categoria (até 60 caracteres).';
        }
        if ($descricao === '' || mb_strlen($descricao) > 200) {
            $e['descricao'] = 'Informe a descrição (até 200 caracteres).';
        }
        if ($valor === null || $valor <= 0) {
            $e['valor'] = 'O valor deve ser maior que zero.';
        }
        if (!in_array($forma, Estoque::FORMAS_PGTO, true)) {
            $e['forma'] = 'Escolha a forma de pagamento.';
        }
        if ($e) {
            return back()->withErrors($e)->withInput();
        }
        DB::insert('INSERT INTO caixa (data, tipo, categoria, descricao, valor, forma, usuario_id) VALUES (?, ?, ?, ?, ?, ?, ?)',
                   [$data, $tipo, $categoria, $descricao, $valor, $forma, session('usuario.id')]);
        return back()->with('flash', [['tipo' => 'ok', 'msg' => 'Lançamento registrado.']]);
    }

    /** Só o administrador exclui, e nunca lançamento de locação (corrige-se pela locação) nem pagamento de conta. */
    public function destroy(string $id): RedirectResponse
    {
        abort_unless(Str::isUuid($id), 404);
        abort_unless(session('usuario.perfil') === 'admin', 403, 'Só o administrador pode excluir lançamentos.');
        $l = DB::selectOne('SELECT locacao_id FROM caixa WHERE id = ?', [$id]);
        abort_if($l === null, 404, 'Lançamento não encontrado.');
        if ($l->locacao_id !== null) {
            return back()->with('flash', [['tipo' => 'erro', 'msg' => 'Lançamentos de locação não podem ser excluídos. Use o estorno (cancelamento) na própria locação.']]);
        }
        if (DB::selectOne('SELECT 1 AS ok FROM contas_pagar WHERE caixa_id = ?', [$id])) {
            return back()->with('flash', [['tipo' => 'erro', 'msg' => 'Este lançamento é o pagamento de uma conta: use "Desfazer pagamento" em Contas a pagar.']]);
        }
        DB::delete('DELETE FROM caixa WHERE id = ?', [$id]);
        return back()->with('flash', [['tipo' => 'ok', 'msg' => 'Lançamento excluído.']]);
    }
}
