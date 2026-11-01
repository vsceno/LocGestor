<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Aviso da mensalidade do LocGestor, mostrado ao administrador da locadora (mesmas regras do layout do sistema
 * antigo). Com uma cobrança do Asaas em aberto, aparece o botão "Pagar agora" com a fatura.
 */
final class Mensalidade
{
    /** null = nada a mostrar. 'link' é a fatura do Asaas ('' = sem cobrança em aberto). */
    public static function aviso(): ?array
    {
        if (session('usuario.perfil') !== 'admin') {
            return null;
        }
        $t = Locadora::dados();
        if ($t === null || empty($t->vencimento)) {
            return null;
        }

        // O RLS só deixa ler as cobranças da própria locadora
        $cobranca = DB::selectOne("SELECT link FROM assinatura_cobrancas WHERE status = 'pendente' AND link <> '' ORDER BY vencimento LIMIT 1");

        return self::montar($t, Assinaturas::diasAte($t->vencimento), (string) ($cobranca?->link ?? ''), Configuracoes::ler('pgsql')->suporte_contato);
    }

    /**
     * A mensagem para a situação da mensalidade (null quando não há o que avisar). $t traz vencimento, carencia_dias e
     * plano; $dias é até o vencimento (negativo = já passou); $contato é o telefone/e-mail da equipe, se houver.
     */
    public static function montar(object $t, int $dias, string $link, string $contato): ?array
    {
        $contato = $contato !== '' ? ' Contato: '.$contato.'.' : '';

        if ($dias < 0) {
            return [
                'tipo' => 'erro', 'link' => $link,
                'texto' => 'Mensalidade do LocGestor vencida desde '.data_br($t->vencimento)
                    .'. Regularize até '.data_br(Locadora::limitePagamento($t)).' para evitar a suspensão do acesso.'.$contato,
            ];
        }
        if ($dias <= 5 || $link !== '') {
            $quando = $dias === 0 ? 'vence hoje' : ($dias === 1 ? 'vence amanhã' : "vence em $dias dias");

            return [
                'tipo' => 'aviso', 'link' => $link,
                'texto' => "Sua mensalidade do LocGestor (plano {$t->plano}) $quando (".data_br($t->vencimento).').'.$contato,
            ];
        }

        return null;
    }
}
