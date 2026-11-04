<?php

namespace App\Http\Controllers;

use App\Support\Configuracoes;
use App\Support\Locadoras;
use App\Support\Recusa;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Cadastro público ("Teste grátis"): uma locadora nova se cadastra e começa o período de teste, sem cartão.
 * Desligado por padrão (chave na tela Configurações da LocGestor, que não abre sem os termos de uso). Contra robôs, sem serviço externo: campo-isca invisível, tempo
 * mínimo de preenchimento e no máximo 3 cadastros por IP por hora (o IP é guardado só como hash). A criação é a mesma
 * do painel da plataforma. Mesmas regras de cadastro.php do sistema antigo.
 */
class CadastroController extends Controller
{
    private const MAX_POR_IP = 3;

    private const TEMPO_MIN = 3;   // segundos entre abrir e enviar o formulário

    public function form(Request $request): View
    {
        $this->ativo();
        $request->session()->put('cadastro_inicio', time());

        return view('cadastro', ['dias' => $this->dias()]);
    }

    public function criar(Request $request): RedirectResponse
    {
        $this->ativo();
        try {
            $ok = $this->cadastrar($request);
        } catch (Recusa $e) {
            return back()->withInput($request->except(['senha', 'senha_confirma']))
                ->with('flash', [['tipo' => 'erro', 'msg' => $e->getMessage()]]);
        }

        // O identificador da empresa já fica preenchido no login, como no sistema antigo
        Cookie::queue('lg_empresa', $ok['slug'], 365 * 24 * 60);

        return redirect()->route('cadastro.pronto')->with('cadastro_ok', $ok);
    }

    public function pronto(): View|RedirectResponse
    {
        $ok = session('cadastro_ok');
        if ($ok === null) {
            return redirect()->route('cadastro');
        }

        return view('cadastro-pronto', ['ok' => $ok]);
    }

    /** Valida, confere os limites contra robôs e cria a locadora. Devolve os dados de acesso para a tela de sucesso. */
    private function cadastrar(Request $request): array
    {
        if (trim((string) $request->input('site', '')) !== '') {
            Log::warning('Cadastro público recusado: campo-isca preenchido (robô).');
            throw new Recusa('Não foi possível concluir o cadastro. Tente de novo.');
        }
        $inicio = (int) $request->session()->get('cadastro_inicio', 0);
        if ($inicio === 0 || time() - $inicio < self::TEMPO_MIN) {
            throw new Recusa('Confira os dados e envie o formulário de novo.');
        }

        $db = DB::connection('plataforma');
        $chave = hash('sha256', 'cadastro|ip|'.$request->ip());
        $recentes = (int) $db->selectOne(
            "SELECT count(*) AS n FROM login_tentativas WHERE chave = ? AND criado_em > now() - INTERVAL '1 hour'", [$chave]
        )->n;
        if ($recentes >= self::MAX_POR_IP) {
            Log::alert('Cadastro público: limite de cadastros por IP atingido.');
            throw new Recusa('Muitos cadastros feitos daqui na última hora. Tente mais tarde ou fale com o suporte.');
        }

        $d = [
            'nome' => trim((string) $request->input('nome', '')),
            'slug' => strtolower(trim((string) $request->input('slug', ''))),
            'cnpj' => so_digitos($request->input('cnpj', '')),
            'email' => trim((string) $request->input('email', '')),
            'telefone' => trim((string) $request->input('telefone', '')),
            'admin_nome' => trim((string) $request->input('admin_nome', '')),
            'admin_login' => strtolower(trim((string) $request->input('admin_login', 'admin'))),
        ];
        $senha = (string) $request->input('senha', '');

        $erros = [];
        if (mb_strlen($d['nome']) < 3 || mb_strlen($d['nome']) > 150) {
            $erros[] = 'Informe o nome da locadora (3 a 150 caracteres).';
        }
        if (! preg_match('/^[a-z0-9][a-z0-9-]{1,38}[a-z0-9]$/', $d['slug'])) {
            $erros[] = 'Identificador inválido: de 3 a 40 letras minúsculas, números e hífen (sem hífen no início ou no fim).';
        }
        if ($d['cnpj'] !== '' && (strlen($d['cnpj']) !== 14 || ! doc_valido($d['cnpj']))) {
            $erros[] = 'CNPJ inválido.';
        }
        if (filter_var($d['email'], FILTER_VALIDATE_EMAIL) === false || mb_strlen($d['email']) > 120) {
            $erros[] = 'Informe um e-mail válido.';
        }
        if (! in_array(strlen(so_digitos($d['telefone'])), [10, 11], true) || mb_strlen($d['telefone']) > 20) {
            $erros[] = 'Informe o telefone com DDD.';
        }
        if (mb_strlen($d['admin_nome']) < 3 || mb_strlen($d['admin_nome']) > 100) {
            $erros[] = 'Informe o seu nome (3 a 100 caracteres).';
        }
        if (! preg_match('/^[a-z0-9._-]{3,40}$/', $d['admin_login'])) {
            $erros[] = 'Usuário inválido (3 a 40 letras minúsculas, números, ponto, hífen ou sublinhado).';
        }
        if (mb_strlen($senha) < 8) {
            $erros[] = 'A senha deve ter pelo menos 8 caracteres.';
        } elseif ($senha !== (string) $request->input('senha_confirma', '')) {
            $erros[] = 'A confirmação da senha não confere.';
        }
        if (! $request->boolean('aceite')) {
            $erros[] = 'Confirme que leu e aceita os termos de uso.';
        }
        if ($erros) {
            throw new Recusa(implode(' ', $erros));
        }

        $plano = $this->plano() ?? throw new Recusa('Cadastro indisponível no momento. Fale com o suporte.');
        $vencimento = now()->addDays($this->dias())->toDateString();
        try {
            Locadoras::provisionar([
                'nome' => $d['nome'], 'slug' => $d['slug'], 'cnpj' => $d['cnpj'], 'email' => $d['email'], 'telefone' => $d['telefone'],
                'plano' => $plano, 'vencimento' => $vencimento, 'carencia' => 5, 'teste_ate' => $vencimento,
                'admin_nome' => $d['admin_nome'], 'admin_login' => $d['admin_login'], 'admin_senha' => $senha,
                'trocar_senha' => false,   // quem se cadastra escolheu a própria senha
            ]);
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                throw new Recusa('Já existe uma locadora com este identificador. Escolha outro.');
            }
            throw $e;
        }

        $db->insert('INSERT INTO login_tentativas (chave) VALUES (?)', [$chave]);
        Log::info('Locadora criada pelo cadastro público.', ['slug' => $d['slug'], 'vencimento' => $vencimento]);

        return ['nome' => $d['nome'], 'slug' => $d['slug'], 'login' => $d['admin_login'], 'vencimento' => $vencimento];
    }

    /** Plano do período de teste: o escolhido em Configurações da LocGestor ou o mais barato ativo. */
    private function plano(): ?object
    {
        $escolhido = Configuracoes::ler()->cadastro_plano_id;
        $db = DB::connection('plataforma');

        return $escolhido !== null
            ? $db->selectOne('SELECT id, nome, limite_usuarios FROM planos WHERE ativo AND id = ?', [$escolhido])
            : $db->selectOne('SELECT id, nome, limite_usuarios FROM planos WHERE ativo ORDER BY preco_mensal, nome LIMIT 1');
    }

    /** Dias do período de teste (entre 1 e 90, como na tela Configurações). */
    private function dias(): int
    {
        return max(1, min(90, (int) Configuracoes::ler()->cadastro_dias_teste));
    }

    /**
     * Sem o cadastro ligado, a página não existe (404), como no sistema antigo. Também não abre sem o endereço dos
     * termos de uso publicados: ninguém se cadastra aceitando um texto que não existe.
     */
    private function ativo(): void
    {
        abort_unless(Configuracoes::cadastroAberto(), 404);
    }
}
