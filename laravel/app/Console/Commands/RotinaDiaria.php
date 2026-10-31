<?php

namespace App\Console\Commands;

use App\Support\Cobrancas;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rotina diária das mensalidades: confere as cobranças no Asaas (funciona mesmo sem webhook), suspende as locadoras
 * além da carência e gera as cobranças dos próximos vencimentos. Sai com código 1 se algo falhou (o detalhe vai para o log).
 */
class RotinaDiaria extends Command
{
    protected $signature = 'locgestor:rotina-diaria';

    protected $description = 'Confere as cobranças do Asaas, suspende as locadoras além da carência e gera as próximas cobranças';

    public function handle(): int
    {
        // Quem altera as locadoras nesta rotina aparece na auditoria como "rotina diária"
        DB::connection('plataforma')->select("SELECT set_config('app.usuario', 'rotina diária', false)");

        $r = Cobrancas::rotina();
        $this->line('[' . now()->format('Y-m-d H:i:s') . '] Rotina diária do LocGestor');
        foreach ($r['linhas'] ?: ['Nada a fazer.'] as $linha) {
            $this->line("  $linha");
        }
        return $r['erros'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
