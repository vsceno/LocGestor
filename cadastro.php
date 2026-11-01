<?php
/*
 * LocGestor - endereço antigo do cadastro público. O cadastro agora é o do sistema novo (pasta laravel/):
 * este arquivo só leva para lá. O código antigo fica guardado na tag backup/cadastro-antigo do Git.
 */

declare(strict_types=1);

$config = is_file(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : [];
$destino = rtrim((string) ($config['laravel_url'] ?? ''), '/');

header('Location: ' . ($destino !== '' ? $destino : '/novo') . '/cadastro', true, 301);
exit;
