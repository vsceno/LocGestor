<?php

namespace App\Support;

/**
 * Regra de negócio recusou a operação. Lançada dentro de DB::transaction(), desfaz tudo o que a transação já
 * gravou (como o UserError do sistema antigo); a mensagem é para o usuário.
 */
class Recusa extends \RuntimeException
{
}
