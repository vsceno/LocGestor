<?php

namespace App\Http\Controllers\Plataforma;

use App\Http\Controllers\Controller;
use App\Support\Configuracoes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Configurações da LocGestor: cada seção valida e grava só os próprios campos (rotas separadas), e o banco registra
 * quem mudou o quê na auditoria. Segredos (Asaas, proxies) não aparecem aqui: só se estão configurados.
 */
class ConfiguracoesController extends Controller
{
    /** Colunas que cada seção pode gravar. Fixas no código: nada vindo do formulário vira nome de coluna. */
    private const CAMPOS_EMPRESA = ['emitente_razao', 'emitente_cnpj', 'emitente_endereco', 'emitente_contato'];

    private const CAMPOS_IDENTIDADE = ['nome_sistema', 'logo_versao'];

    private const CAMPOS_DOCUMENTOS = ['termos_url', 'privacidade_url', 'observacao_recibo'];

    private const CAMPOS_CADASTRO = ['cadastro_publico', 'cadastro_dias_teste', 'cadastro_plano_id'];

    private const CAMPOS_SUPORTE = ['suporte_contato'];

    /** Mensagens em português: o app roda em inglês e não tem arquivo de traduções, então sem isto sairiam as chaves. */
    private const MENSAGENS = [
        'required' => 'Preencha este campo.',
        'string' => 'Use apenas texto.',
        'min' => 'Use pelo menos :min caracteres.',
        'max' => 'Use no máximo :max caracteres.',
        'logo.max' => 'A logomarca pode ter até 2 MB.',
        'logo.mimes' => 'Use uma imagem PNG, JPG ou WEBP.',
        'logo.file' => 'Envie um arquivo de imagem.',
        'integer' => 'Informe um número inteiro.',
        'between' => 'Informe um valor entre :min e :max.',
        'uuid' => 'Escolha um plano da lista.',
        'exists' => 'Escolha um plano da lista.',
        'boolean' => 'Valor inválido.',
        'regex' => 'Use um endereço que comece com https:// e não tenha espaços.',
    ];

    public function index(): View
    {
        $db = DB::connection('plataforma');

        return view('plataforma.configuracoes', [
            'c' => Configuracoes::ler(),
            'planos' => $db->select('SELECT id, nome, preco_mensal FROM planos WHERE ativo ORDER BY preco_mensal'),
            'proximoRecibo' => (int) ($db->selectOne("SELECT ultimo FROM plataforma_sequencias WHERE nome = 'recibo'")?->ultimo ?? 0) + 1,
            'asaas' => [
                'configurado' => config('locgestor.asaas.chave') !== '',
                'ambiente' => config('locgestor.asaas.ambiente'),
                'webhook_token' => config('locgestor.asaas.webhook_token') !== '',
            ],
            'proxies' => count(array_filter(explode(',', (string) config('locgestor.proxies')))),
        ]);
    }

    public function empresa(Request $request): RedirectResponse
    {
        $dados = $this->validar($request, 'empresa', [
            'emitente_razao' => ['required', 'string', 'min:3', 'max:150'],
            'emitente_cnpj' => ['nullable', 'string', 'max:18', function (string $atributo, mixed $valor, \Closure $falha): void {
                $digitos = preg_replace('/\D/', '', (string) $valor);
                if ($digitos !== '' && (strlen($digitos) !== 14 || ! doc_valido($digitos))) {
                    $falha('CNPJ inválido. Confira os 14 dígitos.');
                }
            }],
            'emitente_endereco' => ['nullable', 'string', 'max:200'],
            'emitente_contato' => ['nullable', 'string', 'max:200'],
        ]);

        $this->atualizar([
            'emitente_razao' => trim($dados['emitente_razao']),
            'emitente_cnpj' => preg_replace('/\D/', '', (string) ($dados['emitente_cnpj'] ?? '')),
            'emitente_endereco' => trim((string) ($dados['emitente_endereco'] ?? '')),
            'emitente_contato' => trim((string) ($dados['emitente_contato'] ?? '')),
        ], self::CAMPOS_EMPRESA);

        return $this->ok('Dados da empresa salvos.');
    }

    public function identidade(Request $request): RedirectResponse
    {
        $dados = $this->validar($request, 'identidade', [
            'nome_sistema' => ['required', 'string', 'min:2', 'max:40'],
            'logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'remover_logo' => ['nullable', 'boolean'],
        ]);

        $campos = ['nome_sistema' => trim($dados['nome_sistema'])];
        try {
            if ($request->hasFile('logo')) {
                Configuracoes::gravarLogo($request->file('logo')->getRealPath());
                $campos['logo_versao'] = now()->format('YmdHis');
            } elseif ($request->boolean('remover_logo')) {
                Configuracoes::removerLogo();
                $campos['logo_versao'] = '';
            }
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['logo' => $e->getMessage()])->errorBag('identidade');
        }

        $this->atualizar($campos, self::CAMPOS_IDENTIDADE);

        return $this->ok('Identidade visual salva.');
    }

    public function documentos(Request $request): RedirectResponse
    {
        $https = ['nullable', 'string', 'max:300', 'regex:/^https:\/\/[^\s]+$/'];
        $dados = $this->validar($request, 'documentos', [
            'termos_url' => $https,
            'privacidade_url' => $https,
            'observacao_recibo' => ['nullable', 'string', 'max:300'],
        ]);

        $this->atualizar([
            'termos_url' => trim((string) ($dados['termos_url'] ?? '')),
            'privacidade_url' => trim((string) ($dados['privacidade_url'] ?? '')),
            'observacao_recibo' => trim((string) ($dados['observacao_recibo'] ?? '')),
        ], self::CAMPOS_DOCUMENTOS);

        return $this->ok('Documentos salvos.');
    }

    public function cadastro(Request $request): RedirectResponse
    {
        $dados = $this->validar($request, 'cadastro', [
            'cadastro_publico' => ['nullable', 'boolean'],
            'cadastro_dias_teste' => ['required', 'integer', 'between:1,90'],
            // Conexão da plataforma: a área das locadoras não tem leitura da tabela de planos
            'cadastro_plano_id' => ['nullable', 'uuid', 'exists:plataforma.planos,id'],
        ]);

        $ligar = $request->boolean('cadastro_publico');
        // Trava: o cadastro público só abre com os termos de uso publicados (a própria política da plataforma)
        if ($ligar && trim(Configuracoes::ler()->termos_url) === '') {
            throw ValidationException::withMessages(['cadastro_publico' => 'Antes de abrir o cadastro, informe o endereço dos termos de uso (seção Documentos).'])
                ->errorBag('cadastro');
        }

        $this->atualizar([
            'cadastro_publico' => $ligar,
            'cadastro_dias_teste' => (int) $dados['cadastro_dias_teste'],
            'cadastro_plano_id' => $dados['cadastro_plano_id'] ?? null,
        ], self::CAMPOS_CADASTRO);

        return $this->ok($ligar ? 'Cadastro público aberto.' : 'Cadastro público fechado.');
    }

    public function suporte(Request $request): RedirectResponse
    {
        $dados = $this->validar($request, 'suporte', [
            'suporte_contato' => ['nullable', 'string', 'max:200'],
        ]);

        $this->atualizar(['suporte_contato' => trim((string) ($dados['suporte_contato'] ?? ''))], self::CAMPOS_SUPORTE);

        return $this->ok('Contato de suporte salvo.');
    }

    /** Valida a seção no próprio bag de erros, com as mensagens em português. */
    private function validar(Request $request, string $bag, array $regras): array
    {
        return $request->validateWithBag($bag, $regras, self::MENSAGENS);
    }

    /** Grava só as colunas permitidas da seção (a lista vem do código, nunca do formulário). */
    private function atualizar(array $campos, array $permitidos): void
    {
        $gravar = array_intersect_key($campos, array_flip($permitidos));
        $atribuicoes = implode(', ', array_map(fn (string $coluna): string => "{$coluna} = ?", array_keys($gravar)));

        DB::connection('plataforma')->update(
            "UPDATE plataforma_configuracoes SET {$atribuicoes}, atualizado_em = now() WHERE id = ?",
            [...array_values($gravar), Configuracoes::ID]
        );
    }

    private function ok(string $mensagem): RedirectResponse
    {
        return redirect()->route('plataforma.configuracoes')->with('flash', [['tipo' => 'ok', 'msg' => $mensagem]]);
    }
}
