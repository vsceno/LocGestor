<?php

namespace App\Http\Controllers\Plataforma;

use App\Http\Controllers\Controller;
use App\Support\Asaas;
use App\Support\AsaasErro;
use App\Support\Assinaturas;
use App\Support\Cobrancas;
use App\Support\Estoque;
use App\Support\Formulario;
use App\Support\Locadoras;
use App\Support\Recusa;
use App\Support\Suporte;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Locadoras (tabela tenants) no painel da plataforma: cadastro, plano e mensalidade, suspensão e reativação.
 * Mesmas regras de tenants_index(), tenants_criar(), tenants_salvar() e tenants_status() do sistema antigo.
 */
class LocadorasController extends Controller
{
    public function index(Request $request): View
    {
        Assinaturas::suspenderInadimplentes();
        $locadoras = DB::connection('plataforma')->select(
            'SELECT t.id, t.slug, t.nome, t.email_contato, t.status, t.motivo_suspensao, t.limite_usuarios, t.criado_em,
                    t.vencimento, t.carencia_dias, p.nome AS plano_nome, p.preco_mensal
               FROM tenants t LEFT JOIN planos p ON p.id = t.plano_id ORDER BY t.nome'
        );
        Locadoras::numeros($locadoras);
        foreach ($locadoras as $t) {
            $t->alertas = Assinaturas::alertas($t);
            $t->situacao = Assinaturas::situacao($t);
        }

        $filtro = array_key_exists((string) $request->query('filtro', ''), Assinaturas::FILTROS)
            ? (string) $request->query('filtro')
            : 'todas';
        $ativas = array_filter($locadoras, fn ($t) => $t->status === 'ativa');

        return view('plataforma.locadoras.index', [
            'locadoras' => $locadoras,
            'visiveis' => array_values(array_filter($locadoras, fn ($t) => Assinaturas::passaFiltro($t, $filtro))),
            'filtro' => $filtro,
            'filtros' => Assinaturas::FILTROS,
            'ativas' => count($ativas),
            // Receita mensal recorrente: locadoras ativas com mensalidade
            'mrr' => array_sum(array_map(fn ($t) => $t->vencimento ? (float) $t->preco_mensal : 0, $ativas)),
            'inadimplentes' => count(array_filter($locadoras, fn ($t) => $t->motivo_suspensao === 'inadimplencia' && $t->status === 'suspensa')),
            'atencao' => array_filter($locadoras, fn ($t) => $t->alertas !== []),
        ]);
    }

    public function nova(): View
    {
        return view('plataforma.locadoras.nova', ['planos' => Assinaturas::planosAtivos()]);
    }

    public function criar(Request $request): RedirectResponse
    {
        [$dados, $erros] = $this->dados($request);
        $slug = strtolower(trim((string) $request->input('slug', '')));
        $adminNome = trim((string) $request->input('admin_nome', ''));
        $adminLogin = strtolower(trim((string) $request->input('admin_login', '')));
        if (! preg_match('/^[a-z0-9][a-z0-9-]{1,38}[a-z0-9]$/', $slug)) {
            $erros[] = 'Identificador inválido: use de 3 a 40 letras minúsculas, números e hífen (sem hífen no início ou no fim).';
        }
        if ($adminNome === '' || mb_strlen($adminNome) > 100) {
            $erros[] = 'Informe o nome do administrador.';
        }
        if (! preg_match('/^[a-z0-9._-]{3,40}$/', $adminLogin)) {
            $erros[] = 'Login do administrador inválido (3 a 40 letras minúsculas, números, ponto, hífen ou sublinhado).';
        }
        if ($erros) {
            return $this->voltarComErro(implode(' ', $erros));
        }

        $senha = Locadoras::senhaTemporaria();
        try {
            Locadoras::provisionar($dados + [
                'slug' => $slug, 'admin_nome' => $adminNome, 'admin_login' => $adminLogin,
                'admin_senha' => $senha, 'trocar_senha' => true,
            ]);
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                return $this->voltarComErro('Já existe uma locadora com este identificador.');
            }
            throw $e;
        }
        Log::info('Locadora criada.', ['slug' => $slug]);

        // A senha temporária é mostrada uma única vez: vai na sessão só para a próxima tela
        return redirect()->route('plataforma.locadoras.criada')
            ->with('provisionada', ['nome' => $dados['nome'], 'slug' => $slug, 'login' => $adminLogin, 'senha' => $senha]);
    }

    public function criada(): Response|RedirectResponse
    {
        $p = session('provisionada');
        if ($p === null) {
            return redirect()->route('plataforma.locadoras');
        }

        return response()->view('plataforma.locadoras.criada', ['p' => $p])->header('Cache-Control', 'no-store');
    }

    public function editar(string $id): View
    {
        $t = $this->locadora($id);

        return view('plataforma.locadoras.editar', [
            'locadora' => $t,
            'planos' => Assinaturas::planosAtivos(),
            'situacao' => Assinaturas::situacao($t),
            'cobrancas' => DB::connection('plataforma')->select(
                'SELECT * FROM assinatura_cobrancas WHERE locadora_id = ? ORDER BY vencimento DESC, criado_em DESC LIMIT 24',
                [$id]
            ),
            'asaasAtivo' => Asaas::ativo(),
            'asaasAmbiente' => Asaas::config()['ambiente'],
            'diasAntes' => Asaas::config()['dias_antes'],
            'pagamentos' => DB::connection('plataforma')->select(
                'SELECT p.*, u.nome AS usuario FROM assinatura_pagamentos p LEFT JOIN plataforma_usuarios u ON u.id = p.usuario_id
                  WHERE p.locadora_id = ? ORDER BY p.criado_em DESC LIMIT 36',
                [$id]
            ),
            'usuarios' => Locadoras::noContexto($id, fn ($db) => $db->select(
                'SELECT id, nome, login, perfil, ativo, ultimo_acesso FROM usuarios ORDER BY ativo DESC, lower(nome)'
            )),
            'acessos' => DB::connection('plataforma')->select(
                'SELECT login_suporte, motivo, criado_em, usado_em FROM acessos_suporte WHERE tenant_id = ? ORDER BY criado_em DESC LIMIT 10',
                [$id]
            ),
        ]);
    }

    public function salvar(Request $request, string $id): RedirectResponse
    {
        $t = $this->locadora($id);
        if ($t->status === 'cancelada') {
            return $this->voltarComErro('Locadora cancelada: o contrato não pode mais ser alterado.');
        }
        [$dados, $erros] = $this->dados($request);
        // Limite em branco = o do plano escolhido; preenchido, é um limite especial
        $limite = $request->filled('limite_usuarios')
            ? filter_var($request->input('limite_usuarios'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]])
            : $dados['plano']?->limite_usuarios;
        if ($limite === false) {
            $erros[] = 'O limite de usuários deve ser de 1 a 1000.';
        }
        if ($erros) {
            return $this->voltarComErro(implode(' ', $erros));
        }

        DB::connection('plataforma')->update(
            'UPDATE tenants SET nome = ?, cnpj = ?, email_contato = ?, telefone = ?, plano_id = ?, plano = ?,
                    limite_usuarios = ?, vencimento = ?, carencia_dias = ? WHERE id = ?',
            [$dados['nome'], $dados['cnpj'], $dados['email'], $dados['telefone'], $dados['plano']->id, $dados['plano']->nome,
                $limite, $dados['vencimento'], $dados['carencia'], $id]
        );
        Log::info('Locadora atualizada.', ['slug' => $t->slug]);

        $avisos = [['tipo' => 'ok', 'msg' => 'Locadora atualizada.']];
        // Cobrança em aberto no Asaas para um vencimento que deixou de valer
        if (DB::connection('plataforma')->selectOne(
            "SELECT 1 FROM assinatura_cobrancas WHERE locadora_id = ? AND status = 'pendente' AND vencimento IS DISTINCT FROM CAST(? AS date)",
            [$id, $dados['vencimento']]
        ) !== null) {
            $avisos[] = ['tipo' => 'aviso', 'msg' => 'Há cobrança pendente no Asaas com outro vencimento. Cancele-a abaixo se não valer mais.'];
        }

        return redirect()->route('plataforma.locadoras.editar', $id)->with('flash', $avisos);
    }

    public function status(Request $request, string $id): RedirectResponse
    {
        $status = (string) $request->input('status', '');
        if (! in_array($status, ['ativa', 'suspensa'], true)) {
            return $this->voltarComErro('Requisição inválida.');
        }
        $t = $this->locadora($id);
        if ($t->status === 'cancelada') {
            return $this->voltarComErro('Locadora cancelada: o acesso não volta pelo painel.');
        }
        DB::connection('plataforma')->update('UPDATE tenants SET status = ?, motivo_suspensao = ? WHERE id = ?',
            [$status, $status === 'suspensa' ? 'manual' : '', $id]);
        Log::info("Locadora agora está $status.", ['slug' => $t->slug]);

        // Reativar não adianta se a mensalidade continua vencida além da carência: o acesso segue bloqueado
        if ($status === 'ativa' && Assinaturas::inadimplente($t)) {
            return redirect()->route('plataforma.locadoras.editar', $id)->with('flash', [['tipo' => 'aviso',
                'msg' => "Locadora {$t->nome} reativada, mas a mensalidade continua vencida além da carência: o acesso seguirá bloqueado até registrar o pagamento ou ajustar o vencimento."]]);
        }
        $msg = $status === 'ativa'
            ? "Locadora {$t->nome} reativada."
            : "Locadora {$t->nome} suspensa. Os usuários dela perderam o acesso.";

        return redirect()->route('plataforma.locadoras.editar', $id)->with('flash', [['tipo' => 'ok', 'msg' => $msg]]);
    }

    /** Registra o pagamento de mensalidades: avança o vencimento e reativa a locadora suspensa por inadimplência. */
    public function pagamento(Request $request, string $id): RedirectResponse
    {
        $t = $this->locadora($id);
        if ($t->status === 'cancelada') {
            return $this->voltarComErro('Locadora cancelada: não há mensalidade a registrar.');
        }
        $meses = filter_var($request->input('meses', ''), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 24]]);
        $data = trim((string) $request->input('data', ''));
        if ($meses === false || ! Formulario::dataValida($data) || $data > now()->toDateString()) {
            return $this->voltarComErro('Confira os meses (1 a 24) e a data do pagamento.');
        }
        $forma = (string) $request->input('forma', '');
        if (! in_array($forma, Estoque::FORMAS_PGTO, true)) {
            return $this->voltarComErro('Escolha a forma de pagamento.');
        }
        // Em branco = preço do plano × meses; preenchido, o valor digitado (ex.: "1.234,50")
        $valor = $request->filled('valor') ? valor_br($request->input('valor')) : null;
        if ($request->filled('valor') && $valor === null) {
            return $this->voltarComErro('Valor inválido.');
        }

        try {
            $r = Assinaturas::registrarPagamento(
                $id, $meses, $data, $valor, $forma,
                mb_substr(trim((string) $request->input('obs', '')), 0, 200),
                session('plataforma.usuario.id')
            );
        } catch (Recusa $e) {
            return $this->voltarComErro($e->getMessage());
        }
        $msg = 'Pagamento de '.moeda($r['valor']).' registrado. Próximo vencimento: '.data_br($r['novo']).'.'
            .($r['reativou'] ? ' Locadora reativada.' : '');

        return redirect()->route('plataforma.locadoras.editar', $id)->with('flash', [['tipo' => 'ok', 'msg' => $msg]]);
    }

    /** Estorna o último pagamento: o vencimento volta ao que era antes dele. Mesmas recusas do sistema antigo. */
    public function estornar(string $id): RedirectResponse
    {
        $this->locadora($id);
        try {
            DB::connection('plataforma')->transaction(function () use ($id) {
                $db = DB::connection('plataforma');
                $venc = $db->selectOne('SELECT vencimento FROM tenants WHERE id = ? FOR UPDATE', [$id]);
                $p = $db->selectOne(
                    'SELECT id, vencimento_anterior, vencimento_novo FROM assinatura_pagamentos
                      WHERE locadora_id = ? AND estornado_em IS NULL ORDER BY criado_em DESC LIMIT 1 FOR UPDATE',
                    [$id]
                );
                if ($venc === null || $p === null) {
                    throw new Recusa('Nenhum pagamento para estornar.');
                }
                if ($db->selectOne('SELECT 1 FROM assinatura_cobrancas WHERE pagamento_id = ?', [$p->id]) !== null) {
                    throw new Recusa('Este pagamento foi recebido pelo Asaas e não é estornado aqui. Se o dinheiro foi devolvido ao cliente no Asaas, ajuste o vencimento manualmente.');
                }
                if ($venc->vencimento !== $p->vencimento_novo) {
                    throw new Recusa('O vencimento foi alterado depois deste pagamento. Ajuste o vencimento manualmente e exclua o pagamento pelo banco, se preciso.');
                }
                // Não se apaga pagamento (o recibo continua existindo): marca como estornado e devolve o vencimento
                $db->update("UPDATE assinatura_pagamentos SET estornado_em = now(), estorno_motivo = 'Estornado pelo painel'
                              WHERE id = ?", [$p->id]);
                $db->update('UPDATE tenants SET vencimento = ? WHERE id = ?', [$p->vencimento_anterior, $id]);
            });
        } catch (Recusa $e) {
            return $this->voltarComErro($e->getMessage());
        }

        return redirect()->route('plataforma.locadoras.editar', $id)->with('flash', [['tipo' => 'ok', 'msg' => 'Último pagamento estornado.']]);
    }

    /** Gera uma senha temporária para um usuário da locadora (quando ele esqueceu). Mostrada uma única vez. */
    public function resetSenha(Request $request, string $id): RedirectResponse
    {
        $t = $this->locadora($id);
        $usuario = (string) $request->input('usuario', '');
        if ($t->status === 'cancelada') {
            return $this->voltarComErro('Locadora não encontrada ou cancelada.');
        }
        if (! Str::isUuid($usuario)) {
            return $this->voltarComErro('Usuário não encontrado.');
        }

        $senha = Locadoras::senhaTemporaria();
        $u = Locadoras::noContexto($id, fn ($db) => $db->selectOne(
            'UPDATE usuarios SET senha_hash = ?, trocar_senha = true, senha_redefinida_em = now() WHERE id = ? AND ativo RETURNING login',
            [password_hash($senha, PASSWORD_DEFAULT), $usuario]
        ));
        if ($u === null) {
            return $this->voltarComErro('Usuário não encontrado ou inativo.');
        }
        Log::info('Senha de um usuário da locadora redefinida pelo painel.', ['slug' => $t->slug]);

        return redirect()->route('plataforma.locadoras.editar', $id)
            ->with('senhaRedefinida', ['login' => $u->login, 'senha' => $senha])
            ->with('flash', [['tipo' => 'ok', 'msg' => 'Senha temporária gerada. Anote agora: ela não aparece de novo.']]);
    }

    /**
     * Abre o acesso de suporte: cria o código de uso único e manda o suporte para a locadora. Só com locadora ativa e
     * um motivo de pelo menos 10 caracteres, que fica na auditoria da locadora.
     */
    public function suporteEntrar(Request $request, string $id): RedirectResponse
    {
        $t = $this->locadora($id);
        if ($t->status !== 'ativa') {
            return $this->voltarComErro('Só é possível abrir o acesso de suporte em locadora ativa.');
        }
        $motivo = trim((string) $request->input('motivo', ''));
        if (mb_strlen($motivo) < 10 || mb_strlen($motivo) > 300) {
            return $this->voltarComErro('Informe o motivo do acesso (de 10 a 300 caracteres).');
        }

        [$codigo, $hash] = Suporte::codigo($t->id);
        DB::connection('plataforma')->insert(
            'INSERT INTO acessos_suporte (tenant_id, login_suporte, motivo, token_hash, expira_em)
             VALUES (?, ?, ?, ?, now() + make_interval(mins => ?))',
            [$t->id, (string) session('plataforma.usuario.login', ''), $motivo, $hash, Suporte::CODIGO_MIN]
        );
        Log::info('Acesso de suporte aberto na locadora.', ['slug' => $t->slug]);

        return redirect()->route('suporte.entrar', ['t' => $codigo]);
    }

    /** Liga ou desliga a cobrança automática: a rotina diária gera as cobranças no Asaas. */
    public function cobrancaConfig(Request $request, string $id): RedirectResponse
    {
        $t = $this->locadora($id);
        $ligar = $request->input('cobranca_automatica') === '1';
        if ($ligar && (strlen($t->cnpj) !== 14 || empty($t->vencimento) || (float) $t->preco_mensal <= 0)) {
            return $this->voltarComErro('Para cobrar pelo Asaas a locadora precisa de CNPJ, próximo vencimento e um plano pago.');
        }
        DB::connection('plataforma')->update('UPDATE tenants SET cobranca_automatica = ? WHERE id = ?', [$ligar ? 'true' : 'false', $id]);

        return redirect()->route('plataforma.locadoras.editar', $id)->with('flash', [['tipo' => 'ok',
            'msg' => $ligar ? 'Cobrança automática ligada: a rotina diária gera as cobranças no Asaas.' : 'Cobrança automática desligada.']]);
    }

    /** Gera agora, no Asaas, a cobrança do vencimento atual. */
    public function cobrancaGerar(string $id): RedirectResponse
    {
        $this->locadora($id);
        try {
            $c = Cobrancas::gerar($id);
        } catch (Recusa $e) {
            return $this->voltarComErro($e->getMessage());
        } catch (AsaasErro $e) {
            Log::error($e->getMessage());

            return $this->voltarComErro($e->getMessage());
        }

        return redirect()->route('plataforma.locadoras.editar', $id)->with('flash', [['tipo' => 'ok',
            'msg' => 'Cobrança de '.moeda($c->valor).' com vencimento em '.data_br($c->vencimento).' '
                .($c->status === 'pendente' ? 'disponível' : $c->status).' no Asaas.']]);
    }

    /** Confere no Asaas as cobranças pendentes desta locadora e dá baixa nas que foram pagas. */
    public function cobrancaSincronizar(string $id): RedirectResponse
    {
        $this->locadora($id);
        $pagas = 0;
        try {
            foreach (DB::connection('plataforma')->select(
                "SELECT id FROM assinatura_cobrancas WHERE locadora_id = ? AND status = 'pendente' AND gateway_id IS NOT NULL", [$id]
            ) as $c) {
                $pagas += Cobrancas::sincronizar($c->id) === 'paga' ? 1 : 0;
            }
        } catch (AsaasErro $e) {
            Log::error($e->getMessage());

            return $this->voltarComErro($e->getMessage());
        }

        return redirect()->route('plataforma.locadoras.editar', $id)->with('flash', [['tipo' => 'ok',
            'msg' => $pagas > 0 ? "$pagas pagamento(s) recebido(s) e registrado(s)." : 'Nenhum pagamento novo no Asaas.']]);
    }

    /** Cancela uma cobrança pendente, no Asaas e aqui. */
    public function cobrancaCancelar(Request $request, string $id): RedirectResponse
    {
        $this->locadora($id);
        $cobranca = (string) $request->input('cobranca', '');
        if (! Str::isUuid($cobranca) || DB::connection('plataforma')->selectOne(
            'SELECT 1 FROM assinatura_cobrancas WHERE id = ? AND locadora_id = ?', [$cobranca, $id]
        ) === null) {
            return $this->voltarComErro('Cobrança não encontrada.');
        }
        try {
            Cobrancas::cancelar($cobranca);
        } catch (Recusa $e) {
            return $this->voltarComErro($e->getMessage());
        } catch (AsaasErro $e) {
            Log::error($e->getMessage());

            return $this->voltarComErro($e->getMessage());
        }

        return redirect()->route('plataforma.locadoras.editar', $id)->with('flash', [['tipo' => 'ok', 'msg' => 'Cobrança cancelada.']]);
    }

    /**
     * Cancela o contrato: cancela no Asaas as cobranças pendentes, bloqueia o acesso e zera o vencimento. É definitivo
     * pelo painel (a data fica em cancelada_em para os números). Mesmas regras de tenants_cancelar() do sistema antigo.
     */
    public function cancelarContrato(string $id): RedirectResponse
    {
        $t = $this->locadora($id);
        if ($t->status === 'cancelada') {
            return $this->voltarComErro('Esta locadora já está cancelada.');
        }
        $db = DB::connection('plataforma');
        try {
            $db->transaction(function () use ($db, $t) {
                foreach ($db->select("SELECT id FROM assinatura_cobrancas WHERE locadora_id = ? AND status = 'pendente'", [$t->id]) as $c) {
                    Cobrancas::cancelar($c->id);
                }
                $db->update("UPDATE tenants SET status = 'cancelada', cancelada_em = CURRENT_DATE, vencimento = NULL,
                             motivo_suspensao = '', cobranca_automatica = false WHERE id = ?", [$t->id]);
            });
        } catch (Recusa $e) {
            return $this->voltarComErro($e->getMessage());
        } catch (AsaasErro $e) {
            Log::error($e->getMessage());

            return $this->voltarComErro($e->getMessage());
        }
        Log::info('Contrato da locadora cancelado.', ['slug' => $t->slug]);

        return redirect()->route('plataforma.locadoras.editar', $id)
            ->with('flash', [['tipo' => 'ok', 'msg' => "Contrato de {$t->nome} cancelado. O acesso está bloqueado."]]);
    }

    /** Locadora pelo id da URL (ou 404). */
    private function locadora(string $id): object
    {
        abort_unless(Str::isUuid($id), 404);

        return DB::connection('plataforma')->selectOne(
            'SELECT t.*, p.nome AS plano_nome, p.preco_mensal FROM tenants t LEFT JOIN planos p ON p.id = t.plano_id WHERE t.id = ?',
            [$id]
        ) ?? abort(404, 'Locadora não encontrada.');
    }

    /**
     * Campos que criar e editar têm em comum, com as mesmas regras do sistema antigo. Devolve [dados, erros]
     * (lista de erros vazia = tudo certo).
     */
    private function dados(Request $request): array
    {
        $dados = [
            'nome' => trim((string) $request->input('nome', '')),
            'cnpj' => so_digitos($request->input('cnpj', '')),
            'email' => trim((string) $request->input('email_contato', '')),
            'telefone' => trim((string) $request->input('telefone', '')),
        ];
        $erros = [];
        if (mb_strlen($dados['nome']) < 3 || mb_strlen($dados['nome']) > 150) {
            $erros[] = 'Informe o nome da locadora (3 a 150 caracteres).';
        }
        if ($dados['cnpj'] !== '' && (strlen($dados['cnpj']) !== 14 || ! doc_valido($dados['cnpj']))) {
            $erros[] = 'CNPJ inválido.';
        }
        if ($dados['email'] !== '' && filter_var($dados['email'], FILTER_VALIDATE_EMAIL) === false) {
            $erros[] = 'E-mail de contato inválido.';
        }
        if (mb_strlen($dados['telefone']) > 20) {
            $erros[] = 'Telefone muito longo.';
        }

        $assinatura = Assinaturas::formulario($request->all());

        return [
            $dados + ['plano' => $assinatura['plano'], 'vencimento' => $assinatura['vencimento'], 'carencia' => $assinatura['carencia']],
            [...$erros, ...$assinatura['erros']],
        ];
    }

    private function voltarComErro(string $msg): RedirectResponse
    {
        return back()->withInput()->with('flash', [['tipo' => 'erro', 'msg' => $msg]]);
    }
}
