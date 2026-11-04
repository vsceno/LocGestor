<?php

namespace App\Support;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Locadoras vistas pelo painel da plataforma: criação com dados padrão e administrador, senha temporária e os números
 * de cada uma. As tabelas das locadoras só enxergam os dados dela com o contexto definido (RLS), então cada leitura
 * roda dentro de noContexto(), pela conexão 'plataforma'.
 */
class Locadoras
{
    /** Recursos que contam como "usados" no painel de uso (rótulo da tela). */
    public const RECURSOS_USO = [
        'vistorias' => 'Vistorias',
        'roteiro' => 'Roteiro do motorista',
        'patrimonio' => 'Patrimônio',
        'manutencoes' => 'Manutenção',
        'kits' => 'Kits',
        'contas' => 'Contas a pagar',
    ];

    /**
     * Cria a locadora (já validada), os dados padrão (empresa e categorias) e o administrador, numa transação.
     * $t: nome, slug, cnpj, email, telefone, plano (objeto com id, nome e limite_usuarios), vencimento (ou null),
     * carencia, admin_nome, admin_login, admin_senha, trocar_senha. Usado pelo painel e pelo cadastro público.
     * Devolve o id da locadora.
     */
    public static function provisionar(array $t): string
    {
        return DB::connection('plataforma')->transaction(function () use ($t) {
            $db = DB::connection('plataforma');
            $id = (string) $db->selectOne(
                'INSERT INTO tenants (slug, nome, cnpj, email_contato, telefone, plano, limite_usuarios, plano_id, vencimento, carencia_dias, teste_ate)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
                [$t['slug'], $t['nome'], $t['cnpj'], $t['email'], $t['telefone'], $t['plano']->nome, $t['plano']->limite_usuarios,
                    $t['plano']->id, $t['vencimento'], $t['carencia'], $t['teste_ate'] ?? null]
            )->id;
            // Contexto da nova locadora só até o fim desta transação (true = SET LOCAL)
            $db->select("SELECT set_config('app.tenant_id', ?, true)", [$id]);
            // Linha da empresa (com os textos padrão de contrato e fatura) e as 5 categorias padrão
            $db->select('SELECT provisionar_dados_padrao(?)', [$t['nome']]);
            $db->insert("INSERT INTO usuarios (nome, login, senha_hash, perfil, trocar_senha) VALUES (?, ?, ?, 'admin', ?)",
                [$t['admin_nome'], $t['admin_login'], password_hash($t['admin_senha'], PASSWORD_DEFAULT), $t['trocar_senha'] ? 'true' : 'false']);

            return $id;
        });
    }

    /** Senha temporária de 12 caracteres, sem os que se confundem (0/O, 1/l/I). */
    public static function senhaTemporaria(): string
    {
        $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $senha = '';
        for ($i = 0; $i < 12; $i++) {
            $senha .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
        }

        return $senha;
    }

    /** Roda $fn no contexto da locadora e limpa o contexto no fim, aconteça o que acontecer. */
    public static function noContexto(string $id, Closure $fn): mixed
    {
        $db = DB::connection('plataforma');
        $db->select("SELECT set_config('app.tenant_id', ?, false)", [$id]);
        try {
            return $fn($db);
        } finally {
            $db->select("SELECT set_config('app.tenant_id', '', false)");
        }
    }

    /** Plano mais barato que comporta mais usuários do que o limite atual (sugestão de upgrade). */
    public static function planoAcima(int $limite): ?string
    {
        return DB::connection('plataforma')
            ->selectOne('SELECT nome FROM planos WHERE ativo AND limite_usuarios > ? ORDER BY preco_mensal, nome LIMIT 1', [$limite])?->nome;
    }

    /**
     * Acrescenta a cada locadora (nas próprias linhas) os números de hoje: usuários, clientes, locações abertas
     * e o último acesso de qualquer usuário.
     */
    public static function numeros(array $locadoras): void
    {
        foreach ($locadoras as $t) {
            $n = self::noContexto($t->id, fn (ConnectionInterface $db) => $db->selectOne(
                "SELECT (SELECT count(*) FROM usuarios WHERE ativo)                       AS usuarios,
                        (SELECT count(*) FROM clientes)                                   AS clientes,
                        (SELECT count(*) FROM locacoes WHERE status = 'aberta')           AS abertas,
                        (SELECT max(ultimo_acesso) FROM usuarios)                         AS ultimo_acesso"
            ));
            $t->usuarios = (int) $n->usuarios;
            $t->clientes = (int) $n->clientes;
            $t->abertas = (int) $n->abertas;
            $t->ultimo_acesso = $n->ultimo_acesso;
        }
    }

    /**
     * Acrescenta a cada locadora o uso do sistema (sem dados pessoais): locações do mês, usuários que acessaram
     * nos últimos 30 dias, espaço das fotos, os recursos usados alguma vez e a sugestão de plano quando perto do limite.
     */
    public static function uso(array $locadoras): void
    {
        foreach ($locadoras as $t) {
            $n = self::noContexto($t->id, fn (ConnectionInterface $db) => $db->selectOne(
                "SELECT (SELECT count(*) FROM locacoes WHERE status <> 'cancelada'
                            AND data_saida >= date_trunc('month', CURRENT_DATE)::date)                                AS locacoes_mes,
                        (SELECT count(*) FROM usuarios WHERE ativo)                                                   AS usuarios,
                        (SELECT count(*) FROM usuarios WHERE ativo AND ultimo_acesso >= now() - INTERVAL '30 days')  AS usuarios_30d,
                        (SELECT COALESCE(sum(tamanho), 0) FROM vistoria_fotos)                                        AS fotos_bytes,
                        (SELECT count(*) FROM vistorias)                                                              AS vistorias,
                        (SELECT count(*) FROM entregas)                                                               AS roteiro,
                        (SELECT count(*) FROM unidades)                                                               AS patrimonio,
                        (SELECT count(*) FROM manutencoes)                                                            AS manutencoes,
                        (SELECT count(*) FROM kits)                                                                   AS kits,
                        (SELECT count(*) FROM contas_pagar)                                                           AS contas"
            ));
            $t->locacoes_mes = (int) $n->locacoes_mes;
            $t->usuarios = (int) $n->usuarios;
            $t->usuarios_30d = (int) $n->usuarios_30d;
            $t->fotos_bytes = (int) $n->fotos_bytes;
            $t->recursos = array_keys(array_filter(self::RECURSOS_USO, fn ($rotulo, $chave) => (int) $n->$chave > 0, ARRAY_FILTER_USE_BOTH));
            $t->perto_do_limite = $t->usuarios >= max(1, (int) ceil($t->limite_usuarios * 0.8));
            $t->sugestao = $t->perto_do_limite ? self::planoAcima((int) $t->limite_usuarios) : null;
        }
    }
}
