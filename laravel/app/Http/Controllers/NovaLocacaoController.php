<?php

namespace App\Http\Controllers;

use App\Support\Estoque;
use App\Support\Locacoes;
use App\Support\Recusa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Nova locação (saída de equipamentos). Mesmas regras de locacao_criar() do sistema antigo: cliente ativo e não
 * bloqueado, saída nunca no futuro (retirada futura é reserva), desconto/acréscimo só com administrador, estoque
 * conferido com os produtos travados, reserva convertida e adiantamento lançado no caixa, tudo numa transação.
 */
class NovaLocacaoController extends Controller
{
    public const PERIODOS = ['diaria' => 'Diária', 'semanal' => 'Semanal', 'quinzenal' => 'Quinzenal', 'mensal' => 'Mensal'];

    /** Texto do cliente na lista: "Nome · documento" (o id nunca aparece). Igual a cliente_rotulo(). */
    public static function rotuloCliente(object $c): string
    {
        return $c->cpf_cnpj !== '' ? $c->nome . ' · ' . doc_formatar($c->cpf_cnpj) : $c->nome;
    }

    public function create(Request $request): View
    {
        $hoje = now()->toDateString();
        $clienteTxt = '';
        $saida = $hoje;
        $prev = now()->addDay()->toDateString();
        $linhas = [];
        $reserva = null;
        $copia = ['local_uso' => '', 'frete_entrega' => '', 'obs' => ''];

        // Vindo da ficha do cliente (?cliente=), de uma reserva (?reserva=) ou "repetir locação" (?copiar=)
        $cid = (string) $request->query('cliente', '');
        if (Str::isUuid($cid) && ($c = DB::selectOne('SELECT nome, cpf_cnpj FROM clientes WHERE id = ? AND ativo', [$cid]))) {
            $clienteTxt = self::rotuloCliente($c);
        }
        $rid = (string) $request->query('reserva', '');
        if (Str::isUuid($rid)) {
            $reserva = DB::selectOne("SELECT r.id, r.numero, r.data_fim, r.obs, c.nome, c.cpf_cnpj FROM reservas r
                                        JOIN clientes c ON c.id = r.cliente_id WHERE r.id = ? AND r.status = 'ativa'", [$rid]);
            abort_if($reserva === null, 404, 'Reserva não encontrada ou não está mais ativa.');
            $clienteTxt = self::rotuloCliente($reserva);
            $prev = max($reserva->data_fim, $hoje);
            $copia['obs'] = $reserva->obs;
            foreach (DB::select('SELECT ri.produto_id, ri.quantidade, ri.periodo, p.valor_diaria, p.valor_semanal, p.valor_quinzenal, p.valor_mensal
                                   FROM reserva_itens ri JOIN produtos p ON p.id = ri.produto_id WHERE ri.reserva_id = ?', [$rid]) as $ri) {
                $linhas[] = $this->linhaDe($ri, $prev);
            }
        }
        $copiar = (string) $request->query('copiar', '');
        if (Str::isUuid($copiar)) {
            // Repetir: cliente, itens, local e fretes da anterior; datas de hoje com o mesmo prazo. Acréscimo e desconto não vêm.
            $orig = DB::selectOne('SELECT l.*, c.nome, c.cpf_cnpj FROM locacoes l JOIN clientes c ON c.id = l.cliente_id WHERE l.id = ?', [$copiar]);
            abort_if($orig === null, 404, 'Locação para repetir não encontrada.');
            $clienteTxt = self::rotuloCliente($orig);
            $prazo = max(0, (int) (new \DateTime($orig->data_saida))->diff(new \DateTime($orig->data_prev_devolucao))->format('%r%a'));
            $prev = now()->addDays($prazo)->toDateString();
            $copia = ['local_uso' => $orig->local_uso, 'frete_entrega' => numero($orig->frete_entrega + $orig->frete_recolhimento, 2), 'obs' => ''];
            $linhas = [];
            foreach (DB::select('SELECT produto_id, quantidade, periodo, valor_diaria, valor_semanal, valor_quinzenal, valor_mensal
                                   FROM locacao_itens WHERE locacao_id = ? ORDER BY id', [$copiar]) as $li) {
                $linhas[] = $this->linhaDe($li, $prev);
            }
        }
        if (!$linhas) {
            $linhas[] = ['produto_id' => '', 'quantidade' => '1', 'diaria' => '', 'prev' => $prev, 'periodo' => 'diaria'];
        }

        return view('locacoes.nova', [
            'clienteTxt' => $clienteTxt,
            'saida'      => $saida,
            'prev'       => $prev,
            'linhas'     => $linhas,
            'reserva'    => $reserva,
            'copia'      => $copia,
            'clientes'   => DB::select('SELECT nome, cpf_cnpj FROM clientes WHERE ativo AND NOT bloqueado ORDER BY lower(nome)'),
            'produtos'   => DB::select('SELECT id, codigo, descricao, unidade, valor_diaria, valor_semanal, valor_quinzenal, valor_mensal
                                          FROM produtos WHERE ativo ORDER BY descricao'),
            'admin'      => session('usuario.perfil') === 'admin',
        ]);
    }

    /** Disponível de um produto no período, para o aviso na linha enquanto se monta a locação (o servidor confere de novo ao salvar). */
    public function disponivel(Request $request): JsonResponse
    {
        $produto = (string) $request->input('produto', '');
        $inicio = (string) $request->input('inicio', '');
        $fim = (string) $request->input('fim', '');
        $reserva = (string) $request->input('reserva', '');
        if (!Str::isUuid($produto) || !$this->dataValida($inicio) || !$this->dataValida($fim) || $fim < $inicio) {
            return response()->json(['ok' => false], 422);
        }
        return response()->json(['ok' => true, 'disponivel' => Estoque::disponivel($produto, $inicio, $fim, Str::isUuid($reserva) ? $reserva : null)]);
    }

    public function store(Request $request): RedirectResponse
    {
        try {
            $r = $this->criar($request);
        } catch (Recusa $e) {
            return back()->withErrors(['locacao' => $e->getMessage()])->withInput($request->except('senha_admin'));
        }
        return redirect()->route('locacoes.show', $r['numero'])->with('flash', [['tipo' => 'ok', 'msg' => "Locação nº {$r['numero']} registrada."]]);
    }

    /** @return array{id: string, numero: int} */
    private function criar(Request $request): array
    {
        $hoje = now()->toDateString();
        $cliente = $this->resolverCliente((string) $request->input('cliente', ''));
        if ($cliente->bloqueado) {
            throw new Recusa("O cliente {$cliente->nome} está bloqueado e não pode fazer novas locações.");
        }

        $saida = (string) $request->input('data_saida', '');
        $prev = (string) $request->input('data_prev_devolucao', '');
        if ($prev === '') {
            $prev = $saida;   // sem data geral: cada item traz a sua (a locação fica com a mais tarde)
        }
        if (!$this->dataValida($saida) || !$this->dataValida($prev)) {
            throw new Recusa('Informe datas válidas de saída e de devolução prevista.');
        }
        if ($saida > $hoje) {
            throw new Recusa('A data de saída não pode ser no futuro. Para garantir equipamentos para outra data, faça uma reserva.');
        }
        if ($prev < $saida) {
            throw new Recusa('A devolução prevista não pode ser antes da saída.');
        }

        $acrescimo = $this->reais($request->input('acrescimo'));
        $desconto = $this->reais($request->input('desconto'));
        $adiantamento = $this->reais($request->input('adiantamento'));
        if ($acrescimo < 0 || $desconto < 0 || $adiantamento < 0) {
            throw new Recusa('Acréscimo, desconto e adiantamento não podem ser negativos.');
        }
        $autorizadoPor = $this->autorizador($acrescimo, $desconto, (string) $request->input('senha_admin', ''));
        $forma = '';
        if ($adiantamento > 0) {
            $forma = (string) $request->input('forma', '');
            if (!in_array($forma, Estoque::FORMAS_PGTO, true)) {
                throw new Recusa('Escolha a forma de pagamento.');
            }
        }
        $local = trim((string) $request->input('local_uso', ''));
        $obs = trim((string) $request->input('obs', ''));
        if (mb_strlen($local) > 200) {
            throw new Recusa('O local de uso aceita no máximo 200 caracteres.');
        }
        if (mb_strlen($obs) > 2000) {
            throw new Recusa('Observações muito longas (máximo de 2.000 caracteres).');
        }
        $fe = $this->reais($request->input('frete_entrega'));
        $fr = $this->reais($request->input('frete_recolhimento'));
        if ($fe < 0 || $fr < 0 || $fe > 9999999999 || $fr > 9999999999) {
            throw new Recusa('Os fretes não podem ser negativos.');
        }
        $endereco = trim((string) $request->input('endereco_entrega', ''));
        if (mb_strlen($endereco) > 200) {
            throw new Recusa('O endereço de entrega aceita no máximo 200 caracteres.');
        }
        $endereco = $endereco !== '' ? $endereco : $local;

        $reservaId = (string) $request->input('reserva_id', '');
        $reservaId = $reservaId === '' ? null : $reservaId;
        if ($reservaId !== null && !Str::isUuid($reservaId)) {
            throw new Recusa('Reserva inválida.');
        }

        $itens = $this->itens($request);
        foreach ($itens as $pid => $it) {
            $itens[$pid]['prev'] ??= $prev;
            if ($itens[$pid]['prev'] < $saida) {
                throw new Recusa('A devolução prevista de um item não pode ser antes da saída.');
            }
        }
        $prev = max(array_column($itens, 'prev'));

        return DB::transaction(function () use ($cliente, $saida, $prev, $acrescimo, $desconto, $adiantamento, $forma, $local, $obs,
                                                $reservaId, $itens, $fe, $fr, $endereco, $autorizadoPor, $hoje) {
            if ($reservaId !== null) {
                $st = DB::selectOne('SELECT status FROM reservas WHERE id = ? FOR UPDATE', [$reservaId])?->status;
                if ($st !== 'ativa') {
                    throw new Recusa('A reserva não está mais ativa.');
                }
            }
            // Trava os produtos e confere o estoque com os dados já travados
            Estoque::travar(array_keys($itens));
            $cadastro = [];
            foreach (DB::select('SELECT id, descricao, valor_diaria, valor_semanal, valor_quinzenal, valor_mensal, ativo FROM produtos
                                  WHERE id = ANY (CAST(? AS uuid[]))', ['{' . implode(',', array_keys($itens)) . '}']) as $p) {
                $cadastro[$p->id] = $p;
            }
            foreach ($itens as $pid => &$it) {
                if (!isset($cadastro[$pid])) {
                    throw new Recusa('Produto não encontrado.');
                }
                $p = $cadastro[$pid];
                if (!$p->ativo) {
                    throw new Recusa("O produto {$p->descricao} está inativo.");
                }
                $pr = $it['periodo'];
                if (in_array($pr, ['semanal', 'quinzenal', 'mensal'], true)) {
                    // O valor digitado é o do período; os dias que sobram são cobrados na diária do cadastro
                    $precoPlano = (float) ($it['diaria'] ?? $p->{'valor_' . $pr});
                    if ($precoPlano <= 0) {
                        throw new Recusa("Informe o valor {$pr} de {$p->descricao} ou escolha outro período.");
                    }
                    $it['valores'] = ['valor_' . $pr => $precoPlano];
                    $it['diaria'] = (float) $p->valor_diaria;
                }
                $it['diaria'] ??= (float) $p->valor_diaria;
                if ($it['diaria'] < 0) {
                    throw new Recusa('A diária não pode ser negativa.');
                }
            }
            unset($it);
            $falta = Estoque::falta(array_map(fn ($it) => $it['qtd'], $itens), $saida, $prev, $reservaId);
            if ($falta !== null) {
                throw new Recusa($falta);
            }

            $numero = (int) DB::selectOne("SELECT proximo_numero('locacao') AS n")->n;
            $id = DB::selectOne('INSERT INTO locacoes (numero, cliente_id, data_saida, data_prev_devolucao, local_uso, desconto, acrescimo, obs,
                                                       reserva_id, usuario_id, frete_entrega, frete_recolhimento, endereco_entrega, desconto_autorizado_por)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
                                [$numero, $cliente->id, $saida, $prev, $local, $desconto, $acrescimo, $obs, $reservaId, session('usuario.id'),
                                 $fe, $fr, $endereco, $autorizadoPor])->id;
            foreach ($itens as $pid => $it) {
                $v = $it['valores'] ?? [];
                $p = $cadastro[$pid];
                DB::insert('INSERT INTO locacao_itens (locacao_id, produto_id, quantidade, valor_diaria, valor_semanal, valor_quinzenal, valor_mensal,
                                                       data_prev_devolucao, periodo)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                           [$id, $pid, $it['qtd'], $it['diaria'], $v['valor_semanal'] ?? $p->valor_semanal, $v['valor_quinzenal'] ?? $p->valor_quinzenal,
                            $v['valor_mensal'] ?? $p->valor_mensal, $it['prev'], $it['periodo']]);
            }
            if ($reservaId !== null) {
                DB::update("UPDATE reservas SET status = 'convertida' WHERE id = ?", [$reservaId]);
            }
            if ($adiantamento > 0) {
                Locacoes::lancarCaixa((object) ['id' => $id, 'numero' => $numero, 'cliente_nome' => $cliente->nome], 'E', $adiantamento, $forma, $hoje, 'Adiantamento');
            }
            return ['id' => $id, 'numero' => $numero];
        });
    }

    /** Cliente pelo texto escolhido na lista ("Nome · documento"), só entre os ativos. Igual a cliente_resolver(). */
    private function resolverCliente(string $texto): object
    {
        $partes = explode(' · ', trim($texto), 2);
        $nome = trim($partes[0]);
        $doc = so_digitos($partes[1] ?? '');
        if ($nome === '') {
            throw new Recusa('Escolha o cliente.');
        }
        $achados = DB::select("SELECT id, nome, cpf_cnpj, bloqueado FROM clientes WHERE ativo AND nome = ? AND (? = '' OR cpf_cnpj = ?)", [$nome, $doc, $doc]);
        if (!$achados) {
            throw new Recusa('Cliente não encontrado. Escolha um cliente da lista ou cadastre-o antes.');
        }
        if (count($achados) > 1) {
            throw new Recusa('Há mais de um cliente com esse nome. Escolha na lista a opção com o documento.');
        }
        return $achados[0];
    }

    /** Desconto ou acréscimo só com administrador logado ou a senha de um administrador ativo. Devolve quem autorizou. */
    private function autorizador(float $acrescimo, float $desconto, string $senha): ?string
    {
        if ($acrescimo <= 0 && $desconto <= 0) {
            return null;
        }
        if (session('usuario.perfil') === 'admin') {
            return session('usuario.id');
        }
        if ($senha !== '') {
            foreach (DB::select("SELECT id, senha_hash FROM usuarios WHERE perfil = 'admin' AND ativo") as $adm) {
                if (password_verify($senha, $adm->senha_hash)) {
                    return $adm->id;
                }
            }
        }
        throw new Recusa('Desconto ou acréscimo precisa da senha de um administrador.');
    }

    /**
     * Itens do formulário => [produto_id => [qtd, diaria, prev, periodo]]. Igual a itens_do_form(): linhas do mesmo produto
     * somam; valor vazio fica null (usa o do cadastro); o mesmo produto não pode ter períodos ou devoluções diferentes.
     */
    private function itens(Request $request): array
    {
        $qtds = (array) $request->input('quantidade', []);
        $valores = (array) $request->input('diaria', []);
        $prevs = (array) $request->input('prev', []);
        $periodos = (array) $request->input('periodo', []);
        $itens = [];
        foreach ((array) $request->input('produto_id', []) as $i => $pid) {
            $pid = (string) $pid;
            if ($pid === '') {
                continue;
            }
            $linha = (int) $i + 1;
            if (!Str::isUuid($pid)) {
                throw new Recusa("Produto inválido na linha $linha.");
            }
            $qtd = filter_var($qtds[$i] ?? '', FILTER_VALIDATE_INT);
            if ($qtd === false || $qtd < 1 || $qtd > 100000) {
                throw new Recusa("Quantidade inválida na linha $linha.");
            }
            $valorTxt = trim((string) ($valores[$i] ?? ''));
            $prevTxt = trim((string) ($prevs[$i] ?? ''));
            if ($prevTxt !== '' && !$this->dataValida($prevTxt)) {
                throw new Recusa("Data de devolução inválida na linha $linha.");
            }
            $per = trim((string) ($periodos[$i] ?? ''));
            if ($per !== '' && !in_array($per, ['automatico', 'diaria', 'semanal', 'quinzenal', 'mensal'], true)) {
                throw new Recusa("Período inválido na linha $linha.");
            }
            $per = $per === '' ? 'automatico' : $per;
            $itens[$pid] ??= ['qtd' => 0, 'diaria' => null, 'prev' => null, 'periodo' => null];
            if ($itens[$pid]['periodo'] !== null && $itens[$pid]['periodo'] !== $per) {
                throw new Recusa('O mesmo produto aparece com períodos diferentes. Use uma linha por produto.');
            }
            $itens[$pid]['periodo'] = $per;
            $itens[$pid]['qtd'] += $qtd;
            if ($itens[$pid]['diaria'] === null && $valorTxt !== '') {
                $itens[$pid]['diaria'] = $this->reais($valorTxt);
            }
            if ($prevTxt !== '') {
                if ($itens[$pid]['prev'] !== null && $itens[$pid]['prev'] !== $prevTxt) {
                    throw new Recusa('O mesmo produto aparece com datas de devolução diferentes. Use uma linha por produto.');
                }
                $itens[$pid]['prev'] = $prevTxt;
            }
        }
        if (!$itens) {
            throw new Recusa('Inclua pelo menos um item na locação.');
        }
        return $itens;
    }

    /** Linha do formulário a partir de um item de reserva ou de locação (o automático vira diária). */
    private function linhaDe(object $it, string $prev): array
    {
        $per = $it->periodo === 'automatico' ? 'diaria' : $it->periodo;
        $valor = $per === 'diaria' ? $it->valor_diaria : $it->{'valor_' . $per};
        return ['produto_id' => $it->produto_id, 'quantidade' => (string) $it->quantidade, 'diaria' => numero($valor, 2), 'prev' => $prev, 'periodo' => $per];
    }

    /** Valor em reais como o parse_money() antigo: inválido recusa a operação com a mesma mensagem. */
    private function reais($v): float
    {
        $n = valor_br($v ?? '');
        if ($n === null) {
            throw new Recusa('Valor inválido: ' . trim(str_replace(['R$', ' '], '', (string) $v)));
        }
        return $n;
    }

    private function dataValida(string $d): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4));
    }
}
