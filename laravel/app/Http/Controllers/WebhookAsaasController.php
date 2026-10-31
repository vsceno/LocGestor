<?php

namespace App\Http\Controllers;

use App\Support\Asaas;
use App\Support\Cobrancas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Avisos do Asaas (webhook das mensalidades). O aviso só diz QUAL cobrança mudou: a situação é sempre conferida na API
 * antes da baixa, então um aviso forjado não registra pagamento. Avisos repetidos são ignorados. Mesmas regras de
 * webhook_asaas.php do sistema antigo. O endereço antigo (webhook_asaas.php) é encaminhado para esta rota pelo servidor.
 * Não exige login nem o token de formulário: a autorização é o token do cabeçalho asaas-access-token.
 */
class WebhookAsaasController extends Controller
{
    public function receber(Request $request): JsonResponse
    {
        $token = Asaas::config()['webhook_token'];
        $recebido = (string) $request->header('asaas-access-token', '');
        if ($token === '' || !hash_equals($token, $recebido)) {
            Log::alert('Aviso do Asaas recusado: token ausente ou inválido.');
            return $this->responder(401, 'Token inválido.');
        }

        $aviso = json_decode(substr((string) $request->getContent(), 0, 100000), true);
        if (!is_array($aviso) || !is_string($aviso['event'] ?? null)) {
            return $this->responder(400, 'Aviso inválido.');
        }
        $eventoId = is_string($aviso['id'] ?? null) && strlen($aviso['id']) <= 80 ? $aviso['id'] : '';
        $pagamentoId = $aviso['payment']['id'] ?? '';
        if (!str_starts_with($aviso['event'], 'PAYMENT_') || !is_string($pagamentoId) || !Asaas::idValido($pagamentoId)) {
            return $this->responder(200, 'Ignorado.');
        }

        // Quem altera as locadoras por aviso do Asaas aparece na auditoria como "webhook asaas"
        $db = DB::connection('plataforma');
        $db->select("SELECT set_config('app.usuario', 'webhook asaas', false)");
        try {
            if ($eventoId !== '' && $db->selectOne('SELECT 1 FROM gateway_eventos WHERE id = ?', [$eventoId]) !== null) {
                return $this->responder(200, 'Aviso já processado.');
            }
            $c = $db->selectOne('SELECT id FROM assinatura_cobrancas WHERE gateway_id = ?', [$pagamentoId]);
            // Cobrança que não é mensalidade do LocGestor (a conta do Asaas pode ter outras)
            $situacao = $c === null ? 'desconhecida' : Cobrancas::sincronizar($c->id);
            if ($eventoId !== '') {
                $db->insert('INSERT INTO gateway_eventos (id, tipo) VALUES (?, ?) ON CONFLICT (id) DO NOTHING',
                            [$eventoId, mb_substr($aviso['event'], 0, 60)]);
            }
            return $this->responder(200, "Cobrança $situacao.");
        } catch (Throwable $e) {
            // 500: o Asaas tenta de novo mais tarde (e a rotina diária também confere)
            Log::error('Aviso do Asaas ' . $aviso['event'] . ': ' . $e->getMessage());
            return $this->responder(500, 'Erro ao processar o aviso.');
        }
    }

    private function responder(int $codigo, string $msg): JsonResponse
    {
        return response()->json(['ok' => $codigo === 200, 'msg' => $msg], $codigo);
    }
}
