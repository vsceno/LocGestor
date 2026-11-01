<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Recados do painel da plataforma: avisos para todas as locadoras, no topo do sistema, até a data de validade.
 * Mesma regra de recados_html() do sistema antigo: no máximo 3, os mais recentes primeiro, só os ativos e no prazo.
 * A tabela não tem RLS (é a mesma para todas as locadoras), então a leitura usa a conexão da própria locadora.
 */
final class Recados
{
    /** @return array<int, object> */
    public static function ativos(): array
    {
        return DB::select(
            'SELECT id, tipo, titulo, texto, expira_em FROM recados
              WHERE ativo AND expira_em >= CURRENT_DATE
              ORDER BY criado_em DESC LIMIT 3'
        );
    }
}
