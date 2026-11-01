<?php

namespace App\Http\Controllers;

use App\Support\Estoque;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Recibos numerados: pagamento avulso ou ligado a uma locação. O número é fixo: não há tela para alterar nem apagar.
 * O pagamento continua sendo lançado no caixa; o recibo é só o comprovante. Mesmas regras de recibo_salvar().
 */
class RecibosController extends Controller
{
    public function index(Request $request): View
    {
        $porPagina = config('locgestor.por_pagina');
        $pagina = max(1, (int) $request->query('page', 1));
        $total = (int) DB::selectOne('SELECT count(*) AS n FROM recibos')->n;
        $linhas = DB::select('SELECT r.id, r.numero, r.emissao, r.recebido_de, r.valor, r.referente, l.numero AS locacao_numero
                                FROM recibos r LEFT JOIN locacoes l ON l.id = r.locacao_id
                               ORDER BY r.numero DESC LIMIT ? OFFSET ?', [$porPagina, ($pagina - 1) * $porPagina]);
        $recibos = new LengthAwarePaginator($linhas, $total, $porPagina, $pagina, ['path' => route('recibos.index')]);
        return view('recibos.index', ['recibos' => $recibos]);
    }

    public function create(Request $request): View
    {
        // Vindo de uma locação: já preenche quem pagou e a referência (locação de outra locadora não aparece)
        $loc = null;
        if (Str::isUuid((string) $request->query('locacao', ''))) {
            $loc = DB::selectOne('SELECT l.id, l.numero, c.nome, c.cpf_cnpj FROM locacoes l JOIN clientes c ON c.id = l.cliente_id WHERE l.id = ?',
                                 [$request->query('locacao')]);
        }
        return view('recibos.form', [
            'loc' => $loc,
            'pre' => [
                'recebido_de' => $loc->nome ?? '',
                'documento'   => $loc->cpf_cnpj ?? '',
                'referente'   => $loc ? 'Pagamento da locação nº ' . $loc->numero : '',
                'locacao_id'  => $loc->id ?? '',
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $recebido = trim((string) $request->input('recebido_de', ''));
        $doc = so_digitos($request->input('documento', ''));
        $valor = valor_br($request->input('valor', ''));
        $referente = trim((string) $request->input('referente', ''));
        $forma = (string) $request->input('forma', 'Dinheiro');
        $locacaoId = (string) $request->input('locacao_id', '');

        $e = [];
        if (mb_strlen($recebido) < 2 || mb_strlen($recebido) > 150) {
            $e['recebido_de'] = 'Informe quem pagou (2 a 150 caracteres).';
        }
        if ($doc !== '' && !doc_valido($doc)) {
            $e['documento'] = 'CPF ou CNPJ inválido (deixe em branco se não quiser informar).';
        }
        if ($valor === null || $valor <= 0 || $valor > 9999999999) {
            $e['valor'] = 'Informe um valor maior que zero.';
        }
        if (mb_strlen($referente) < 2 || mb_strlen($referente) > 200) {
            $e['referente'] = 'Informe a referência do pagamento (2 a 200 caracteres).';
        }
        if (!in_array($forma, Estoque::FORMAS_PGTO, true)) {
            $e['forma'] = 'Forma de pagamento inválida.';
        }
        $clienteId = null;
        if ($locacaoId !== '') {
            $clienteId = Str::isUuid($locacaoId)
                ? DB::selectOne('SELECT cliente_id FROM locacoes WHERE id = ?', [$locacaoId])?->cliente_id
                : null;
            if ($clienteId === null) {
                $e['locacao_id'] = 'Locação inválida.';
            }
        }
        if ($e) {
            return back()->withErrors($e)->withInput();
        }

        $r = DB::transaction(function () use ($recebido, $doc, $valor, $referente, $forma, $locacaoId, $clienteId) {
            $numero = (int) DB::selectOne("SELECT proximo_numero('recibo') AS n")->n;
            return DB::selectOne('INSERT INTO recibos (numero, recebido_de, documento, valor, referente, forma, cliente_id, locacao_id, usuario_id)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id, numero',
                                 [$numero, $recebido, $doc, $valor, $referente, $forma, $clienteId,
                                  $locacaoId === '' ? null : $locacaoId, session('usuario.id')]);
        });

        return redirect()->route('recibos.show', $r->id)->with('flash', [['tipo' => 'ok', 'msg' => "Recibo nº {$r->numero} emitido."]]);
    }

    public function show(string $id): View
    {
        return view('recibos.show', ['r' => $this->recibo($id)]);
    }

    public function imprimir(string $id): View
    {
        return view('impressao.recibo', ['r' => $this->recibo($id)]);
    }

    /** Recibo da locadora logada ou 404 (o RLS esconde os das outras). */
    private function recibo(string $id): object
    {
        abort_unless(Str::isUuid($id), 404);
        $r = DB::selectOne('SELECT r.*, l.numero AS locacao_numero FROM recibos r LEFT JOIN locacoes l ON l.id = r.locacao_id WHERE r.id = ?', [$id]);
        abort_if($r === null, 404, 'Recibo não encontrado.');
        return $r;
    }
}
