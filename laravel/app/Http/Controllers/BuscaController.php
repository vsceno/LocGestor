<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Busca geral (campo no topo): clientes, locações, reservas, produtos e patrimônio.
 * LGPD: o termo pode ser um CPF/CNPJ ou telefone, então vai por POST e fica na sessão;
 * o endereço da página de resultados nunca carrega o que foi buscado (como no sistema antigo).
 */
class BuscaController extends Controller
{
    private const LIMITE = 10;

    public function buscar(Request $request): RedirectResponse
    {
        $request->session()->put('busca', mb_substr(trim((string) $request->input('q')), 0, 100));
        return redirect()->route('busca.index');
    }

    /** Paleta de comandos (Ctrl+K): POST com o termo, resposta em JSON. Não guarda nada na sessão. */
    public function rapida(Request $request): JsonResponse
    {
        $q = mb_substr(trim((string) $request->input('q')), 0, 100);
        $grupos = mb_strlen($q) >= 2 ? $this->resultados($q, 5) : [];
        return response()->json(['grupos' => array_map(fn ($titulo, $itens) => compact('titulo', 'itens'), array_keys($grupos), array_values($grupos))]);
    }

    public function index(Request $request): View|RedirectResponse
    {
        $q = (string) $request->session()->get('busca', '');
        $grupos = mb_strlen($q) >= 2 ? $this->resultados($q) : [];
        $todos = array_merge(...array_values($grupos ?: [[]]));
        if (count($todos) === 1) {
            return redirect($todos[0]['url']);   // um resultado só: abre direto
        }
        return view('busca', compact('q', 'grupos'));
    }

    /** [grupo => [['rotulo', 'detalhe', 'url', 'ico'], ...]] — tudo filtrado pelo RLS da locadora logada. */
    private function resultados(string $q, int $limite = self::LIMITE): array
    {
        $dig = so_digitos($q);
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $numero = preg_match('/^#?\d{1,9}$/', $q) ? (int) ltrim($q, '#') : -1;
        $soNumero = str_starts_with($q, '#');   // "#123": só locação e reserva com esse número
        $r = [];

        if (!$soNumero) {
            $cond = 'lower(c.nome) LIKE lower(?)';
            $p = [$like];
            if (strlen($dig) >= 3) {
                $cond .= " OR c.cpf_cnpj LIKE ? OR regexp_replace(c.celular || ' ' || c.telefone, '\\D', '', 'g') LIKE ?";
                array_push($p, "%$dig%", "%$dig%");
            }
            $r['Clientes'] = array_map(fn ($c) => [
                'rotulo' => $c->nome, 'ico' => 'clientes', 'url' => route('clientes.show', $c->id),
                'detalhe' => trim(doc_formatar($c->cpf_cnpj) . ' · ' . $c->cidade, ' ·') . ($c->ativo ? '' : ' · inativo'),
            ], DB::select("SELECT c.id, c.nome, c.cpf_cnpj, c.cidade, c.ativo FROM clientes c WHERE $cond
                           ORDER BY c.ativo DESC, lower(c.nome) LIMIT " . $limite, $p));
        }

        $r['Locações'] = array_map(fn ($l) => [
            'rotulo' => 'Locação nº ' . $l->numero, 'ico' => 'locacoes', 'url' => legado('locacoes', ['a' => 'ver', 'n' => $l->numero]),
            'detalhe' => $l->nome . ' · ' . data_br($l->data_saida) . ' · ' . $l->status,
        ], DB::select('SELECT l.numero, l.data_saida, l.status, c.nome FROM locacoes l JOIN clientes c ON c.id = l.cliente_id
                        WHERE l.numero = ? OR (NOT CAST(? AS boolean) AND lower(l.local_uso) LIKE lower(?))
                        ORDER BY (l.numero = ?) DESC, l.numero DESC LIMIT ' . $limite, [$numero, $soNumero ? 'true' : 'false', $like, $numero]));

        if ($numero >= 0) {
            $r['Reservas'] = array_map(fn ($x) => [
                'rotulo' => 'Reserva nº ' . $x->numero, 'ico' => 'reservas', 'url' => legado('reservas', ['a' => 'ver', 'n' => $x->numero]),
                'detalhe' => $x->nome . ' · ' . data_br($x->data_inicio) . ' · ' . $x->status,
            ], DB::select('SELECT r.numero, r.data_inicio, r.status, c.nome FROM reservas r JOIN clientes c ON c.id = r.cliente_id WHERE r.numero = ?', [$numero]));
        }

        // Código de barras confere exato (leitor USB); código e descrição só fora do modo "#número"
        $r['Produtos'] = array_map(fn ($x) => [
            'rotulo' => $x->codigo . ' — ' . $x->descricao, 'ico' => 'produtos', 'url' => route('produtos.edit', $x->id),
            'detalhe' => ($x->ativo ? '' : 'inativo · ') . numero($x->qtd_total) . ' em estoque',
        ], DB::select('SELECT p.id, p.codigo, p.descricao, p.qtd_total, p.ativo FROM produtos p
                        WHERE (NOT CAST(? AS boolean) AND (lower(p.codigo) LIKE lower(?) OR lower(p.descricao) LIKE lower(?)))
                           OR EXISTS (SELECT 1 FROM produto_barras b WHERE b.produto_id = p.id AND b.codigo = ?)
                        ORDER BY p.ativo DESC, p.descricao LIMIT ' . $limite, [$soNumero ? 'true' : 'false', $like, $like, $q]));

        if (!$soNumero) {
            $r['Patrimônio'] = array_map(fn ($x) => [
                'rotulo' => 'Unidade ' . $x->patrimonio, 'ico' => 'manutencao', 'url' => route('unidades.show', $x->id),
                'detalhe' => $x->descricao . ($x->serie !== '' ? ' · série ' . $x->serie : '') . ($x->ativa ? '' : ' · baixada'),
            ], DB::select('SELECT u.id, u.patrimonio, u.serie, u.ativa, p.descricao FROM unidades u JOIN produtos p ON p.id = u.produto_id
                            WHERE lower(u.patrimonio) LIKE lower(?) OR lower(u.serie) LIKE lower(?)
                            ORDER BY u.ativa DESC, u.patrimonio LIMIT ' . $limite, [$like, $like]));
        }

        return array_filter($r);
    }
}
