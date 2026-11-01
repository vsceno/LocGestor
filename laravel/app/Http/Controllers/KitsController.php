<?php

namespace App\Http\Controllers;

use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Kits: pacote de produtos que costumam sair juntos. Na locação e na reserva o kit vira os itens dele;
 * estoque e preço continuam sendo os de cada produto. Mesmas regras de kit_salvar() do sistema antigo.
 */
class KitsController extends Controller
{
    public function index(): View
    {
        $kits = DB::select("SELECT k.id, k.codigo, k.nome, k.ativo,
                                   (SELECT string_agg(ki.quantidade || ' × ' || p.descricao, ', ' ORDER BY p.descricao)
                                      FROM kit_itens ki JOIN produtos p ON p.id = ki.produto_id WHERE ki.kit_id = k.id) AS composicao,
                                   (SELECT COALESCE(SUM(ki.quantidade * p.valor_diaria), 0)
                                      FROM kit_itens ki JOIN produtos p ON p.id = ki.produto_id WHERE ki.kit_id = k.id) AS diaria
                              FROM kits k ORDER BY k.nome");
        return view('produtos.kits', compact('kits'));
    }

    public function create(): View
    {
        return view('produtos.kit', ['k' => null, 'itens' => [], 'produtos' => $this->produtos()]);
    }

    public function edit(string $id): View
    {
        $k = $this->kit($id);
        $itens = DB::select('SELECT ki.produto_id, ki.quantidade FROM kit_itens ki JOIN produtos p ON p.id = ki.produto_id
                              WHERE ki.kit_id = ? ORDER BY p.descricao', [$id]);
        return view('produtos.kit', ['k' => $k, 'itens' => $itens, 'produtos' => $this->produtos()]);
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->gravar($request, null);
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $this->kit($id);
        return $this->gravar($request, $id);
    }

    public function destroy(string $id): RedirectResponse
    {
        $this->kit($id);
        DB::delete('DELETE FROM kits WHERE id = ?', [$id]);   // os itens vão junto; produtos e locações não mudam
        return redirect()->route('kits.index')->with('flash', [['tipo' => 'ok', 'msg' => 'Kit excluído.']]);
    }

    private function gravar(Request $request, ?string $id): RedirectResponse
    {
        [$k, $itens, $e] = $this->validar($request);
        if ($e) {
            return back()->withErrors($e)->withInput();
        }
        try {
            DB::transaction(function () use ($k, $itens, &$id) {
                if ($id === null) {
                    $id = DB::selectOne('INSERT INTO kits (codigo, nome, obs, ativo) VALUES (?, ?, ?, CAST(? AS boolean)) RETURNING id', array_values($k))->id;
                } else {
                    DB::update('UPDATE kits SET codigo = ?, nome = ?, obs = ?, ativo = CAST(? AS boolean) WHERE id = ?', [...array_values($k), $id]);
                    DB::delete('DELETE FROM kit_itens WHERE kit_id = ?', [$id]);
                }
                foreach ($itens as $produto => $qtd) {
                    DB::insert('INSERT INTO kit_itens (kit_id, produto_id, quantidade) VALUES (?, ?, ?)', [$id, $produto, $qtd]);
                }
            });
        } catch (QueryException $ex) {
            if ($ex->getCode() === '23505' && str_contains($ex->getMessage(), 'kits_codigo_uk')) {
                return back()->withErrors(['codigo' => 'Já existe um kit com este código.'])->withInput();
            }
            throw $ex;
        }
        return redirect()->route('kits.index')->with('flash', [['tipo' => 'ok', 'msg' => $request->route('id') ? 'Kit atualizado.' : 'Kit cadastrado.']]);
    }

    /**
     * @return array{0: array<string, string>, 1: array<string, int>, 2: array<string, string>} [kit, produto_id => qtd, erros]
     */
    private function validar(Request $request): array
    {
        $k = [
            'codigo' => strtoupper(trim((string) $request->input('codigo', ''))),
            'nome'   => trim((string) $request->input('nome', '')),
            'obs'    => trim((string) $request->input('obs', '')),
            'ativo'  => $request->boolean('ativo') ? 'true' : 'false',
        ];
        $e = [];
        if (!preg_match('/^[A-Z0-9][A-Z0-9._\/-]{0,29}$/', $k['codigo'])) {
            $e['codigo'] = 'Código inválido (até 30 caracteres: letras, números, ponto, hífen, barra).';
        }
        if (mb_strlen($k['nome']) < 2 || mb_strlen($k['nome']) > 150) {
            $e['nome'] = 'Informe o nome do kit (2 a 150 caracteres).';
        }
        if (mb_strlen($k['obs']) > 2000) {
            $e['obs'] = 'Observações muito longas (máximo de 2.000 caracteres).';
        }

        // Composição: linhas sem produto são ignoradas; o mesmo produto em duas linhas soma (como no sistema antigo)
        $itens = [];
        $qtds = (array) $request->input('quantidade', []);
        foreach ((array) $request->input('produto_id', []) as $i => $pid) {
            $pid = (string) $pid;
            if ($pid === '') {
                continue;
            }
            $qtd = filter_var($qtds[$i] ?? '', FILTER_VALIDATE_INT);
            if (!Str::isUuid($pid)) {
                $e['itens'] ??= 'Produto inválido na linha ' . ((int) $i + 1) . '.';
            } elseif ($qtd === false || $qtd < 1 || $qtd > 100000) {
                $e['itens'] ??= 'Quantidade inválida na linha ' . ((int) $i + 1) . '.';
            } else {
                $itens[$pid] = ($itens[$pid] ?? 0) + $qtd;
            }
        }
        if (!$itens && !isset($e['itens'])) {
            $e['itens'] = 'Inclua pelo menos um item na composição do kit.';
        }
        // Todos os produtos precisam ser desta locadora (o RLS esconde os das outras, então a contagem não fecha)
        if ($itens && !isset($e['itens'])) {
            $achados = (int) DB::selectOne('SELECT count(*) AS n FROM produtos WHERE id = ANY (CAST(? AS uuid[]))',
                                           ['{' . implode(',', array_keys($itens)) . '}'])->n;
            if ($achados !== count($itens)) {
                $e['itens'] = 'Produto não encontrado na composição do kit.';
            }
        }
        return [$k, $itens, $e];
    }

    /** @return array<string, object> id => produto, para o seletor da composição */
    private function produtos(): array
    {
        $lista = [];
        foreach (DB::select('SELECT id, codigo, descricao, valor_diaria, ativo FROM produtos ORDER BY descricao') as $p) {
            $lista[$p->id] = $p;
        }
        return $lista;
    }

    /** Kit da locadora logada ou 404 (o RLS esconde os das outras). */
    private function kit(string $id): object
    {
        abort_unless(Str::isUuid($id), 404);
        $k = DB::selectOne('SELECT * FROM kits WHERE id = ?', [$id]);
        abort_if($k === null, 404, 'Kit não encontrado.');
        return $k;
    }
}
