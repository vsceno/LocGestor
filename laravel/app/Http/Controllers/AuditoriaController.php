<?php

namespace App\Http\Controllers;

use App\Support\Auditoria;
use App\Support\Formulario;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/** Auditoria da locadora (só administrador): filtros por período, tabela, ação, usuário e registro. */
class AuditoriaController extends Controller
{
    private const POR_PAGINA = 50;

    public function index(Request $request): View
    {
        abort_unless(session('usuario.perfil') === 'admin', 403, 'Acesso restrito ao administrador.');

        $de = Formulario::dataValida((string) $request->query('de', '')) ? (string) $request->query('de') : now()->subDays(30)->toDateString();
        $ate = Formulario::dataValida((string) $request->query('ate', '')) ? (string) $request->query('ate') : now()->toDateString();
        $tabela = array_key_exists((string) $request->query('tabela', ''), Auditoria::TABELAS) ? (string) $request->query('tabela') : '';
        $acao = array_key_exists((string) $request->query('acao', ''), Auditoria::ACOES) ? (string) $request->query('acao') : '';
        $usuario = mb_substr((string) $request->query('usuario', ''), 0, 80);
        $registro = Str::isUuid((string) $request->query('registro', '')) ? (string) $request->query('registro') : '';

        $where = ['a.quando >= CAST(? AS date)', "a.quando < CAST(? AS date) + INTERVAL '1 day'"];
        $bind = [$de, $ate];
        foreach (['tabela' => $tabela, 'acao' => $acao, 'usuario' => $usuario, 'registro_id' => $registro] as $coluna => $valor) {
            if ($valor !== '') {
                $where[] = "a.$coluna = ?";
                $bind[] = $valor;
            }
        }
        $sqlWhere = implode(' AND ', $where);

        $pagina = max(1, (int) $request->query('page', 1));
        $total = (int) DB::selectOne("SELECT count(*) AS n FROM auditoria a WHERE $sqlWhere", $bind)->n;
        $linhas = DB::select("SELECT a.* FROM auditoria a WHERE $sqlWhere ORDER BY a.quando DESC, a.id DESC LIMIT ? OFFSET ?",
                             [...$bind, self::POR_PAGINA, ($pagina - 1) * self::POR_PAGINA]);

        $query = array_filter(['de' => $de, 'ate' => $ate, 'tabela' => $tabela, 'acao' => $acao, 'usuario' => $usuario, 'registro' => $registro],
                              fn ($v) => $v !== '');
        return view('configuracoes.auditoria', [
            'linhas' => new LengthAwarePaginator(array_map(fn ($a) => Auditoria::linha($a), $linhas), $total, self::POR_PAGINA, $pagina,
                                                 ['path' => route('config.auditoria'), 'query' => $query]),
            'total' => $total,
            'de' => $de, 'ate' => $ate, 'tabela' => $tabela, 'acao' => $acao, 'filtroUsuario' => $usuario, 'registro' => $registro,
            'tabelas' => Auditoria::TABELAS,
            'acoes' => Auditoria::ACOES,
            'usuarios' => array_column(DB::select('SELECT DISTINCT usuario FROM auditoria ORDER BY usuario'), 'usuario'),
        ]);
    }
}
