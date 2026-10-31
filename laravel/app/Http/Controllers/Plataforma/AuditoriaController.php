<?php

namespace App\Http\Controllers\Plataforma;

use App\Http\Controllers\Controller;
use App\Support\AuditoriaPlataforma;
use App\Support\Formulario;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Auditoria do painel da plataforma: quem criou, alterou ou excluiu, e quando. Só leitura. */
class AuditoriaController extends Controller
{
    private const POR_PAGINA = 50;

    public function index(Request $request): View
    {
        $de = Formulario::dataValida((string) $request->query('de', '')) ? (string) $request->query('de') : now()->subDays(30)->toDateString();
        $ate = Formulario::dataValida((string) $request->query('ate', '')) ? (string) $request->query('ate') : now()->toDateString();
        $tabela = array_key_exists((string) $request->query('tabela', ''), AuditoriaPlataforma::TABELAS) ? (string) $request->query('tabela') : '';
        $acao = array_key_exists((string) $request->query('acao', ''), AuditoriaPlataforma::ACOES) ? (string) $request->query('acao') : '';
        $usuario = mb_substr((string) $request->query('usuario', ''), 0, 80);

        $where = ['quando >= CAST(? AS date)', "quando < CAST(? AS date) + INTERVAL '1 day'"];
        $bind = [$de, $ate];
        foreach (['tabela' => $tabela, 'acao' => $acao, 'usuario' => $usuario] as $coluna => $valor) {
            if ($valor !== '') {
                $where[] = "$coluna = ?";
                $bind[] = $valor;
            }
        }
        $sqlWhere = implode(' AND ', $where);

        $db = DB::connection('plataforma');
        $pagina = max(1, (int) $request->query('page', 1));
        $total = (int) $db->selectOne("SELECT count(*) AS n FROM auditoria_plataforma WHERE $sqlWhere", $bind)->n;
        $linhas = $db->select("SELECT * FROM auditoria_plataforma WHERE $sqlWhere ORDER BY quando DESC, id DESC LIMIT ? OFFSET ?",
                              [...$bind, self::POR_PAGINA, ($pagina - 1) * self::POR_PAGINA]);

        $query = array_filter(['de' => $de, 'ate' => $ate, 'tabela' => $tabela, 'acao' => $acao, 'usuario' => $usuario], fn ($v) => $v !== '');
        return view('plataforma.auditoria', [
            'linhas' => new LengthAwarePaginator(array_map(fn ($a) => AuditoriaPlataforma::linha($a), $linhas), $total, self::POR_PAGINA, $pagina,
                                                 ['path' => route('plataforma.auditoria'), 'query' => $query]),
            'total' => $total,
            'de' => $de, 'ate' => $ate, 'tabela' => $tabela, 'acao' => $acao, 'filtroUsuario' => $usuario,
            'tabelas' => AuditoriaPlataforma::TABELAS,
            'acoes' => AuditoriaPlataforma::ACOES,
            'usuarios' => array_column($db->select('SELECT DISTINCT usuario FROM auditoria_plataforma ORDER BY usuario'), 'usuario'),
        ]);
    }
}
