<?php

namespace App\Http\Controllers;

use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/** Categorias de produtos: mesmas regras de categoria_salvar() do sistema antigo. */
class CategoriasController extends Controller
{
    public function index(): View
    {
        $categorias = DB::select('SELECT c.id, c.nome, (SELECT count(*) FROM produtos p WHERE p.categoria_id = c.id) AS produtos
                                    FROM categorias c ORDER BY c.nome');
        return view('produtos.categorias', compact('categorias'));
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->gravar($request, null);
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $this->categoria($id);
        return $this->gravar($request, $id);
    }

    public function destroy(string $id): RedirectResponse
    {
        $c = $this->categoria($id);
        // Com produtos, não exclui (o banco também impede pela chave estrangeira)
        if ((int) DB::selectOne('SELECT count(*) AS n FROM produtos WHERE categoria_id = ?', [$id])->n > 0) {
            return back()->with('flash', [['tipo' => 'erro', 'msg' => "A categoria {$c->nome} tem produtos e não pode ser excluída."]]);
        }
        DB::delete('DELETE FROM categorias WHERE id = ?', [$id]);
        return redirect()->route('categorias.index')->with('flash', [['tipo' => 'ok', 'msg' => 'Categoria excluída.']]);
    }

    private function gravar(Request $request, ?string $id): RedirectResponse
    {
        $nome = trim((string) $request->input('nome', ''));
        // O erro vai para o campo da linha certa (cada linha da tela é um formulário)
        $campo = $id === null ? 'nome' : "nome_$id";
        if (mb_strlen($nome) < 2 || mb_strlen($nome) > 60) {
            return back()->withErrors([$campo => 'O nome da categoria deve ter de 2 a 60 caracteres.'])->withInput(['nome' => $nome, '_linha' => $id]);
        }
        try {
            // Transação própria: se o banco recusar (nome repetido), só este trecho é desfeito
            DB::transaction(fn () => $id === null
                ? DB::insert('INSERT INTO categorias (nome) VALUES (?)', [$nome])
                : DB::update('UPDATE categorias SET nome = ? WHERE id = ?', [$nome, $id]));
        } catch (QueryException $e) {
            if ($e->getCode() === '23505' && str_contains($e->getMessage(), 'categorias_nome_uk')) {
                return back()->withErrors([$campo => 'Já existe uma categoria com este nome.'])->withInput(['nome' => $nome, '_linha' => $id]);
            }
            throw $e;
        }
        return redirect()->route('categorias.index')->with('flash', [['tipo' => 'ok', 'msg' => $id === null ? 'Categoria adicionada.' : 'Categoria renomeada.']]);
    }

    /** Categoria da locadora logada ou 404 (o RLS esconde as das outras). */
    private function categoria(string $id): object
    {
        abort_unless(Str::isUuid($id), 404);
        $c = DB::selectOne('SELECT * FROM categorias WHERE id = ?', [$id]);
        abort_if($c === null, 404, 'Categoria não encontrada.');
        return $c;
    }
}
