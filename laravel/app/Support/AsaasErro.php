<?php

namespace App\Support;

use RuntimeException;

/** Falha de comunicação ou recusa do Asaas. A mensagem é segura para log e tela (nunca traz a chave da API). */
class AsaasErro extends RuntimeException
{
}
