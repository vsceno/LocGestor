<?php
/*
 * LocGestor - endereço antigo do sistema das locadoras. As telas estão no sistema novo (pasta laravel/):
 * este arquivo só leva para lá, pelo laravel_url do config.php (sem ele, /novo).
 */

declare(strict_types=1);

$config = is_file(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : [];
$destino = rtrim((string) ($config['laravel_url'] ?? ''), '/');

header('Location: ' . ($destino !== '' ? $destino : '/novo') . '/');
exit;
