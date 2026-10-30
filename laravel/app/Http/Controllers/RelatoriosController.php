<?php

namespace App\Http\Controllers;

use App\Support\Formulario;
use App\Support\Relatorios;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Illuminate\View\View;

/**
 * Relatórios (com período ou da situação atual), impressão, CSV e a exportação do mês para o contador
 * (só administrador: traz CPF/CNPJ dos clientes). Mesmas regras de modulos/relatorios.php.
 */
class RelatoriosController extends Controller
{
    public function index(): View
    {
        return view('relatorios.index', [
            'relatorios' => Relatorios::definicoes(),
            'admin' => session('usuario.perfil') === 'admin',
        ]);
    }

    public function ver(Request $request, string $chave): View|Response
    {
        $defs = Relatorios::definicoes();
        abort_unless(isset($defs[$chave]), 404, 'Relatório não encontrado.');
        $rel = $defs[$chave];
        [$de, $ate] = $this->periodo($request);
        $linhas = Relatorios::executar($rel, $de, $ate);
        $totais = Relatorios::totais($rel, $linhas);
        $subtitulo = $rel['periodo'] ? 'Período: ' . data_br($de) . ' a ' . data_br($ate) : 'Situação em ' . data_br(now()->toDateString(), true);
        $params = ['chave' => $chave] + ($rel['periodo'] ? ['de' => $de, 'ate' => $ate] : []);

        if ($request->query('csv') === '1') {
            return response(Relatorios::csvRelatorio($rel, $linhas, $totais), 200, [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="relatorio-' . $chave . '-' . now()->toDateString() . '.csv"',
                'Cache-Control' => 'no-store',
            ]);
        }
        if ($request->query('imprimir') === '1') {
            return view('relatorios.imprimir', [
                'rel' => $rel, 'linhas' => $linhas, 'totais' => $totais, 'subtitulo' => $subtitulo,
                'voltar' => route('relatorios.ver', $params),
            ]);
        }
        return view('relatorios.ver', [
            'chave' => $chave, 'rel' => $rel, 'linhas' => $linhas, 'totais' => $totais,
            'de' => $de, 'ate' => $ate, 'subtitulo' => $subtitulo, 'params' => $params,
            'cfg' => $this->grafico($rel, $linhas),
        ]);
    }

    public function contador(Request $request): View
    {
        $this->soAdmin();
        $mes = $this->mes($request);
        $meses = [];
        for ($i = 0; $i < 24; $i++) {
            $m = now()->startOfMonth()->subMonths($i)->format('Y-m');
            $meses[$m] = Relatorios::MESES[(int) substr($m, 5)] . ' de ' . substr($m, 0, 4);
        }
        return view('relatorios.contador', [
            'mes' => $mes,
            'meses' => $meses,
            'arquivos' => Relatorios::contadorArquivos($mes),
            'zip' => class_exists(\ZipArchive::class),
        ]);
    }

    public function contadorBaixar(Request $request): Response|BinaryFileResponse
    {
        $this->soAdmin();
        $mes = $this->mes($request);
        $arquivo = (string) $request->query('arquivo', '');
        abort_if($arquivo === 'zip' && !class_exists(\ZipArchive::class), 404, 'Arquivo não encontrado.');
        $arquivos = Relatorios::contadorArquivos($mes);
        abort_unless($arquivo === 'zip' || isset($arquivos[$arquivo]), 404, 'Arquivo não encontrado.');
        Log::info("Exportação para o contador: $mes ($arquivo).", ['usuario' => session('usuario.login')]);

        if ($arquivo === 'zip') {
            return $this->zip($mes, $arquivos);
        }
        [, $colunas, $linhas] = $arquivos[$arquivo];
        return response(Relatorios::csvTexto($colunas, $linhas), 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $arquivo . '"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** Período da URL, padrão os últimos 12 meses; se vier invertido, as datas trocam de lugar. */
    private function periodo(Request $request): array
    {
        $padrao = now()->startOfMonth()->subMonths(11)->toDateString();
        $de = Formulario::dataValida((string) $request->query('de', $padrao)) ? (string) $request->query('de') : $padrao;
        $ate = Formulario::dataValida((string) $request->query('ate', now()->toDateString())) ? (string) $request->query('ate') : now()->toDateString();
        return $ate < $de ? [$ate, $de] : [$de, $ate];
    }

    /** Mês 'AAAA-MM' da URL; padrão o mês passado. */
    private function mes(Request $request): string
    {
        $mes = (string) $request->query('mes', '');
        return preg_match('/^(19|20)\d\d-(0[1-9]|1[0-2])$/', $mes) ? $mes : now()->startOfMonth()->subMonth()->format('Y-m');
    }

    /** Gráfico de barras do relatório (até 12 linhas), ou null se não há gráfico nem linhas. */
    private function grafico(array $rel, array $linhas): ?array
    {
        if (empty($rel['grafico']) || !$linhas) {
            return null;
        }
        $g = $rel['grafico'];
        $base = array_slice($linhas, 0, 12);
        $cfg = ['tipo' => 'bar', 'moeda' => $g['moeda'] ?? false, 'horizontal' => $g['horizontal'] ?? false,
                'rotulos' => array_map(fn ($l) => mb_strimwidth((string) $l[$g['rotulo']], 0, 32, '…'), $base), 'series' => []];
        foreach ($g['series'] as $campo => [$nome, $cor]) {
            $cfg['series'][] = ['nome' => $nome, 'cor' => $cor, 'dados' => array_map(fn ($l) => (float) $l[$campo], $base)];
        }
        return $cfg;
    }

    /** Todos os arquivos do mês num ZIP, com o LEIAME.txt (como o sistema antigo). */
    private function zip(string $mes, array $arquivos): BinaryFileResponse
    {
        $tmp = tempnam(sys_get_temp_dir(), 'lgz');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $empresa = DB::selectOne('SELECT razao_social FROM empresa');
        $leia = "LocGestor - exportação para o contador\r\nEmpresa: " . ($empresa->razao_social ?? '') . "\r\nMês: $mes\r\n"
              . "Regime de caixa: entradas e saídas registradas no fluxo de caixa do sistema.\r\n\r\n";
        foreach ($arquivos as $nome => [$titulo, $colunas, $linhas]) {
            $zip->addFromString($nome, Relatorios::csvTexto($colunas, $linhas));
            $leia .= "$nome: $titulo (" . count($linhas) . " linhas)\r\n";
        }
        $zip->addFromString('LEIAME.txt', $leia);
        $zip->close();
        return response()->download($tmp, 'contador_' . $mes . '.zip', [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'no-store',
        ])->deleteFileAfterSend(true);
    }

    private function soAdmin(): void
    {
        abort_unless(session('usuario.perfil') === 'admin', 403, 'Acesso restrito ao administrador.');
    }
}
