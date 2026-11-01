<?php

namespace App\Http\Controllers\Plataforma;

use App\Http\Controllers\Controller;
use App\Support\Recusa;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Planos de assinatura: nome, preço mensal e limite de usuários. Mudar o preço vale para as próximas mensalidades;
 * o limite de cada locadora só muda quando ela troca de plano. Mesmas regras de planos_*() do sistema antigo.
 */
class PlanosController extends Controller
{
    public function index(Request $request): View
    {
        $planos = DB::connection('plataforma')->select(
            "SELECT p.*, count(t.id) AS locadoras,
                    count(t.id) FILTER (WHERE t.status = 'ativa' AND t.vencimento IS NOT NULL) AS pagantes
               FROM planos p LEFT JOIN tenants t ON t.plano_id = p.id
              GROUP BY p.id ORDER BY p.preco_mensal, p.nome"
        );
        $editar = Str::isUuid((string) $request->query('id', ''))
            ? collect($planos)->firstWhere('id', (string) $request->query('id'))
            : null;

        return view('plataforma.planos', ['planos' => $planos, 'editar' => $editar]);
    }

    public function criar(Request $request): RedirectResponse
    {
        return $this->gravar($request, null);
    }

    public function atualizar(Request $request, string $id): RedirectResponse
    {
        return $this->gravar($request, $id);
    }

    public function excluir(string $id): RedirectResponse
    {
        $db = DB::connection('plataforma');
        try {
            $db->transaction(function () use ($db, $id) {
                if ($db->delete('DELETE FROM planos WHERE id = ?', [$id]) === 0) {
                    throw new Recusa('Plano não encontrado.');
                }
            });
        } catch (Recusa $e) {
            return back()->with('flash', [['tipo' => 'erro', 'msg' => $e->getMessage()]]);
        } catch (QueryException $e) {
            if ($e->getCode() === '23503') {
                return back()->with('flash', [['tipo' => 'erro', 'msg' => 'Este plano tem locadoras e não pode ser excluído.']]);
            }
            throw $e;
        }
        return redirect()->route('plataforma.planos.index')->with('flash', [['tipo' => 'ok', 'msg' => 'Plano excluído.']]);
    }

    private function gravar(Request $request, ?string $id): RedirectResponse
    {
        $nome = trim((string) $request->input('nome', ''));
        $preco = valor_br($request->input('preco_mensal', ''));
        $limite = filter_var($request->input('limite_usuarios', ''), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if (mb_strlen($nome) < 2 || mb_strlen($nome) > 40 || $preco === null || $preco < 0 || $limite === false) {
            return back()->withInput()->with('flash', [['tipo' => 'erro', 'msg' => 'Confira o nome (2 a 40), o preço e o limite de usuários (1 a 1000).']]);
        }
        $ativo = $request->boolean('ativo') ? 'true' : 'false';
        $db = DB::connection('plataforma');
        try {
            $msg = $db->transaction(function () use ($db, $id, $nome, $preco, $limite, $ativo) {
                if ($id === null) {
                    $db->insert('INSERT INTO planos (nome, preco_mensal, limite_usuarios, ativo) VALUES (?, ?, ?, ?)', [$nome, $preco, $limite, $ativo]);
                    return 'Plano criado.';
                }
                if ($db->update('UPDATE planos SET nome = ?, preco_mensal = ?, limite_usuarios = ?, ativo = ? WHERE id = ?',
                                [$nome, $preco, $limite, $ativo, $id]) === 0) {
                    throw new Recusa('Plano não encontrado.');
                }
                // O nome que a locadora vê acompanha o plano
                $db->update('UPDATE tenants SET plano = ? WHERE plano_id = ?', [$nome, $id]);
                return 'Plano atualizado.';
            });
        } catch (Recusa $e) {
            return back()->withInput()->with('flash', [['tipo' => 'erro', 'msg' => $e->getMessage()]]);
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                return back()->withInput()->with('flash', [['tipo' => 'erro', 'msg' => 'Já existe um plano com este nome.']]);
            }
            throw $e;
        }
        return redirect()->route('plataforma.planos.index')->with('flash', [['tipo' => 'ok', 'msg' => $msg]]);
    }
}
