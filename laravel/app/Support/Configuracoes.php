<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Configurações da LocGestor (tabela plataforma_configuracoes): dados do emitente, identidade, documentos, cadastro
 * público e suporte. A plataforma lê e altera pela conexão 'plataforma'; a área das locadoras só lê ('pgsql'), e
 * nunca grava aqui. Segredos (chave do Asaas, segredo de proxies) não ficam nesta tabela: ficam no ambiente do servidor.
 */
final class Configuracoes
{
    public const ID = '00000000-0000-0000-0000-000000000001';

    public static function ler(string $conexao = 'plataforma'): object
    {
        return DB::connection($conexao)->selectOne('SELECT * FROM plataforma_configuracoes WHERE id = ?', [self::ID]);
    }

    /** Dados que aparecem no recibo. Razão social e CNPJ são obrigatórios para emitir. */
    public static function emitente(string $conexao = 'plataforma'): array
    {
        $c = self::ler($conexao);

        return [
            'razao_social' => $c->emitente_razao,
            'cnpj' => $c->emitente_cnpj,
            'endereco' => $c->emitente_endereco,
            'contato' => $c->emitente_contato,
        ];
    }

    public static function emitenteCompleto(string $conexao = 'plataforma'): bool
    {
        $e = self::emitente($conexao);

        return $e['razao_social'] !== '' && $e['cnpj'] !== '';
    }

    /** O cadastro público só abre com a chave ligada e com os termos de uso publicados. */
    public static function cadastroAberto(string $conexao = 'plataforma'): bool
    {
        $c = self::ler($conexao);

        return (bool) $c->cadastro_publico && $c->termos_url !== '';
    }

    public static function nomeSistema(string $conexao = 'plataforma'): string
    {
        return self::ler($conexao)->nome_sistema;
    }

    /** Arquivo da logomarca enviada pela plataforma (fora do site: fica na pasta de arquivos). */
    public static function logoCaminho(): string
    {
        return Arquivos::base().'/plataforma/logo.png';
    }

    public static function logoEnviada(): bool
    {
        return is_file(self::logoCaminho());
    }

    /** Endereço da logomarca. A versão muda a cada envio, para o navegador não usar a imagem antiga. */
    public static function logoUrl(string $conexao = 'plataforma'): string
    {
        return route('marca.logo', ['v' => self::ler($conexao)->logo_versao]);
    }

    /**
     * Grava a logomarca como PNG de até 512 px, redesenhada pelo PHP: isso tira metadados (GPS, miniaturas) e impede
     * que o arquivo carregue outra coisa (um SVG, por exemplo, não passa por getimagesize). Recusa o que não for imagem.
     *
     * @throws \InvalidArgumentException quando o arquivo não é uma imagem aceita ou é grande demais
     */
    public static function gravarLogo(string $origem): void
    {
        $info = @getimagesize($origem);
        if ($info === false || $info[0] > 4000 || $info[1] > 4000) {
            throw new \InvalidArgumentException('Envie uma imagem PNG, JPG ou WEBP de até 4000 px.');
        }
        $imagem = @imagecreatefromstring((string) file_get_contents($origem));
        if ($imagem === false) {
            throw new \InvalidArgumentException('Não foi possível ler essa imagem. Envie outro arquivo.');
        }

        $escala = min(1, 512 / max($info[0], $info[1]));
        $largura = max(1, (int) round($info[0] * $escala));
        $altura = max(1, (int) round($info[1] * $escala));
        $nova = imagecreatetruecolor($largura, $altura);
        imagealphablending($nova, false);
        imagesavealpha($nova, true);
        imagefill($nova, 0, 0, imagecolorallocatealpha($nova, 0, 0, 0, 127));
        imagecopyresampled($nova, $imagem, 0, 0, 0, 0, $largura, $altura, $info[0], $info[1]);

        $destino = self::logoCaminho();
        if (! is_dir(dirname($destino))) {
            mkdir(dirname($destino), 0750, true);
        }
        // Grava num arquivo temporário e troca de uma vez: quem lê a logomarca nunca pega um arquivo pela metade
        imagepng($nova, $destino.'.tmp', 9);
        rename($destino.'.tmp', $destino);
    }

    public static function removerLogo(): void
    {
        if (is_file(self::logoCaminho())) {
            unlink(self::logoCaminho());
        }
    }
}
