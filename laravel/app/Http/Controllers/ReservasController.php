<?php

namespace App\Http\Controllers;

use App\Support\Estoque;
use App\Support\Formulario;
use App\Support\Recusa;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Reservas: garantem equipamentos para uma data futura, bloqueando o estoque no período, e viram locação pelo
 * "Retirar". Mesmas regras de reserva_salvar() e reserva_cancelar() do sistema antigo.
 */
class ReservasController extends Controller
{
    public const ABAS = ['ativa' => 'Ativas', 'convertida' => 'Convertidas', 'cancelada' => 'Canceladas'];

    /** Selo da reserva: vencida, retirar hoje, ativa, convertida ou cancelada. */
    public static function situacao(object $r): array
    {
        $hoje = now()->toDateString();
        if ($r->status === 'ativa') {
            return $r->data_fim < $hoje ? ['vencida', 'selo-vermelho'] : ($r->data_inicio <= $hoje ? ['retirar hoje', 'selo-amarelo'] : ['ativa', 'selo-azul']);
        }
        return $r->status === 'convertida' ? ['convertida', 'selo-verde'] : ['cancelada', 'selo-cinza'];
    }

    public function index(Request $request): View
    {
        $aba = (string) $request->query('aba', 'ativa');
        $aba = array_key_exists($aba, self::ABAS) ? $aba : 'ativa';
        $contagem = DB::selectOne("SELECT count(*) FILTER (WHERE status = 'ativa') AS ativa, count(*) FILTER (WHERE status = 'convertida') AS convertida,
                                          count(*) FILTER (WHERE status = 'cancelada') AS cancelada FROM reservas");
        $porPagina = config('locgestor.por_pagina');
        $pagina = max(1, (int) $request->query('page', 1));
        $ordem = $aba === 'ativa' ? 'r.data_inicio, r.numero' : 'r.data_inicio DESC, r.numero DESC';
        $linhas = DB::select("SELECT r.id, r.numero, r.data_inicio, r.data_fim, r.status, c.nome AS cliente,
                                     (SELECT string_agg(ri.quantidade || ' ' || p.descricao, ', ' ORDER BY p.descricao)
                                        FROM reserva_itens ri JOIN produtos p ON p.id = ri.produto_id WHERE ri.reserva_id = r.id) AS itens,
                                     (SELECT l.numero FROM locacoes l WHERE l.reserva_id = r.id LIMIT 1) AS locacao
                                FROM reservas r JOIN clientes c ON c.id = r.cliente_id
                               WHERE r.status = ? ORDER BY $ordem LIMIT ? OFFSET ?", [$aba, $porPagina, ($pagina - 1) * $porPagina]);
        $reservas = new LengthAwarePaginator($linhas, (int) $contagem->$aba, $porPagina, $pagina, ['path' => route('reservas.index'), 'query' => ['aba' => $aba]]);
        return view('reservas.index', ['reservas' => $reservas, 'aba' => $aba, 'abas' => self::ABAS, 'contagem' => $contagem]);
    }

    public function show(int $numero): View
    {
        $r = $this->reserva($numero);
        $itens = DB::select('SELECT ri.quantidade, ri.periodo, p.codigo, p.descricao, p.unidade, p.valor_diaria, p.valor_semanal, p.valor_quinzenal, p.valor_mensal
                               FROM reserva_itens ri JOIN produtos p ON p.id = ri.produto_id WHERE ri.reserva_id = ? ORDER BY p.descricao', [$r->id]);
        $dias = \App\Support\Locacoes::diasCobranca($r->data_inicio, $r->data_fim);
        // Estimativa pelos preços atuais do cadastro, com as funções de preço do banco
        $estimado = (float) DB::selectOne("SELECT COALESCE(SUM(ri.quantidade * CASE WHEN ri.periodo = 'automatico'
                                                THEN preco_periodo(CAST(? AS integer), p.valor_diaria, p.valor_semanal, p.valor_mensal)
                                                ELSE preco_plano(CAST(? AS integer), ri.periodo, p.valor_diaria, p.valor_semanal, p.valor_quinzenal, p.valor_mensal) END), 0) AS v
                                             FROM reserva_itens ri JOIN produtos p ON p.id = ri.produto_id WHERE ri.reserva_id = ?", [$dias, $dias, $r->id])->v;
        $locacao = DB::selectOne('SELECT numero FROM locacoes WHERE reserva_id = ? LIMIT 1', [$r->id])?->numero;
        return view('reservas.show', compact('r', 'itens', 'dias', 'estimado', 'locacao') + ['whatsapp' => $this->whatsapp($r)]);
    }

    public function create(): View
    {
        return $this->form(null);
    }

    public function edit(int $numero): View|RedirectResponse
    {
        $r = $this->reserva($numero);
        if ($r->status !== 'ativa') {
            return redirect()->route('reservas.show', $numero)->with('flash', [['tipo' => 'erro', 'msg' => 'Só é possível alterar reserva ativa.']]);
        }
        return $this->form($r);
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->gravar($request, null);
    }

    public function update(Request $request, int $numero): RedirectResponse
    {
        return $this->gravar($request, $this->reserva($numero));
    }

    public function cancelar(int $numero): RedirectResponse
    {
        $r = $this->reserva($numero);
        $ok = DB::update("UPDATE reservas SET status = 'cancelada' WHERE id = ? AND status = 'ativa'", [$r->id]);
        if (!$ok) {
            return back()->with('flash', [['tipo' => 'erro', 'msg' => 'Só é possível cancelar reserva ativa.']]);
        }
        return redirect()->route('reservas.show', $numero)->with('flash', [['tipo' => 'ok', 'msg' => "Reserva nº $numero cancelada."]]);
    }

    private function form(?object $r): View
    {
        $linhas = $r ? array_map(fn ($i) => ['produto_id' => $i->produto_id, 'quantidade' => (string) $i->quantidade, 'periodo' => $i->periodo === 'automatico' ? 'diaria' : $i->periodo],
                                 DB::select('SELECT produto_id, quantidade, periodo FROM reserva_itens WHERE reserva_id = ?', [$r->id])) : [];
        $kits = [];
        // Kits ativos, só com produtos ativos (os inativos não podem ser reservados). Igual a kits_para_form().
        foreach (DB::select('SELECT k.id, k.codigo, k.nome, ki.produto_id, ki.quantidade FROM kits k JOIN kit_itens ki ON ki.kit_id = k.id
                               JOIN produtos p ON p.id = ki.produto_id WHERE k.ativo AND p.ativo ORDER BY k.nome, p.descricao') as $k) {
            $kits[$k->id] ??= ['codigo' => $k->codigo, 'nome' => $k->nome, 'itens' => []];
            $kits[$k->id]['itens'][] = ['p' => $k->produto_id, 'q' => (int) $k->quantidade];
        }
        return view('reservas.form', [
            'r'          => $r,
            'clienteTxt' => $r ? Formulario::rotuloCliente((object) ['nome' => $r->cliente_nome, 'cpf_cnpj' => $r->cliente_doc]) : '',
            'inicio'     => $r->data_inicio ?? now()->addDay()->toDateString(),
            'fim'        => $r->data_fim ?? now()->addDays(2)->toDateString(),
            'linhas'     => $linhas ?: [['produto_id' => '', 'quantidade' => '1', 'periodo' => 'diaria']],
            'kits'       => $kits,
            'clientes'   => DB::select('SELECT nome, cpf_cnpj FROM clientes WHERE ativo AND NOT bloqueado ORDER BY lower(nome)'),
            'produtos'   => DB::select('SELECT id, codigo, descricao FROM produtos WHERE ativo ORDER BY descricao'),
        ]);
    }

    private function gravar(Request $request, ?object $r): RedirectResponse
    {
        try {
            $numero = $this->salvar($request, $r?->id);
        } catch (Recusa $e) {
            return back()->withErrors(['reserva' => $e->getMessage()])->withInput();
        }
        return redirect()->route('reservas.show', $numero)->with('flash', [['tipo' => 'ok', 'msg' => $r ? "Reserva nº $numero alterada." : "Reserva nº $numero registrada."]]);
    }

    /** Cria ou altera uma reserva e devolve o número (usado também na conversão de orçamento). */
    public function salvar(Request $request, ?string $id): int
    {
        $cliente = Formulario::cliente((string) $request->input('cliente', ''));
        if ($cliente->bloqueado) {
            throw new Recusa("O cliente {$cliente->nome} está bloqueado e não pode fazer reservas.");
        }
        $ini = (string) $request->input('data_inicio', '');
        $fim = (string) $request->input('data_fim', '');
        if (!Formulario::dataValida($ini) || !Formulario::dataValida($fim)) {
            throw new Recusa('Informe datas válidas de retirada e de devolução.');
        }
        if ($ini < now()->toDateString()) {
            throw new Recusa('A reserva deve começar hoje ou depois. Para equipamento que já saiu, registre uma locação.');
        }
        if ($fim < $ini) {
            throw new Recusa('A devolução não pode ser antes da retirada.');
        }
        $obs = trim((string) $request->input('obs', ''));
        if (mb_strlen($obs) > 2000) {
            throw new Recusa('Observações muito longas (máximo de 2.000 caracteres).');
        }
        $brutos = Formulario::itens($request, 'na reserva');
        $itens = array_map(fn ($it) => $it['qtd'], $brutos);

        return DB::transaction(function () use ($id, $cliente, $ini, $fim, $obs, $itens, $brutos) {
            if ($id !== null) {
                $st = DB::selectOne('SELECT status FROM reservas WHERE id = ? FOR UPDATE', [$id])->status;
                if ($st !== 'ativa') {
                    throw new Recusa('Só é possível alterar reserva ativa.');
                }
            }
            Estoque::travar(array_keys($itens));
            $ativos = (int) DB::selectOne('SELECT count(*) AS n FROM produtos WHERE ativo AND id = ANY (CAST(? AS uuid[]))', ['{' . implode(',', array_keys($itens)) . '}'])->n;
            if ($ativos !== count($itens)) {
                throw new Recusa('Produto não encontrado ou inativo.');
            }
            $falta = Estoque::falta($itens, $ini, $fim, $id);   // na edição, a própria reserva não conta contra o estoque
            if ($falta !== null) {
                throw new Recusa($falta);
            }
            if ($id === null) {
                $numero = (int) DB::selectOne("SELECT proximo_numero('reserva') AS n")->n;
                $id = DB::selectOne('INSERT INTO reservas (numero, cliente_id, data_inicio, data_fim, obs, usuario_id) VALUES (?, ?, ?, ?, ?, ?) RETURNING id',
                                    [$numero, $cliente->id, $ini, $fim, $obs, session('usuario.id')])->id;
            } else {
                $numero = (int) DB::selectOne('UPDATE reservas SET cliente_id = ?, data_inicio = ?, data_fim = ?, obs = ? WHERE id = ? RETURNING numero',
                                              [$cliente->id, $ini, $fim, $obs, $id])->numero;
                DB::delete('DELETE FROM reserva_itens WHERE reserva_id = ?', [$id]);
            }
            foreach ($itens as $pid => $qtd) {
                DB::insert('INSERT INTO reserva_itens (reserva_id, produto_id, quantidade, periodo) VALUES (?, ?, ?, ?)', [$id, $pid, $qtd, $brutos[$pid]['periodo'] ?? 'automatico']);
            }
            return $numero;
        });
    }

    /** Confirmação no WhatsApp: mesmo texto de whatsapp_reserva(). */
    private function whatsapp(object $r): ?string
    {
        $num = whatsapp_numero((object) ['celular' => $r->cliente_celular, 'telefone' => $r->cliente_telefone]);
        if ($num === null) {
            return null;
        }
        $empresa = DB::selectOne('SELECT nome_fantasia, razao_social FROM empresa');
        $nome = trim($r->cliente_nome);
        $chamar = $r->cliente_tipo === 'PJ' ? $nome : (explode(' ', $nome)[0] ?? $nome);
        $itens = (string) DB::selectOne("SELECT string_agg(ri.quantidade || ' ' || p.descricao, ', ' ORDER BY p.descricao) AS t
                                           FROM reserva_itens ri JOIN produtos p ON p.id = ri.produto_id WHERE ri.reserva_id = ?", [$r->id])->t;
        $msg = "Olá, $chamar! Aqui é da *" . ($empresa->nome_fantasia ?: $empresa->razao_social) . "*.\n\nSua *reserva nº {$r->numero}* está confirmada:\n• Itens: $itens\n"
             . '• Retirada: ' . data_br($r->data_inicio) . "\n• Devolução: " . data_br($r->data_fim) . "\n\nAté lá!";
        return 'https://wa.me/' . $num . '?text=' . rawurlencode($msg);
    }

    private function reserva(int $numero): object
    {
        abort_if($numero < 1 || $numero > 999999999, 404);
        $r = DB::selectOne('SELECT r.*, c.nome AS cliente_nome, c.cpf_cnpj AS cliente_doc, c.tipo AS cliente_tipo, c.celular AS cliente_celular, c.telefone AS cliente_telefone
                              FROM reservas r JOIN clientes c ON c.id = r.cliente_id WHERE r.numero = ?', [$numero]);
        abort_if($r === null, 404, 'Reserva não encontrada.');
        return $r;
    }
}
