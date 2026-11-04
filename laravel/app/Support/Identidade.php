<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * Identidade visual de cada locadora: cores, fontes e logotipo quadrado (o horizontal é o da tela Dados da locadora).
 * Campo vazio = padrão neutro. Só variáveis de CSS mudam: botões, campos, menus, telas e regras seguem iguais.
 */
class Identidade
{
    /** Padrão neutro, usado enquanto a locadora não escolhe as próprias cores. Não é a marca de nenhuma locadora. */
    public const PADRAO = [
        'cor_principal' => '#2563EB', 'cor_destaque' => '#10B981', 'cor_texto_escuro' => '#374151',
        'cor_texto_claro' => '#FFFFFF', 'cor_fundo' => '#F3F4F6', 'fonte_titulos' => 'inter', 'fonte_textos' => 'inter',
    ];

    public const CORES = [
        'cor_principal' => 'Cor principal',
        'cor_destaque' => 'Cor de destaque',
        'cor_texto_escuro' => 'Cor do texto escuro',
        'cor_texto_claro' => 'Cor do texto claro',
        'cor_fundo' => 'Cor de fundo',
    ];

    /** Fontes que já vêm com o sistema: a Inter sai junto com o CSS e nada é baixado de fora. */
    public const FONTES = [
        'inter' => "'Inter Variable', ui-sans-serif, system-ui, sans-serif",
        'sistema' => 'ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif',
    ];

    /** Contraste mínimo WCAG AA: texto sobre o fundo e texto dos botões (4,5:1); cor principal contra o fundo, como elemento de interface (3:1). */
    private const CONTRASTE_TEXTO = 4.5;

    private const CONTRASTE_INTERFACE = 3.0;

    /** Valores que valem na tela: o que a locadora gravou, ou o padrão onde o campo está vazio. */
    public static function resolver(?object $empresa): array
    {
        $valores = [];
        foreach (self::PADRAO as $campo => $padrao) {
            $gravado = (string) ($empresa->$campo ?? '');
            $valores[$campo] = $gravado !== '' ? $gravado : $padrao;
        }

        return $valores;
    }

    /**
     * Cores e fontes do formulário, prontas para gravar: [dados, erros]. Erros vazios = pode gravar.
     * O contraste é conferido com os valores que ficariam na tela (o padrão onde o campo estiver vazio).
     */
    public static function validar(array $dados): array
    {
        $gravar = [];
        $erros = [];
        foreach (self::CORES as $campo => $rotulo) {
            $valor = trim((string) ($dados[$campo] ?? ''));
            if ($valor !== '' && ! preg_match('/^#[0-9A-Fa-f]{6}$/', $valor)) {
                $erros[] = "$rotulo inválida.";

                continue;
            }
            $gravar[$campo] = strtoupper($valor);
        }
        foreach (['fonte_titulos', 'fonte_textos'] as $campo) {
            $valor = (string) ($dados[$campo] ?? '');
            if (! array_key_exists($valor, self::FONTES)) {
                $erros[] = 'Escolha uma das fontes da lista.';

                continue;
            }
            $gravar[$campo] = $valor;
        }
        if ($erros) {
            return [[], $erros];
        }

        $efetiva = self::resolver((object) $gravar);
        if (self::contraste($efetiva['cor_texto_escuro'], $efetiva['cor_fundo']) < self::CONTRASTE_TEXTO) {
            $erros[] = 'O texto escuro fica difícil de ler sobre a cor de fundo (contraste mínimo 4,5:1).';
        }
        if (self::contraste($efetiva['cor_texto_claro'], $efetiva['cor_principal']) < self::CONTRASTE_TEXTO) {
            $erros[] = 'O texto claro fica difícil de ler nos botões da cor principal (contraste mínimo 4,5:1).';
        }
        if (self::contraste($efetiva['cor_principal'], $efetiva['cor_fundo']) < self::CONTRASTE_INTERFACE) {
            $erros[] = 'A cor principal aparece pouco sobre o fundo, nos campos e no menu (contraste mínimo 3:1).';
        }

        return $erros ? [[], $erros] : [$gravar, []];
    }

    /**
     * Texto branco ou escuro, o que lê melhor sobre a cor. Usado no botão de destaque, em que a locadora só escolhe a cor
     * de fundo (o texto acompanha, para o contraste não depender de quem escolheu).
     */
    public static function textoSobre(string $hex): string
    {
        return self::contraste($hex, '#FFFFFF') >= self::contraste($hex, '#111827') ? '#FFFFFF' : '#111827';
    }

    /** Razão de contraste entre duas cores, de 1 (nenhuma) a 21 (preto sobre branco). */
    public static function contraste(string $a, string $b): float
    {
        $la = self::luminancia($a);
        $lb = self::luminancia($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /**
     * Tons da cor principal (classes marca-50 a marca-900 do tema). O 600 é a própria cor escolhida, então botões e
     * destaques saem exatamente nela. Os claros misturam com branco e os escuros, com preto.
     *
     * @return array<int, string>
     */
    public static function escala(string $hex): array
    {
        $base = self::rgb($hex);
        $tons = [];
        foreach ([50 => 0.92, 100 => 0.82, 200 => 0.66, 300 => 0.46, 400 => 0.26, 500 => 0.12] as $tom => $peso) {
            $tons[$tom] = self::misturar($base, 255, $peso);
        }
        $tons[600] = strtoupper($hex);
        foreach ([700 => 0.12, 800 => 0.28, 900 => 0.42] as $tom => $peso) {
            $tons[$tom] = self::misturar($base, 0, $peso);
        }

        return $tons;
    }

    /**
     * Bloco <style> com as variáveis da identidade. Na impressão, só as variáveis que a folha usa (--laranja e --texto).
     * Na tela, também fixa o fundo, o texto e as fontes: regras sem camada, que vencem as do tema.
     */
    public static function html(?object $empresa, bool $impressao = false): HtmlString
    {
        $v = self::resolver($empresa);

        // A folha de impressão só usa a cor principal (--laranja) e o texto (--texto)
        $vars = ["--laranja: {$v['cor_principal']}", "--texto: {$v['cor_texto_escuro']}"];
        $regras = '';
        if (! $impressao) {
            foreach (self::escala($v['cor_principal']) as $tom => $hex) {
                $vars[] = "--color-marca-$tom: $hex";
            }
            $vars[] = "--identidade-destaque: {$v['cor_destaque']}";
            $vars[] = '--identidade-texto-destaque: '.self::textoSobre($v['cor_destaque']);
            $vars[] = "--identidade-texto: {$v['cor_texto_escuro']}";
            $vars[] = "--identidade-texto-claro: {$v['cor_texto_claro']}";
            $vars[] = "--identidade-fundo: {$v['cor_fundo']}";
            $vars[] = '--identidade-fonte-titulos: '.self::FONTES[$v['fonte_titulos']];
            $vars[] = '--font-sans: '.self::FONTES[$v['fonte_textos']];
            $regras = 'html:not(.dark) body{background-color:var(--identidade-fundo);color:var(--identidade-texto)}'
                .'h1,h2,h3,.cartao-titulo{font-family:var(--identidade-fonte-titulos)}'
                .'.btn-primario{color:var(--identidade-texto-claro)}';
        }

        return new HtmlString('<style>:root{'.implode(';', $vars)."}$regras</style>");
    }

    /**
     * Logotipo quadrado (menu recolhido e ícone): regravado em PNG pela mesma regra da logomarca dos documentos.
     * Com $remover, apaga o atual. O arquivo antigo só sai do disco depois de o banco confirmar a troca.
     */
    public static function salvarLogoQuadrada(?UploadedFile $arquivo, bool $remover): void
    {
        if ($arquivo === null && ! $remover) {
            return;
        }
        $novo = '';
        if ($arquivo !== null) {
            if (! $arquivo->isValid() || $arquivo->getSize() > Arquivos::LOGO_BYTES_MAX) {
                throw new Recusa('A logotipo quadrado é grande demais (máximo de 5 MB) ou não foi recebida. Tente de novo.');
            }
            $img = Arquivos::regravar((string) file_get_contents($arquivo->getRealPath()), Arquivos::LOGO_LADO_MAX, true);
            $novo = Arquivos::gravar($img['bytes'], 'png');
        }

        $antigo = (string) DB::selectOne('SELECT logo_quadrada_arquivo FROM empresa')->logo_quadrada_arquivo;
        try {
            DB::update('UPDATE empresa SET logo_quadrada_arquivo = ?', [$novo]);
        } catch (Throwable $e) {
            if ($novo !== '') {
                Arquivos::apagar($novo);
            }
            throw $e;
        }
        if ($antigo !== '' && $antigo !== $novo) {
            Arquivos::apagar($antigo);
        }
    }

    /** Luminância relativa da cor (WCAG 2.x), de 0 (preto) a 1 (branco). */
    private static function luminancia(string $hex): float
    {
        $canais = array_map(function (int $c): float {
            $c /= 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, self::rgb($hex));

        return 0.2126 * $canais[0] + 0.7152 * $canais[1] + 0.0722 * $canais[2];
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function rgb(string $hex): array
    {
        return [(int) hexdec(substr($hex, 1, 2)), (int) hexdec(substr($hex, 3, 2)), (int) hexdec(substr($hex, 5, 2))];
    }

    /** Mistura a cor com $alvo (255 = branco, 0 = preto) na proporção $peso. */
    private static function misturar(array $rgb, int $alvo, float $peso): string
    {
        $hex = array_map(fn (int $c): string => str_pad(dechex((int) round($c + ($alvo - $c) * $peso)), 2, '0', STR_PAD_LEFT), $rgb);

        return '#'.strtoupper(implode('', $hex));
    }
}
