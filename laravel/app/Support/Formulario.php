<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Partes comuns dos formulários de locação e reserva (cliente da lista, itens, valores e datas), como no sistema antigo. */
class Formulario
{
    /** Texto do cliente na lista: "Nome · documento" (o id nunca aparece). Igual a cliente_rotulo(). */
    public static function rotuloCliente(object $c): string
    {
        return $c->cpf_cnpj !== '' ? $c->nome . ' · ' . doc_formatar($c->cpf_cnpj) : $c->nome;
    }

    /** Cliente pelo texto escolhido na lista ("Nome · documento"), só entre os ativos. Igual a cliente_resolver(). */
    public static function cliente(string $texto): object
    {
        $partes = explode(' · ', trim($texto), 2);
        $nome = trim($partes[0]);
        $doc = so_digitos($partes[1] ?? '');
        if ($nome === '') {
            throw new Recusa('Escolha o cliente.');
        }
        $achados = DB::select("SELECT id, nome, cpf_cnpj, bloqueado FROM clientes WHERE ativo AND nome = ? AND (? = '' OR cpf_cnpj = ?)", [$nome, $doc, $doc]);
        if (!$achados) {
            throw new Recusa('Cliente não encontrado. Escolha um cliente da lista ou cadastre-o antes.');
        }
        if (count($achados) > 1) {
            throw new Recusa('Há mais de um cliente com esse nome. Escolha na lista a opção com o documento.');
        }
        return $achados[0];
    }

    /**
     * Itens do formulário => [produto_id => [qtd, diaria, prev, periodo]]. Igual a itens_do_form(): linhas do mesmo produto
     * somam; valor vazio fica null (usa o do cadastro); o mesmo produto não pode ter períodos ou devoluções diferentes.
     */
    public static function itens(Request $request, string $onde): array
    {
        $qtds = (array) $request->input('quantidade', []);
        $valores = (array) $request->input('diaria', []);
        $prevs = (array) $request->input('prev', []);
        $periodos = (array) $request->input('periodo', []);
        $itens = [];
        foreach ((array) $request->input('produto_id', []) as $i => $pid) {
            $pid = (string) $pid;
            if ($pid === '') {
                continue;
            }
            $linha = (int) $i + 1;
            if (!Str::isUuid($pid)) {
                throw new Recusa("Produto inválido na linha $linha.");
            }
            $qtd = filter_var($qtds[$i] ?? '', FILTER_VALIDATE_INT);
            if ($qtd === false || $qtd < 1 || $qtd > 100000) {
                throw new Recusa("Quantidade inválida na linha $linha.");
            }
            $valorTxt = trim((string) ($valores[$i] ?? ''));
            $prevTxt = trim((string) ($prevs[$i] ?? ''));
            if ($prevTxt !== '' && !self::dataValida($prevTxt)) {
                throw new Recusa("Data de devolução inválida na linha $linha.");
            }
            $per = trim((string) ($periodos[$i] ?? ''));
            if ($per !== '' && !in_array($per, ['automatico', 'diaria', 'semanal', 'quinzenal', 'mensal'], true)) {
                throw new Recusa("Período inválido na linha $linha.");
            }
            $per = $per === '' ? 'automatico' : $per;
            $itens[$pid] ??= ['qtd' => 0, 'diaria' => null, 'prev' => null, 'periodo' => null];
            if ($itens[$pid]['periodo'] !== null && $itens[$pid]['periodo'] !== $per) {
                throw new Recusa('O mesmo produto aparece com períodos diferentes. Use uma linha por produto.');
            }
            $itens[$pid]['periodo'] = $per;
            $itens[$pid]['qtd'] += $qtd;
            if ($itens[$pid]['diaria'] === null && $valorTxt !== '') {
                $itens[$pid]['diaria'] = self::reais($valorTxt);
            }
            if ($prevTxt !== '') {
                if ($itens[$pid]['prev'] !== null && $itens[$pid]['prev'] !== $prevTxt) {
                    throw new Recusa('O mesmo produto aparece com datas de devolução diferentes. Use uma linha por produto.');
                }
                $itens[$pid]['prev'] = $prevTxt;
            }
        }
        if (!$itens) {
            throw new Recusa("Inclua pelo menos um item na $onde.");
        }
        return $itens;
    }

    /** Valor em reais como o parse_money() antigo: inválido recusa a operação com a mesma mensagem. */
    public static function reais($v): float
    {
        $n = valor_br($v ?? '');
        if ($n === null) {
            throw new Recusa('Valor inválido: ' . trim(str_replace(['R$', ' '], '', (string) $v)));
        }
        return $n;
    }

    public static function dataValida(string $d): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4));
    }
}
